<?php
/**
 * MerryVic — step 1: separate login and registration.
 *
 * - registers [merryvic_login_form] and [merryvic_register_form]
 * - sends logged-out visitors on /my-account/ to the dedicated login page
 * - creates the Register page and points the stale LoginPress menu item at it
 * - puts the login shortcode in the existing (empty) Account Login page
 */
error_reporting( 0 );
mysqli_report( MYSQLI_REPORT_OFF );

$creds = [];
foreach ( file( __DIR__ . '/wp-config.php' ) as $line ) {
	if ( preg_match( "/define\(\s*'(DB_[A-Z_]+)'\s*,\s*'([^']*)'\s*\)/", $line, $m ) ) {
		$creds[ $m[1] ] = $m[2];
	} elseif ( preg_match( "/\\\$table_prefix\s*=\s*'([^']+)'/", $line, $m ) ) {
		$creds['prefix'] = $m[1];
	}
}
$db = new mysqli( '127.0.0.1', $creds['DB_USER'], $creds['DB_PASSWORD'], $creds['DB_NAME'] );
$db->set_charset( 'utf8mb4' );
$p = $creds['prefix'];

$now = gmdate( 'Y-m-d H:i:s' );

/* ------------------------------------------------------------------ *
 * 1. The snippet
 * ------------------------------------------------------------------ */
$snippet = <<<'PHP'
<?php
/**
 * MerryVic - login and registration on separate pages.
 *
 * WooCommerce renders BOTH the login and the registration form from a single
 * template (myaccount/form-login.php), wrapped in one tabbed column pair and
 * gated by the `woocommerce_enable_myaccount_registration` option. That is why
 * /my-account/ shows two forms stacked when nobody is signed in.
 *
 * This snippet splits them without touching WooCommerce's markup or hooks:
 *   [merryvic_login_form]     forces the option to "no" for that request, so
 *                              WooCommerce renders the login form only.
 *   [merryvic_register_form]  renders the same template and keeps only the
 *                              registration column (DOM extract, WooCommerce's
 *                              own markup and hooks preserved).
 *
 * Both add a cross-link to the other page, and logged-out visitors landing on
 * /my-account/ are sent to the login page so the combined form no longer
 * competes with the two dedicated pages.
 */
defined( 'ABSPATH' ) || exit;

const MV_AUTH_LOGIN_SLUG    = 'account-login';
const MV_AUTH_REGISTER_SLUG = 'register';

function merryvic_auth_page( $slug ) {
	$page = get_page_by_path( $slug );
	return $page ? $page : null;
}
function merryvic_auth_login_url() {
	$page = merryvic_auth_page( MV_AUTH_LOGIN_SLUG );
	return $page ? get_permalink( $page ) : home_url( '/' . MV_AUTH_LOGIN_SLUG . '/' );
}
function merryvic_auth_register_url() {
	$page = merryvic_auth_page( MV_AUTH_REGISTER_SLUG );
	return $page ? get_permalink( $page ) : home_url( '/' . MV_AUTH_REGISTER_SLUG . '/' );
}

/* Already signed in? Point at the dashboard instead of showing a form. */
function merryvic_auth_signed_in_notice() {
	return '<p class="mv-auth-alt">You are already signed in. '
		. '<a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">Go to your account</a>.</p>';
}

add_shortcode( 'merryvic_login_form', function () {
	if ( is_user_logged_in() ) {
		return merryvic_auth_signed_in_notice();
	}
	/* WooCommerce only emits the single-column login form when registration is off. */
	add_filter( 'option_woocommerce_enable_myaccount_registration', '__return_no' );

	ob_start();
	echo do_shortcode( '[woocommerce_my_account_login]' );
	$html = ob_get_clean();

	return '<div class="mv-auth-form mv-auth-form--login">' . $html
		. '<p class="mv-auth-alt">New to MerryVic? '
		. '<a href="' . esc_url( merryvic_auth_register_url() ) . '">Create an account</a>.</p></div>';
} );

