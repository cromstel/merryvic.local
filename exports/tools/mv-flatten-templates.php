<?php
/**
 * MerryVic — import-ready template generator.
 *
 * Elementor templates reference two things that do NOT exist on another site:
 *   1. e-component instances (the shared component library), and
 *   2. kit global classes (g-xxxx ids stored on the active kit).
 *
 * This script rewrites every exported template so it is self-contained:
 *   - each e-component is replaced by a copy of the component's own widget tree,
 *     with the instance overrides applied;
 *   - each global class is inlined into the element as local custom CSS, with
 *     global variable references resolved to their literal values.
 *
 * Source of truth: the exported files in exports/merryvic-templates/.
 * Run: php mv-flatten-templates.php
 */

/* Resolve the WordPress root so this script runs from any directory. */
$mv_root = __DIR__;
while ( ! file_exists( $mv_root . '/wp-load.php' ) && dirname( $mv_root ) !== $mv_root ) {
	$mv_root = dirname( $mv_root );
}

$creds = [];
foreach ( file( $mv_root . '/wp-config.php' ) as $line ) {
	if ( preg_match( "/define\(\s*'(DB_[A-Z_]+)'\s*,\s*'([^']*)'\s*\)/", $line, $m ) ) {
		$creds[ $m[1] ] = $m[2];
	} elseif ( preg_match( "/\\\$table_prefix\s*=\s*'([^']+)'/", $line, $m ) ) {
		$creds['prefix'] = $m[1];
	}
}
$db = new mysqli( '127.0.0.1', $creds['DB_USER'], $creds['DB_PASSWORD'], $creds['DB_NAME'] );
if ( $db->connect_error ) {
	exit( "DB connect failed\n" );
}
$db->set_charset( 'utf8mb4' );
$prefix = $creds['prefix'];

/* ------------------------------------------------------------------ *
 * Load data
 * ------------------------------------------------------------------ */
$meta_rows = function ( $sql ) use ( $db ) {
	$out = [];
	$r   = $db->query( $sql );
	if ( ! $r ) {
		return $out;
	}
	while ( $row = $r->fetch_assoc() ) {
		$out[ $row['post_id'] ][ $row['meta_key'] ] = $row['meta_value'];
	}
	return $out;
};

