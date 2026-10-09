<?php
/**
 * MerryVic — Elementor template exporter.
 *
 * 1. Finds every draft page titled "MV - * (Template)".
 * 2. Converts each into a real Elementor library template (elementor_library)
 *    so it appears under Templates -> Saved Templates.
 * 3. Writes an Elementor-import-format .json per template.
 * 4. Writes a media manifest + copies every referenced image.
 * 5. Exports the kit design tokens (global variables + global classes).
 *
 * Run:  php mv-export-templates.php
 */

if ( PHP_SAPI !== 'cli' ) {
    exit( "CLI only.\n" );
}

$_SERVER['HTTP_HOST']      = 'localhost';
$_SERVER['SERVER_NAME']    = 'localhost';
$_SERVER['SERVER_PORT']    = '80';
$_SERVER['REQUEST_URI']    = '/merryvic/';
$_SERVER['REQUEST_METHOD'] = 'GET';

/* Resolve the WordPress root so this script runs from any directory. */
$mv_root = __DIR__;
while ( ! file_exists( $mv_root . '/wp-load.php' ) && dirname( $mv_root ) !== $mv_root ) {
	$mv_root = dirname( $mv_root );
}

define( 'WP_USE_THEMES', false );
define( 'ABSPATH', $mv_root . '/' );

require $mv_root . '/wp-load.php';

$out_dir = $mv_root . '/exports/merryvic-templates';
$media_dir = $out_dir . '/media';
foreach ( [ $out_dir, $media_dir ] as $d ) {
    if ( ! is_dir( $d ) && ! mkdir( $d, 0755, true ) ) {
        fwrite( STDERR, "Cannot create $d\n" );
        exit( 1 );
    }
}

$site_url = untrailingslashit( home_url() );

/* ------------------------------------------------------------------ *
 * 1. Collect templates
 * ------------------------------------------------------------------ */
$pages = get_posts(
    [
        'post_type'   => 'page',
        'post_status' => 'any',
        'numberposts' => -1,
        'orderby'     => 'ID',
        'order'       => 'ASC',
    ]
);

$candidates = [];
foreach ( $pages as $p ) {
    if ( 0 !== strpos( $p->post_title, 'MV - ' ) ) {
        continue;
    }
    /* Purge throwaway probe pages. */
    if ( false !== stripos( $p->post_title, 'spike' ) ) {
        $old = get_posts(
            [
                'post_type'   => 'elementor_library',
                'post_status' => 'any',
                'numberposts' => -1,
                'fields'      => 'ids',
                'meta_query'  => [
                    [
                        'key'   => '_mv_source_page',
                        'value' => $p->ID,
                    ],
                ],
            ]
        );
        foreach ( $old as $lib ) {
            wp_delete_post( $lib, true );
        }
        wp_delete_post( $p->ID, true );
        echo "  purged probe: {$p->post_title}\n";
        continue;
    }
    $candidates[] = $p;
}

if ( ! $candidates ) {
    fwrite( STDERR, "No 'MV - ' templates found.\n" );
    exit( 1 );
}

/* Skip re-conversion if a library template already exists for this page. */
function mv_slug( $title ) {
    $slug = sanitize_title( str_replace( [ 'MV - ', ' (Template)' ], '', $title ) );
    return $slug;
}

/**
 * Walk an Elementor document tree and collect every referenced attachment,
 * copying the file into the export's media folder.
 *
 * Elementor 4.x stores media as typed values:
 *   { "$$type": "image-attachment-id", "value": 9532 }
 */
function mv_collect_attachment_ids( $node, array &$manifest, $media_dir ) {
    if ( ! is_array( $node ) ) {
        return;
    }
    if ( isset( $node['$$type'] ) && 'image-attachment-id' === $node['$$type'] ) {
        $att_id = (int) ( $node['value'] ?? 0 );
        if ( $att_id && ! isset( $manifest[ $att_id ] ) && 'attachment' === get_post_type( $att_id ) ) {
            $file_rel = get_attached_file( $att_id );
            $copied   = false;
            if ( $file_rel && is_file( $file_rel ) ) {
                $copied = (bool) @copy( $file_rel, $media_dir . '/' . wp_basename( $file_rel ) );
            }
            $manifest[ $att_id ] = [
                'id'     => $att_id,
                'title'  => get_the_title( $att_id ),
                'file'   => $file_rel ? wp_basename( $file_rel ) : null,
                'url'    => wp_get_attachment_url( $att_id ),
                'copied' => $copied,
            ];
        }
        return;
    }
    foreach ( $node as $child ) {
        mv_collect_attachment_ids( $child, $manifest, $media_dir );
    }
}