add_shortcode( 'merryvic_register_form', function () {
	if ( is_user_logged_in() ) {
		return merryvic_auth_signed_in_notice();
	}
	if ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) {
		return '<p class="mv-auth-alt">Registration is closed at the moment. '
			. '<a href="' . esc_url( merryvic_auth_login_url() ) . '">Sign in</a> if you already have an account.</p>';
	}

	ob_start();
	echo do_shortcode( '[woocommerce_my_account_login]' );
	$html = ob_get_clean();

	/* Keep WooCommerce's registration markup; drop the login column beside it. */
	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="mv-auth-root">' . $html . '</div>' );
	libxml_clear_errors();
	$xpath = new DOMXPath( $doc );

	$login = $xpath->query( "//*[@id='login']" );
	$dropped = 0;
	if ( $login && $login->length ) {
		foreach ( $login as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
				$dropped++;
			}
		}
	}
	if ( ! $dropped ) {
		/* Never blank the page: if the markup changed, render what WooCommerce gave us. */
		return '<div class="mv-auth-form mv-auth-form--register">' . $html
			. '<p class="mv-auth-alt">Already have an account? '
			. '<a href="' . esc_url( merryvic_auth_login_url() ) . '">Log in</a>.</p></div>';
	}

	$inner = '';
	foreach ( $xpath->query( "//*[@id='mv-auth-root']" )->item( 0 )->childNodes as $child ) {
		$inner .= $doc->saveHTML( $child );
	}

	return '<div class="mv-auth-form mv-auth-form--register">' . $inner
		. '<p class="mv-auth-alt">Already have an account? '
		. '<a href="' . esc_url( merryvic_auth_login_url() ) . '">Log in</a>.</p></div>';
} );

/* Logged-out visits to /my-account/ belong on the dedicated login page.
 * Endpoints (lost-password, edit-address, ...) are left alone. */
add_action( 'template_redirect', function () {
	if ( is_admin() || is_user_logged_in() ) {
		return;
	}
	if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
		return;
	}
	global $wp;
	if ( ! empty( $wp->query_vars['endpoint'] ) ) {
		return;
	}
	$login = merryvic_auth_page( MV_AUTH_LOGIN_SLUG );
	if ( ! $login ) {
		return;
	}
	wp_safe_redirect( get_permalink( $login ), 302 );
	exit;
}, 5 );

/* Presentation: one column once a form has been isolated, and the cross-link. */
add_action( 'wp_head', function () {
	if ( is_admin() ) {
		return;
	}
	echo "<style id=\"merryvic-auth-pages\">\n" . <<<'CSS'
.mv-auth-form .col2-set { display: block; }
.mv-auth-form .u-column { float: none; width: 100%; }
.mv-auth-form .u-column::after { content: ""; display: table; clear: both; }
.mv-auth-alt { margin: 1.25rem 0 0; text-align: center; font-size: 0.95rem; }
.mv-auth-alt a { font-weight: 600; }
CSS
	. "\n</style>\n";
}, 999 );
PHP;

$files = serialize( [ [ 'name' => 'main.php', 'content_b64' => base64_encode( $snippet ) ] ] );

$existing = $db->query( "SELECT ID FROM {$p}posts WHERE post_type='angie_snippet' AND post_title='MerryVic Auth pages'" )->fetch_assoc();
if ( $existing ) {
	$sid = (int) $existing['ID'];
	$u   = $db->prepare( "UPDATE {$p}postmeta SET meta_value=? WHERE post_id=? AND meta_key='_angie_snippet_files'" );
	$u->bind_param( 'si', $files, $sid );
	$u->execute();
	echo "snippet updated: #$sid\n";
} else {
	/* build the post row from the real schema, as in the import test */
	$cols = $db->query( "SHOW COLUMNS FROM {$p}posts" );
	$set  = [
		'post_author'       => 70,
		'post_date'         => $now,
		'post_date_gmt'     => $now,
		'post_modified'     => $now,
		'post_modified_gmt' => $now,
		'post_title'        => 'MerryVic Auth pages',
		'post_name'         => 'merryvic-auth-pages',
		'post_status'       => 'publish',
		'post_type'         => 'angie_snippet',
		'comment_status'    => 'closed',
		'ping_status'       => 'closed',
	];
	$cols_out = [];
	$vals     = [];
	while ( $c = $cols->fetch_assoc() ) {
		if ( stripos( $c['Extra'] ?? '', 'auto_increment' ) !== false ) {
			continue;
		}
		$is_int = (bool) preg_match( '/^(tinyint|smallint|mediumint|int|bigint|bit)/i', $c['Type'] ?? '' );
		$val    = array_key_exists( $c['Field'], $set ) ? $set[ $c['Field'] ] : ( $is_int ? 0 : '' );
		$cols_out[] = $c['Field'];
		$vals[]     = $is_int ? (string) (int) $val : "'" . $db->real_escape_string( (string) $val ) . "'";
	}
	$db->query( "INSERT INTO {$p}posts (" . implode( ',', $cols_out ) . ") VALUES (" . implode( ',', $vals ) . ")" );
	$sid = (int) $db->insert_id;
	$u   = $db->prepare( "INSERT INTO {$p}postmeta (post_id, meta_key, meta_value) VALUES ({$sid}, '_angie_snippet_files', ?)" );
	$u->bind_param( 's', $files );
	$u->execute();
	echo "snippet created: #$sid\n";
}