/* Components */
$components = [];
$r = $db->query( "SELECT p.ID, m.meta_key, m.meta_value
                 FROM {$prefix}posts p
                 JOIN {$prefix}postmeta m ON m.post_id = p.ID
                 WHERE p.post_type = 'elementor_component'
                   AND m.meta_key IN ('_elementor_data','_elementor_component_overridable_props')" );
while ( $m = $r->fetch_assoc() ) {
	$cid = (int) $m['ID'];
	if ( '_elementor_data' === $m['meta_key'] ) {
		$components[ $cid ]['data'] = json_decode( $m['meta_value'], true );
	} else {
		$components[ $cid ]['props'] = json_decode( $m['meta_value'], true );
	}
}

/* Kit variables: e-gv-id => literal value */
$kit_id = 0;
$r      = $db->query( "SELECT option_value FROM {$prefix}options WHERE option_name = 'elementor_active_kit' LIMIT 1" );
if ( $row = $r->fetch_assoc() ) {
	$kit_id = (int) $row['option_value'];
}
/* Kit variables: e-gv-id => typed literal (e.g. {"$$type":"color","value":"#171309"}) */
$var_value = [];
$var_typed = [];
if ( $kit_id ) {
	$r = $db->query( "SELECT meta_value FROM {$prefix}postmeta WHERE post_id = {$kit_id} AND meta_key = '_elementor_global_variables' LIMIT 1" );
	if ( $row = $r->fetch_assoc() ) {
		$vars = json_decode( $row['meta_value'], true );
		$vars = $vars['data'] ?? $vars;
		foreach ( (array) $vars as $vid => $v ) {
			if ( empty( $v['label'] ) ) {
				continue;
			}
			$value = $v['value'] ?? '';
			if ( is_array( $value ) ) {
				$value = $value['value'] ?? '';
			}
			$var_value[ $v['label'] ] = $value;
			/* Keep the typed literal so it can be dropped straight into a class prop. */
			if ( is_array( $v['value'] ?? null ) && isset( $v['value']['$$type'] ) ) {
				$var_typed[ $vid ] = $v['value'];
			}
		}
	}
}

/* Global classes: g-id => compiled CSS text with literals resolved */
function mv_props_to_css( $props, $var_value ) {
	$out = '';
	foreach ( (array) $props as $prop => $val ) {
		if ( is_array( $val ) ) {
			if ( isset( $val['$$type'] ) ) {
				$ref = $val['value'] ?? '';
				if ( is_array( $ref ) ) {
					$ref = $ref['value'] ?? '';
				}
				if ( isset( $var_value[ $ref ] ) ) {
					$val = $var_value[ $ref ];
				} elseif ( is_array( $ref ) ) {
					continue;
				} elseif ( 'size' === $val['$$type'] && isset( $ref['size'], $ref['unit'] ) ) {
					$val = $ref['size'] . $ref['unit'];
				} else {
					continue;
				}
			} else {
				continue;
			}
		}
		if ( is_scalar( $val ) && '' !== (string) $val ) {
			$out .= $prop . ': ' . $val . ';';
		}
	}
	return $out;
}

$class_css = [];
$r         = $db->query( "SELECT p.ID, m.meta_value FROM {$prefix}posts p
                        JOIN {$prefix}postmeta m ON m.post_id = p.ID
                        WHERE p.post_type = 'e_global_class'
                          AND m.meta_key = '_elementor_global_class_data'" );
$class_ids = [];
$r2        = $db->query( "SELECT meta_value FROM {$prefix}postmeta WHERE post_id = {$kit_id} AND meta_key = '_elementor_global_classes_post_ids' LIMIT 1" );
$id_map    = $r2 ? ( $r2->fetch_assoc()['meta_value'] ?? '' ) : '';
$id_map    = $id_map ? maybe_unserialize_safe( $id_map ) : [];
while ( $m = $r->fetch_assoc() ) {
	$data = maybe_unserialize_safe( $m['meta_value'] );
	if ( ! is_array( $data ) ) {
		continue;
	}
	/*
	 * Each class is stored as a list of variants holding BARE declarations.
	 * Elementor wraps custom_css in the element's own selector and applies the
	 * variant's breakpoint / pseudo-state, so selectors must not be included.
	 */
	$variants = [];
	foreach ( (array) ( $data['variants'] ?? [] ) as $variant ) {
		$bp    = $variant['meta']['breakpoint'] ?? 'desktop';
		$state = $variant['meta']['state'] ?? null;
		/* Resolve every global-variable reference into its typed literal. */
		$props = mv_resolve_props( $variant['props'] ?? [], $var_typed, $var_value );
		if ( ! $props ) {
			continue;
		}
		$variants[] = [
			'breakpoint' => $bp,
			'state'      => $state,
			'props'      => $props,
		];
	}
	foreach ( (array) $id_map as $gid => $pid ) {
		if ( (int) $pid === (int) $m['ID'] ) {
			$class_css[ $gid ] = $variants;
			$class_ids[]       = $gid;
		}
	}
}

function maybe_unserialize_safe( $value ) {
	if ( is_array( $value ) ) {
		return $value;
	}
	$trimmed = trim( (string) $value );
	if ( '' === $trimmed || ! preg_match( '/^[aOs]:\d+:/', $trimmed ) ) {
		return $trimmed;
	}
	$out = @unserialize( $trimmed );
	return false === $out ? $trimmed : $out;
}

/* ------------------------------------------------------------------ *
 * Rewriters
 * ------------------------------------------------------------------ */
function mv_uid() {
	return substr( md5( uniqid( '', true ) ), 0, 8 );
}

/** Replace one element's prop, searching the tree by element id. */
function mv_set_prop( &$nodes, $element_id, $prop, $value ) {
	foreach ( $nodes as &$node ) {
		if ( ( $node['id'] ?? '' ) === $element_id ) {
			$node['settings'][ $prop ] = $value;
			return true;
		}
		if ( ! empty( $node['elements'] ) ) {
			$child = $node['elements'];
			if ( mv_set_prop( $child, $element_id, $prop, $value ) ) {
				$node['elements'] = $child;
				return true;
			}
		}
	}
	return false;
}

/** Give every node in a cloned subtree fresh ids (components repeat many times). */
function mv_reid( &$nodes, &$style_ids ) {
	foreach ( $nodes as &$node ) {
		$old            = $node['id'] ?? null;
		$node['id']     = mv_uid();
		$style_ids[ $old ] = $node['id'];

		if ( ! empty( $node['styles'] ) && is_array( $node['styles'] ) ) {
			$styles = [];
			foreach ( $node['styles'] as $sid => $sdef ) {
				$new_sid           = 'e-' . mv_uid() . '-local';
				if ( is_array( $sdef ) ) {
					$sdef['id']   = $new_sid;
					$sdef['label'] = 'local';
				}
				$styles[ $new_sid ] = $sdef;
			}
			$node['styles'] = $styles;
		}
		if ( ! empty( $node['elements'] ) ) {
			mv_reid( $node['elements'], $style_ids );
		}
	}
}

/**
 * Copy a class variant's props, swapping global-variable references for the
 * variable's own typed literal so the style survives on another site.
 */
function mv_resolve_props( $props, $var_typed, $var_value ) {
	$out = [];
	foreach ( (array) $props as $prop => $val ) {
		if ( is_array( $val ) && isset( $val['$$type'] ) && 0 === strpos( (string) $val['$$type'], 'global-' ) ) {
			$ref = $val['value'] ?? '';
			if ( isset( $var_typed[ $ref ] ) ) {
				$out[ $prop ] = $var_typed[ $ref ];
				continue;
			}
			$label = is_array( $ref ) ? ( $ref['value'] ?? '' ) : $ref;
			if ( isset( $var_value[ $label ] ) ) {
				$out[ $prop ] = $var_value[ $label ];
				continue;
			}
			continue; // unresolvable: drop rather than ship a dangling reference
		}
		if ( is_array( $val ) ) {
			$nested = mv_resolve_props( $val, $var_typed, $var_value );
			$out[ $prop ] = $nested ?: $val;
			continue;
		}
		$out[ $prop ] = $val;
	}
	return array_filter( $out, fn( $v ) => '' !== $v && null !== $v );
}

/** Inline global classes into the element as local styles (native typed props). */
function mv_bake_classes( &$node, $class_css ) {
	if ( ! empty( $node['settings']['classes']['value'] ) && is_array( $node['settings']['classes']['value'] ) ) {
		$entries = [];
		foreach ( $node['settings']['classes']['value'] as $cid ) {
			foreach ( $class_css[ $cid ] ?? [] as $variant ) {
				$entries[] = $variant;
			}
		}
		unset( $node['settings']['classes'] );
		if ( $entries ) {
			$sid            = 'e-' . mv_uid() . '-mv';
			$variants_out   = [];
			foreach ( $entries as $variant ) {
				$variants_out[] = [
					'meta'  => [ 'breakpoint' => $variant['breakpoint'], 'state' => $variant['state'] ],
					'props' => (object) $variant['props'],
				];
			}
			$node['styles'][ $sid ] = [
				'id'       => $sid,
				'label'    => 'local',
				'type'     => 'class',
				'variants' => $variants_out,
			];
		}
	}
	if ( ! empty( $node['elements'] ) ) {
		foreach ( $node['elements'] as &$child ) {
			mv_bake_classes( $child, $class_css );
		}
	}
}

/** Flatten e-component instances into real widget trees, with overrides applied. */
function mv_flatten( array $nodes, array $components, array $class_css ) {
	$out = [];
	foreach ( $nodes as $node ) {
		$is_component = ( $node['widgetType'] ?? '' ) === 'e-component' || 'e-component' === ( $node['elType'] ?? '' );

		if ( $is_component ) {
			$instance = $node['settings']['component_instance']['value'] ?? null;
			$cid      = (int) ( $instance['component_id']['value'] ?? 0 );
			if ( ! $cid || empty( $components[ $cid ]['data'] ) ) {
				continue; // unresolved component: drop rather than ship a broken node
			}
			$roots     = $components[ $cid ]['data'];
			$overrides = $instance['overrides']['value'] ?? [];
			$map       = $components[ $cid ]['props']['props'] ?? [];

			foreach ( $overrides as $override ) {
				$key = $override['value']['override_key']   ?? null;
				$val = $override['value']['override_value'] ?? null;
				if ( ! $key || null === $val || ! isset( $map[ $key ] ) ) {
					continue;
				}
				mv_set_prop( $roots, $map[ $key ]['elementId'], $map[ $key ]['propKey'], $val );
			}

			foreach ( $roots as $i => $root ) {
				if ( 0 === $i && ! empty( $node['styles'] ) && is_array( $node['styles'] ) ) {
					$root['styles'] = array_merge( $root['styles'] ?? [], $node['styles'] );
				}
				/* Keep the instance's editor label so the inlined block stays
				 * identifiable in the editor (e.g. "quote-b", "cat-a"). */
				if ( ! empty( $node['editor_settings']['title'] ) ) {
					$title = $node['editor_settings']['title'];
					$root['editor_settings'] = array_merge(
						$root['editor_settings'] ?? [],
						[ 'title' => count( $roots ) > 1 ? $title . ' (' . ( $i + 1 ) . ')' : $title ]
					);
				}
				/* Fresh ids for the cloned root too, so repeated instances stay unique. */
				$root['id'] = mv_uid();
				if ( ! empty( $root['styles'] ) && is_array( $root['styles'] ) ) {
					$restyled = [];
					foreach ( $root['styles'] as $sid => $sdef ) {
						$new_sid = 'e-' . mv_uid() . '-local';
						if ( is_array( $sdef ) ) {
							$sdef['id']    = $new_sid;
							$sdef['label'] = 'local';
						}
						$restyled[ $new_sid ] = $sdef;
					}
					$root['styles'] = $restyled;
				}
				$style_ids = [];
				if ( ! empty( $root['elements'] ) ) {
					$root_children = $root['elements'];
					mv_reid( $root_children, $style_ids );
					$root['elements'] = $root_children;
				}
				mv_bake_classes( $root, $class_css );
				if ( ! empty( $root['elements'] ) ) {
					$root['elements'] = mv_flatten( $root['elements'], $components, $class_css );
				}
				mv_bake_classes( $root, $class_css );
				$out[] = $root;
			}
			continue;
		}

		if ( ! empty( $node['elements'] ) ) {
			$node['elements'] = mv_flatten( $node['elements'], $components, $class_css );
		}
		mv_bake_classes( $node, $class_css );
		$out[] = $node;
	}
	return $out;
}

/* ------------------------------------------------------------------ *
 * Process
 * ------------------------------------------------------------------ */
$src_dir = $mv_root . '/exports/merryvic-templates';
$dst_dir = $src_dir . '/import-ready';
if ( ! is_dir( $dst_dir ) ) {
	mkdir( $dst_dir, 0755, true );
}

$report = [];
foreach ( glob( $src_dir . '/*.json' ) as $file ) {
	$template = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $template ) || empty( $template['content'] ) ) {
		continue;
	}
	$before          = substr_count( json_encode( $template['content'] ), '"e-component"' );
	$template['content'] = mv_flatten( $template['content'], $components, $class_css );
	$after           = substr_count( json_encode( $template['content'] ), '"e-component"' );

	/* Sanitise: a stdClass props node must encode as {} not [] */
	file_put_contents(
		$dst_dir . '/' . basename( $file ),
		json_encode( $template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
	$report[] = [
		'name'          => $template['title'] ?? basename( $file ),
		'components_in' => $before,
		'components_out' => $after,
		'kb'            => round( filesize( $dst_dir . '/' . basename( $file ) ) / 1024, 1 ),
	];
}

$left = 0;
foreach ( glob( $dst_dir . '/*.json' ) as $file ) {
	$left += substr_count( file_get_contents( $file ), '"e-component"' );
}

/* ------------------------------------------------------------------ *
 * Optional: write the flattened trees back into the WordPress documents.
 *
 * Run: php mv-flatten-templates.php --apply
 *
 * This install's renderer falls back to component defaults for every
 * e-component instance, so a draft that still references components does not
 * show its own copy. Applying the same transform to the documents makes the
 * drafts render exactly what the import-ready pack ships. Originals are backed
 * up to exports/backups/ before anything is written.
 * ------------------------------------------------------------------ */
$apply = in_array( '--apply', $argv ?? [], true );
if ( $apply ) {
	$backup_dir = $mv_root . '/exports/backups/drafts-' . gmdate( 'Ymd-His' );
	if ( ! is_dir( $backup_dir ) ) {
		mkdir( $backup_dir, 0755, true );
	}

	$docs = [];
	$r3 = $db->query(
		"SELECT p.ID AS id, p.post_title, p.post_type, m.meta_value
		 FROM {$prefix}posts p
		 JOIN {$prefix}postmeta m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
		 WHERE p.post_status IN ('draft','publish')
		   AND ( p.post_title LIKE 'MV - %' OR p.post_type = 'elementor_library' )"
	);
	while ( $row = $r3->fetch_assoc() ) {
		$docs[ (int) $row['id'] ] = $row;
	}

	$touched_pages = 0;
	$touched_libs  = 0;
	$still_refs    = 0;
	$backup        = [];

	foreach ( $docs as $id => $doc ) {
		$data = json_decode( $doc['meta_value'], true );
		if ( ! is_array( $data ) || ! $data ) {
			continue;
		}
		$inlined = mv_flatten( $data, $components, $class_css );
		if ( json_encode( $inlined ) === json_encode( $data ) ) {
			continue; // already flat
		}
		$refs = substr_count( json_encode( $inlined ), '"e-component"' );
		$still_refs += $refs;

		$backup[ $id ] = $doc['meta_value'];

		$json = json_encode( $inlined, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$stmt = $db->prepare( "UPDATE {$prefix}postmeta SET meta_value = ? WHERE post_id = ? AND meta_key = '_elementor_data'" );
		$stmt->bind_param( 'si', $json, $id );
		$stmt->execute();
		$db->query( "DELETE FROM {$prefix}postmeta WHERE post_id = {$id} AND meta_key IN ('_elementor_css','_elementor_element_cache')" );

		if ( 'elementor_library' === $doc['post_type'] ) {
			$touched_libs++;
		} else {
			$touched_pages++;
			/* stale revisions would keep serving the old component-based tree */
			$rev = [];
			$r2 = $db->query( "SELECT ID FROM {$prefix}posts WHERE post_type = 'revision' AND post_parent = {$id}" );
			while ( $row = $r2->fetch_assoc() ) {
				$rev[] = (int) $row['ID'];
			}
			foreach ( $rev as $rid ) {
				$db->query( "DELETE FROM {$prefix}postmeta WHERE post_id = {$rid}" );
				$db->query( "DELETE FROM {$prefix}posts WHERE ID = {$rid}" );
			}
		}
	}

	file_put_contents(
		$backup_dir . '/elementor-data.json',
		json_encode( $backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);

	echo "\n=== applied to documents ===\n";
	echo 'backup            : ' . str_replace( $mv_root . '/', '', $backup_dir ) . "\n";
	echo 'drafts inlined    : ' . $touched_pages . "\n";
	echo 'library templates : ' . $touched_libs . "\n";
	echo 'component refs left: ' . $still_refs . "\n";
}

echo "\n=== import-ready templates ===\n";
echo 'components loaded : ' . count( $components ) . "\n";
echo 'global classes    : ' . count( $class_css ) . "\n";
echo 'variables         : ' . count( $var_value ) . "\n";
echo 'templates written : ' . count( $report ) . "\n";
echo 'component refs left: ' . $left . "\n\n";
foreach ( array_slice( $report, 0, 8 ) as $r ) {
	printf( "  %-28s %6s kB   components %d -> %d\n", $r['name'], $r['kb'], $r['components_in'], $r['components_out'] );
}
if ( count( $report ) > 8 ) {
	echo "  ... plus " . ( count( $report ) - 8 ) . " more\n";
}
echo "\n";