$manifest = [];
$report   = [];

/* ------------------------------------------------------------------ *
 * 2/3. Convert + export
 * ------------------------------------------------------------------ */

/**
 * Make cards inside a loop grid clickable.
 *
 * Elementor's `post-url` tag does not resolve through the MCP build endpoint, so it is
 * written straight into the document data here; Elementor then resolves it per item at
 * render time (inside a loop it yields that product's permalink).
 */
function mv_link_loop_cards( &$nodes ) {
	foreach ( $nodes as &$node ) {
		if ( 'e-collection-loop-item' === ( $node['elType'] ?? '' ) ) {
			/* Copy into a real variable: foreach cannot take references into a temporary. */
			$children = isset( $node['elements'] ) ? $node['elements'] : [];
			foreach ( $children as &$child ) {
				$type = $child['widgetType'] ?? '';
				if ( in_array( $type, [ 'e-image', 'e-heading' ], true ) && empty( $child['settings']['link'] ) ) {
					$child['settings']['link'] = [
						'$$type' => 'link',
						'value'  => [
							'destination' => [
								'$$type' => 'dynamic',
								'value'  => [
									'name'     => 'post-url',
									'settings' => new stdClass(),
								],
							],
							'tag' => [
								'$$type' => 'string',
								'value'  => 'a',
							],
						],
					];
				}
			}
			unset( $child );
			$node['elements'] = $children;
		}
		if ( ! empty( $node['elements'] ) ) {
			$children = $node['elements'];
			mv_link_loop_cards( $children );
			$node['elements'] = $children;
		}
	}
	unset( $node );
}
foreach ( $candidates as $page ) {
    $raw = get_post_meta( $page->ID, '_elementor_data', true );
    if ( ! $raw ) {
        $report[] = [ 'title' => $page->post_title, 'status' => 'SKIP (no Elementor data)' ];
        continue;
    }

    $data     = json_decode( $raw, true );
    $raw_set  = get_post_meta( $page->ID, '_elementor_page_settings', true );
    if ( is_array( $raw_set ) ) {
        $raw_set = wp_json_encode( $raw_set );
    }
    $settings = $raw_set ? json_decode( (string) $raw_set, true ) : [];

    /* Ensure loop-grid cards are clickable, and keep the page itself in sync. */
    if ( is_array( $data ) ) {
        mv_link_loop_cards( $data );
        update_post_meta(
            $page->ID,
            '_elementor_data',
            wp_slash( wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
        );
    }
    $name     = trim( str_replace( [ 'MV - ', ' (Template)' ], '', $page->post_title ) );
    $slug     = mv_slug( $page->post_title );
    $json     = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

    /* -- JSON export -- */
    $payload = [
        'version'       => '0.4',
        'title'         => $name,
        'type'          => 'page',
        'content'       => $data,
        'page_settings' => $settings ?: [],
    ];
    $file = $out_dir . '/' . $slug . '.json';
    file_put_contents( $file, wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );

    /* -- Elementor library template -- */
    $existing = get_posts(
        [
            'post_type'   => 'elementor_library',
            'post_status' => 'any',
            'numberposts' => 1,
            'fields'      => 'ids',
            'meta_query'  => [
                [
                    'key'   => '_mv_source_page',
                    'value' => $page->ID,
                ],
            ],
        ]
    );

    if ( $existing ) {
        $lib_id = (int) $existing[0];
    } else {
        $lib_id = wp_insert_post(
            [
                'post_type'   => 'elementor_library',
                'post_status' => 'publish',
                'post_title'  => $name,
                'post_name'   => $slug,
            ]
        );
        if ( is_wp_error( $lib_id ) ) {
            $report[] = [ 'title' => $page->post_title, 'status' => 'ERROR ' . $lib_id->get_error_message() ];
            continue;
        }
        update_post_meta( $lib_id, '_mv_source_page', $page->ID );
    }

    update_post_meta( $lib_id, '_elementor_data', wp_slash( $json ) );
    update_post_meta( $lib_id, '_elementor_edit_mode', 'builder' );
    update_post_meta( $lib_id, '_elementor_template_type', 'page' );
    if ( $settings ) {
        update_post_meta( $lib_id, '_elementor_page_settings', wp_slash( wp_json_encode( $settings ) ) );
    }
    if ( function_exists( 'wp_set_object_terms' ) ) {
        wp_set_object_terms( $lib_id, 'page', 'elementor_library_type', false );
    }

    /* -- Media (walk typed values so nested image-attachment-id is found) -- */
    mv_collect_attachment_ids( $data, $manifest, $media_dir );

    $report[] = [
        'title'    => $name,
        'page_id'  => $page->ID,
        'lib_id'   => $lib_id,
        'elements' => is_array( $data ) ? count( $data ) : 0,
        'kb'       => round( strlen( $json ) / 1024, 1 ),
        'status'   => 'OK',
    ];
}

/* ------------------------------------------------------------------ *
 * 4. Media manifest
 * ------------------------------------------------------------------ */
file_put_contents(
    $out_dir . '/media-manifest.json',
    wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
);

/* ------------------------------------------------------------------ *
 * 5. Design tokens (kit global variables + global classes)
 * ------------------------------------------------------------------ */
$kit_id = (int) get_option( 'elementor_active_kit' );
$tokens = [
    'site'      => [ 'name' => get_bloginfo( 'name' ), 'url' => $site_url ],
    'kit_id'    => $kit_id,
    'variables' => [],
    'classes'   => [],
    'css'       => '',
];
if ( $kit_id ) {
    $vars = get_post_meta( $kit_id, '_elementor_global_variables', true );
    if ( ! $vars ) {
        $vars = get_post_meta( $kit_id, '_elementor_variables', true );
    }
    $tokens['variables'] = $vars ? json_decode( $vars, true ) : [];

    $classes = get_post_meta( $kit_id, '_elementor_classes', true );
    $tokens['classes'] = $classes ? json_decode( $classes, true ) : [];

    /* Compile a plain :root block + class list for hand-applying on another site. */
    $root = [];
    if ( is_array( $tokens['variables'] ) ) {
        foreach ( $tokens['variables'] as $key => $v ) {
            if ( empty( $v['value'] ) ) {
                continue;
            }
            $label = $v['label'] ?? $key;
            $val   = $v['value'];
            $root[] = "  --{$label}: {$val};";
        }
    }
    $class_list = [];
    if ( is_array( $tokens['classes'] ) ) {
        foreach ( $tokens['classes'] as $c ) {
            if ( empty( $c['label'] ) ) {
                continue;
            }
            $class_list[] = $c['label'];
            $vars_css = [];
            foreach ( [ 'default' => '', 'tablet' => '@media (max-width:1024px)', 'mobile' => '@media (max-width:767px)' ] as $bp => $mq ) {
                $declarations = '';
                if ( ! empty( $c[ $bp ] ) && is_array( $c[ $bp ] ) ) {
                    foreach ( $c[ $bp ] as $prop => $val ) {
                        if ( is_array( $val ) ) {
                            continue;
                        }
                        $declarations .= "  {$prop}: {$val};\n";
                    }
                }
                if ( '' !== $declarations ) {
                    $tokens['css'] .= ($mq ? $mq . "{\n" . $declarations . "}\n" : "." . $c['label'] . "{\n" . $declarations . "}\n");
                }
            }
        }
    }
    $tokens['css'] = ":root{\n" . implode( "\n", $root ) . "\n}\n\n" . $tokens['css'];
}
file_put_contents( $out_dir . '/design-tokens.json', wp_json_encode( $tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
file_put_contents( $out_dir . '/design-tokens.css', $tokens['css'] );

/* ---- Accurate pass: variables (kit meta) + classes (individual posts) ---- */

/** Flatten a variant's props into CSS declarations, resolving global-var refs. */
function mv_css_declarations( array $props, array $var_labels ) {
    $out = '';
    foreach ( $props as $prop => $val ) {
        if ( is_array( $val ) ) {
            if ( isset( $val['$$type'] ) && isset( $val['value'] ) ) {
                $ref = is_array( $val['value'] ) ? ( $val['value']['value'] ?? '' ) : $val['value'];
                if ( isset( $var_labels[ $ref ] ) ) {
                    $out .= "  {$prop}: var(--{$ref});\n";
                    continue;
                }
                $val = $ref;
            } else {
                continue;
            }
        }
        if ( is_scalar( $val ) && '' !== (string) $val ) {
            $out .= "  {$prop}: {$val};\n";
        }
    }
    return $out;
}

$var_labels = [];
$raw_vars   = $kit_id ? json_decode( (string) get_post_meta( $kit_id, '_elementor_global_variables', true ), true ) : [];
if ( is_array( $raw_vars ) ) {
    $vars = isset( $raw_vars['data'] ) && is_array( $raw_vars['data'] ) ? $raw_vars['data'] : $raw_vars;
    foreach ( $vars as $v ) {
        if ( empty( $v['label'] ) ) {
            continue;
        }
        $value = $v['value'] ?? '';
        if ( is_array( $value ) ) {
            $value = $value['value'] ?? '';
        }
        $var_labels[ $v['label'] ] = $value;
    }
}

$classes = [];
$css     = '';
if ( $kit_id ) {
    $id_map    = (array) get_post_meta( $kit_id, '_elementor_global_classes_post_ids', true );
    $labels    = (array) get_post_meta( $kit_id, '_elementor_global_classes_labels', true );
    $order_raw = (array) get_post_meta( $kit_id, '_elementor_global_classes_order', true );
    $order     = ( isset( $order_raw['order'] ) && is_array( $order_raw['order'] ) ) ? $order_raw['order'] : array_keys( $id_map );

    foreach ( $order as $cid ) {
        $post_id = isset( $id_map[ $cid ] ) ? (int) $id_map[ $cid ] : (int) $cid;
        if ( ! $post_id || 'attachment' === get_post_type( $post_id ) || ! get_post_meta( $post_id, '_elementor_global_class_data', true ) ) {
            continue;
        }
        $label = $labels[ $cid ] ?? get_the_title( $post_id );
        $data  = get_post_meta( $post_id, '_elementor_global_class_data', true );
        $data  = is_array( $data ) ? $data : maybe_unserialize( $data );
        if ( ! is_array( $data ) ) {
            continue;
        }
        $entry = [ 'label' => $label, 'id' => $cid, 'variants' => [] ];
        foreach ( (array) ( $data['variants'] ?? [] ) as $variant ) {
            $bp    = $variant['meta']['breakpoint'] ?? 'desktop';
            $state = $variant['meta']['state'] ?? null;
            $decl  = mv_css_declarations( (array) ( $variant['props'] ?? [] ), $var_labels );
            if ( '' === trim( $decl ) ) {
                continue;
            }
            $entry['variants'][] = [ 'breakpoint' => $bp, 'state' => $state, 'css' => trim( $decl ) ];
            $sel   = '.' . $label . ( $state ? ':' . $state : '' );
            $block = $sel . " {\n" . $decl . "}\n";
            if ( 'tablet' === $bp ) {
                $css .= "@media (max-width: 1024px) {\n" . preg_replace( '/^/m', '  ', $block ) . "}\n";
            } elseif ( 'mobile' === $bp ) {
                $css .= "@media (max-width: 767px) {\n" . preg_replace( '/^/m', '  ', $block ) . "}\n";
            } else {
                $css .= $block;
            }
        }
        if ( $entry['variants'] ) {
            $classes[] = $entry;
        }
    }
}

$root_lines = [];
foreach ( $var_labels as $lbl => $val ) {
    $root_lines[] = "  --{$lbl}: {$val};";
}
$final = [
    'site'      => [ 'name' => get_bloginfo( 'name' ), 'url' => $site_url ],
    'kit_id'    => $kit_id,
    'variables' => $var_labels,
    'classes'   => $classes,
    'css'       => $css,
];
file_put_contents(
    $out_dir . '/design-tokens.json',
    wp_json_encode( $final, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
);
file_put_contents(
    $out_dir . '/design-tokens.css',
    "/* MerryVic design tokens - paste into the target site (custom CSS or a CSS file). */\n\n:root {\n" . implode( "\n", $root_lines ) . "\n}\n\n" . $css
);

/* ------------------------------------------------------------------ *
 * Report
 * ------------------------------------------------------------------ */
$copied = count( array_filter( $manifest, fn( $m ) => $m['copied'] ) );
echo "\n=== MerryVic template export ===\n";
foreach ( $report as $r ) {
    printf(
        "  %-28s %s%s\n",
        $r['title'],
        $r['status'],
        isset( $r['lib_id'] ) ? sprintf( '  (library #%d, %d root elems, %s kB)', $r['lib_id'], $r['elements'], $r['kb'] ) : ''
    );
}
printf( "\n  templates : %d\n", count( $report ) );
printf( "  media     : %d referenced, %d copied\n", count( $manifest ), $copied );
printf( "  output    : %s\n", $out_dir );
echo "\n";