/* Angie caches the published snippet list in a transient */
$db->query( "DELETE FROM {$p}options WHERE option_name IN ('_transient_angie_published_snippet_ids','_transient_timeout_angie_published_snippet_ids')" );
echo "angie snippet cache cleared\n";

/* ------------------------------------------------------------------ *
 * 2. Register page
 * ------------------------------------------------------------------ */
$reg = $db->query( "SELECT ID FROM {$p}posts WHERE post_type='page' AND post_name='register'" )->fetch_assoc();
if ( $reg ) {
	$reg_id = (int) $reg['ID'];
	echo "register page already exists: #$reg_id\n";
} else {
	$cols = $db->query( "SHOW COLUMNS FROM {$p}posts" );
	$set  = [
		'post_author'       => 70,
		'post_date'         => $now,
		'post_date_gmt'     => $now,
		'post_modified'     => $now,
		'post_modified_gmt' => $now,
		'post_title'        => 'Register',
		'post_name'         => 'register',
		'post_status'       => 'publish',
		'post_type'         => 'page',
		'post_content'      => '[merryvic_register_form]',
		'comment_status'    => 'closed',
		'ping_status'       => 'closed',
	];
	$cols_out = [];
	$vals     = [];
	while ( $c = $cols->fetch_assoc() ) {
		if ( stripos( $c['Extra'] ?? '', 'auto_increment' ) !== false ) {
			continue;
		}
		$is_int = (bool) preg_match( '/^(tinyint|smallint|mediumint|int|bigint|bit)/i', $c['Type'] ?? '' );
		$val    = array_key_exists( $c['Field'], $set ) ? $set[ $c['Field'] ] : ( $is_int ? 0 : '' );
		$cols_out[] = $c['Field'];
		$vals[]     = $is_int ? (string) (int) $val : "'" . $db->real_escape_string( (string) $val ) . "'";
	}
	$db->query( "INSERT INTO {$p}posts (" . implode( ',', $cols_out ) . ") VALUES (" . implode( ',', $vals ) . ")" );
	$reg_id = (int) $db->insert_id;
	echo "register page created: #$reg_id\n";
}

$home_url = getenv('HOME_URL') ?: 'http://localhost/merryvic';
$reg_url  = $home_url . '/register/';

/* ------------------------------------------------------------------ *
 * 3. Login page gets the login shortcode
 * ------------------------------------------------------------------ */
$login_id = 9663; // existing, empty "Account Login" page (slug account-login)
$content  = '[merryvic_login_form]';
$u        = $db->prepare( "UPDATE {$p}posts SET post_content=?, post_status='publish', post_modified=? WHERE ID=?" );
$u->bind_param( 'ssi', $content, $now, $login_id );
$u->execute();
echo "login page #$login_id content set\n";

/* ------------------------------------------------------------------ *
 * 4. Repoint the dead LoginPress menu item
 * ------------------------------------------------------------------ */
$item = $db->query( "SELECT ID FROM {$p}posts WHERE post_type='nav_menu_item' AND post_title='Register'" )->fetch_assoc();
if ( $item ) {
	$iid = (int) $item['ID'];
	$u   = $db->prepare( "UPDATE {$p}postmeta SET meta_value=? WHERE post_id=? AND meta_key='_menu_item_url'" );
	$u->bind_param( 'si', $reg_url, $iid );
	$u->execute();
	echo "menu item #$iid -> $reg_url\n";
}

/* New page needs its rewrite rule; ask WordPress to flush permalinks. */
$db->query( "INSERT INTO {$p}options (option_name, option_value, autoload) VALUES ('_mv_flush_permalinks', '1', 'no')
	ON DUPLICATE KEY UPDATE option_value = '1'" );
echo "permalink flush flagged (run Tools > Update Permalinks, or visit Settings > Permalinks once)\n";