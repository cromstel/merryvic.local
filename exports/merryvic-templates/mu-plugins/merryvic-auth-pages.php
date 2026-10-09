<?php
/**
 * Plugin Name: MerryVic - login and registration on separate pages
 * Description: Splits WooCommerce's combined login + registration form onto two
 *              dedicated pages (/account-login/ and /register/), links them to
 *              each other, and sends logged-out visits to /my-account/ to the
 *              login page.
 * Version:     1.1.0
 *
 * WHY A MUST-USE PLUGIN
 * WooCommerce renders both forms from one template
 * (myaccount/form-login.php) as a tabbed pair of columns:
 *
 *   <div class="u-columns col2-set" id="customer_login">
 *     <div class="u-column1 col-1">  ... login form ...
 *     <div class="u-column2 col-2">  ... registration form ...
 *
 * There is no [woocommerce_my_account_login] shortcode in current WooCommerce and
 * no filter for a single column, so each page renders WooCommerce's own template
 * and keeps only the column it needs. The markup, hooks, nonces and validation
 * all stay WooCommerce's; if the markup ever changes shape the selector matches
 * nothing and the page falls back to the untouched template rather than
 * rendering blank.
 *
 * It lives in mu-plugins because it must run on every request, ahead of any page
 * cache, so the two auth pages keep working with a warm object cache.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'MV_AUTH_LOGIN_SLUG' ) ) {
	define( 'MV_AUTH_LOGIN_SLUG', 'account-login' );
}
if ( ! defined( 'MV_AUTH_REGISTER_SLUG' ) ) {
	define( 'MV_AUTH_REGISTER_SLUG', 'register' );
}

/* ------------------------------------------------------------------ *
 * Functional page content slot
 * ------------------------------------------------------------------ */

/**
 * Render the current page's classic content (the plugin shortcode it holds).
 *
 * Elementor's theme-post-content widget is for single templates - on a page
 * built with Elementor it renders nothing, because the page body lives in
 * _elementor_data. Functional pages here (checkout, cart, orders, dashboards,
 * store listings, auctions, tracking, bookings, filters, auth) keep their plugin
 * shortcode in post_content, and the Elementor template embeds this shortcode
 * through Elementor's shortcode widget, so the plugin output survives inside the
 * branded frame.
 *
 * @return string
 */
function merryvic_page_content() {
	/* Any notice queued for this request belongs above the shortcode's own output.
	 * The theme has drained the queue by now, so this is mostly what the capture
	 * saved - without it a coupon applied, an item removed or a rejected order
	 * update would pass without a word on screen. */
	$notices = merryvic_slot_notices();

	$post = get_post();
	if ( ! $post instanceof WP_Post || ! $post->post_content ) {
		return $notices;
	}
	$content = $post->post_content;
	if ( false === strpos( $content, '[' ) ) {
		return $notices . wpautop( $content );
	}
	return $notices . do_shortcode( wpautop( $content ) );
}
add_shortcode( 'merryvic_page_content', 'merryvic_page_content' );

/**
 * Flag our pages in the body class, so stylesheet rules can be scoped to them
 * rather than guessed page by page.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function merryvic_slot_body_class( $classes ) {
	if ( merryvic_slot_page() ) {
		$classes[] = 'mv-slot-page';
	}
	return $classes;
}
add_filter( 'body_class', 'merryvic_slot_body_class' );

/* ------------------------------------------------------------------ *
 * Page lookup
 * ------------------------------------------------------------------ */
function merryvic_auth_page( $slug ) {
	$page = get_page_by_path( $slug );
	return $page instanceof WP_Post ? $page : null;
}

function merryvic_auth_login_url() {
	$page = merryvic_auth_page( MV_AUTH_LOGIN_SLUG );
	return $page ? get_permalink( $page ) : home_url( '/' . MV_AUTH_LOGIN_SLUG . '/' );
}

function merryvic_auth_register_url() {
	$page = merryvic_auth_page( MV_AUTH_REGISTER_SLUG );
	return $page ? get_permalink( $page ) : home_url( '/' . MV_AUTH_REGISTER_SLUG . '/' );
}

/* ------------------------------------------------------------------ *
 * Rendering WooCommerce's form, one column at a time
 * ------------------------------------------------------------------ */

/**
 * Render myaccount/form-login.php and keep a single column.
 *
 * @param string $column Either 'col-1' (login) or 'col-2' (registration).
 * @return string|null Column markup, or null when it cannot be located.
 */
function merryvic_auth_wc_form( $column ) {
	if ( ! function_exists( 'wc_get_template' ) ) {
		return null;
	}

	ob_start();
	wc_get_template( 'myaccount/form-login.php' );
	$html = (string) ob_get_clean();

	if ( ! class_exists( 'DOMDocument' ) ) {
		return null;
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html );
	libxml_clear_errors();
	$xpath = new DOMXPath( $doc );

	$wrapper = $xpath->query( "//*[@id='customer_login']" );
	if ( ! $wrapper || ! $wrapper->length ) {
		$wrapper = $xpath->query( "//*[contains(concat(' ', normalize-space(@class), ' '), ' col2-set ')]" );
	}
	if ( ! $wrapper || ! $wrapper->length ) {
		return null;
	}
	$wrapper = $wrapper->item( 0 );

	$wanted = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$column} ')]", $wrapper );
	if ( ! $wanted || ! $wanted->length ) {
		return null;
	}
	$wanted = $wanted->item( 0 );

	/* Drop the sibling column, keep everything else WooCommerce emitted. */
	$other = ( 'col-1' === $column ) ? 'col-2' : 'col-1';
	$drop  = $xpath->query( ".//*[contains(concat(' ', normalize-space(@class), ' '), ' {$other} ')]", $wrapper );
	if ( $drop && $drop->length ) {
		foreach ( iterator_to_array( $drop ) as $node ) {
			if ( $node->parentNode ) {
				$node->parentNode->removeChild( $node );
			}
		}
	}

	$out = $doc->saveHTML( $wrapper );
	return trim( $out ) ? $out : null;
}

function merryvic_auth_signed_in_notice() {
	if ( ! function_exists( 'wc_get_page_permalink' ) ) {
		return '';
	}
	return '<p class="mv-auth-alt">You are already signed in. <a href="'
		. esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">Go to your account</a>.</p>';
}

/**
 * Is the page being served one of ours, i.e. does it carry one of our slots?
 *
 * Identified by content rather than by slug, so the check keeps working after an
 * import that gives the pages different slugs or IDs on the production site.
 *
 * @return bool
 */
function merryvic_slot_page() {
	if ( is_admin() || ! is_page() ) {
		return false;
	}

	$id = get_queried_object_id();
	if ( ! $id ) {
		return false;
	}

	$content = (string) get_post_field( 'post_content', $id );

	foreach ( array( '[merryvic_page_content]', '[merryvic_login_form]', '[merryvic_register_form]' ) as $slot ) {
		if ( false !== strpos( $content, $slot ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Save WooCommerce's queued notices before anything else can consume them.
 *
 * Blocksy drains the queue on `blocksy:content:top` into a buffer that it only
 * echoes on `blocksy:single:top` - an earlier hook - so on any page it does not
 * recognise as a WooCommerce page the notices are consumed and never shown. Every
 * one of our templates is such a page, which would leave a failed sign-in, a
 * rejected registration or an applied coupon silently invisible.
 *
 * `wc_print_notices()` both prints and clears, so this has to run before any
 * output. `template_redirect` is early enough: the handlers that queue notices
 * run on `wp_loaded`, which fires before it.
 */
function merryvic_slot_capture_notices() {
	if ( ! merryvic_slot_page() || ! function_exists( 'wc_print_notices' ) ) {
		return;
	}

	$notices = wc_print_notices( true );
	if ( is_string( $notices ) && '' !== trim( $notices ) ) {
		$GLOBALS['merryvic_slot_notices'] = $notices;
	}
}
add_action( 'template_redirect', 'merryvic_slot_capture_notices', 4 );

/**
 * Print WooCommerce's queued notices above the slot's own output.
 *
 * Two things have to be preserved here, and in this order:
 *
 * 1. Whatever the capture took, because by the time a slot renders the theme has
 *    already drained the queue.
 * 2. Anything queued after the capture, since a shortcode may add its own.
 *
 * myaccount/form-login.php only prints notices from
 * `woocommerce_before_customer_login_form`, before the columns open, so the
 * column extraction in merryvic_auth_wc_form() discards them too. Printing them
 * here keeps them with the form the visitor just submitted.
 *
 * @return string
 */
function merryvic_slot_notices() {
	/* Consume the captured HTML. A page can nest slots - the template embeds
	 * [merryvic_page_content], whose body is the [merryvic_login_form] - so the
	 * same notice would otherwise be printed at every level. */
	$html = '';
	if ( isset( $GLOBALS['merryvic_slot_notices'] ) ) {
		$html = (string) $GLOBALS['merryvic_slot_notices'];
		unset( $GLOBALS['merryvic_slot_notices'] );
	}

	if ( function_exists( 'wc_print_notices' ) ) {
		$late = wc_print_notices( true );
		if ( is_string( $late ) ) {
			$html .= $late;
		}
	}

	if ( '' === trim( $html ) ) {
		return '';
	}

	return '<div class="woocommerce mv-auth-notices">' . $html . '</div>';
}

add_shortcode(
	'merryvic_login_form',
	function () {
		if ( is_user_logged_in() ) {
			return merryvic_auth_signed_in_notice();
		}

		/* Before rendering: the template's own notice output is discarded below. */
		$notices = merryvic_slot_notices();
		$form    = merryvic_auth_wc_form( 'col-1' );
		if ( null === $form ) {
			$form = '<p class="mv-auth-alt">The sign-in form is unavailable right now. Please try again shortly.</p>';
		}

		return '<div class="mv-auth-form mv-auth-form--login">' . $notices . $form
			. '<p class="mv-auth-alt">New to MerryVic? <a href="'
			. esc_url( merryvic_auth_register_url() ) . '">Create an account</a>.</p></div>';
	}
);

/**
 * Make Dokan's vendor fields optional on the customer-facing register page.
 *
 * Dokan injects Shop Name, Shop URL and Phone into WooCommerce's registration
 * form and marks all three `required`, but its own validation only enforces them
 * when the submitted role is `seller`. On a page where customers register, that
 * combination blocks every customer submission in the browser before it ever
 * reaches the server.
 *
 * So on this page the three fields lose the HTML `required` flag and their
 * asterisk, and gain a note. A seller who leaves them blank still gets Dokan's
 * own "Please provide a shop name." message on submit, so nothing is lost.
 *
 * @param string $html Registration markup.
 * @return string
 */
function merryvic_auth_relax_vendor_fields( $html ) {
	foreach ( [ 'shopname', 'shopurl', 'phone' ] as $name ) {
		$pos = strpos( $html, 'name="' . $name . '"' );
		if ( false === $pos ) {
			continue;
		}

		/* The whole form row, from its opening <p to the matching close. */
		$row_start = strrpos( substr( $html, 0, $pos ), '<p' );
		if ( false === $row_start ) {
			continue;
		}
		$row_end = strpos( $html, '</p>', $pos );
		if ( false === $row_end ) {
			continue;
		}
		$row = substr( $html, $row_start, $row_end - $row_start + 4 );

		$row = preg_replace( '/\srequired\s*=\s*(["\'])required\1/i', '', $row );
		$row = preg_replace( '#<span class="required">\s*\*\s*</span>#', '', $row );
		$row = str_replace(
			'</label>',
			'</label><small class="mv-field-note">Only needed if you plan to sell on MerryVic.</small>',
			$row
		);

		$html = substr( $html, 0, $row_start ) . $row . substr( $html, $row_end + 4 );
	}

	return $html;
}

add_shortcode(
	'merryvic_register_form',
	function () {
		if ( is_user_logged_in() ) {
			return merryvic_auth_signed_in_notice();
		}
		if ( 'yes' !== get_option( 'woocommerce_enable_myaccount_registration' ) ) {
			return '<p class="mv-auth-alt">Registration is closed at the moment. <a href="'
				. esc_url( merryvic_auth_login_url() )
				. '">Sign in</a> if you already have an account.</p>';
		}

		/* Before rendering: the template's own notice output is discarded below. */
		$notices = merryvic_slot_notices();
		$form    = merryvic_auth_wc_form( 'col-2' );
		if ( null === $form ) {
			$form = '<p class="mv-auth-alt">The registration form is unavailable right now. Please try again shortly.</p>';
		} else {
			$form = merryvic_auth_relax_vendor_fields( $form );
		}

		return '<div class="mv-auth-form mv-auth-form--register">' . $notices . $form
			. '<p class="mv-auth-alt">Already have an account? <a href="'
			. esc_url( merryvic_auth_login_url() ) . '">Log in</a>.</p></div>';
	}
);

/* ------------------------------------------------------------------ *
 * Routing: logged-out /my-account/ belongs on the login page
 * ------------------------------------------------------------------ */
add_action(
	'template_redirect',
	function () {
		if ( is_admin() || is_user_logged_in() ) {
			return;
		}
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		/* Account endpoints - /my-account/lost-password/ and friends - are real
		 * pages in their own right and must keep working. */
		if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
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
	},
	5
);

/* ------------------------------------------------------------------ *
 * Broken menu links: redirect the dead paths to their live equivalents.
 * This survives import - on the new site the same broken hrefs will hit
 * these redirects, which are keyed by URL path rather than by page ID.
 * ------------------------------------------------------------------ */
add_action(
	'template_redirect',
	function () {
		$req = $_SERVER['REQUEST_URI'] ?? '';
		/* The "Delivery" item points to /tracking/ (404) instead of
		 * /merryvic/tracking/. The contact link points to /contact-us. */
		if ( false !== strpos( $req, '/tracking/' ) && false === strpos( $req, '/merryvic/' ) ) {
			wp_safe_redirect( site_url( '/tracking/' ), 301 );
			exit;
		}
		if ( false !== strpos( $req, '/contact-us' ) && false === strpos( $req, '/merryvic/' ) ) {
			wp_safe_redirect( site_url( '/contact-us/' ), 301 );
			exit;
		}
	},
	3
);

/* ------------------------------------------------------------------ *
 * Presentation: one column per page
 * ------------------------------------------------------------------ */
add_action(
	'wp_head',
	function () {
		if ( is_admin() ) {
			return;
		}
		echo "<style id=\"merryvic-auth-pages\">\n" . <<<'CSS'
/* Each auth page keeps one column, so it fills the panel instead of sitting in a pair. */
.mv-auth-form .col2-set { display: block; }
.mv-auth-form .u-column1,
.mv-auth-form .u-column2 { float: none; width: 100%; }
.mv-auth-form .u-column1::after,
.mv-auth-form .u-column2::after { content: ""; display: table; clear: both; }
.mv-auth-alt { margin: 1.25rem 0 0; text-align: center; font-size: 0.95rem; }
.mv-auth-alt a { font-weight: 600; }
/* Vendor-only fields on the shared register form. */
.mv-field-note { display: block; margin-top: 0.35rem; font-size: 0.8rem; opacity: 0.75; }
/* WooCommerce fills its action buttons with the site accent, which is the gold
   token. White on that gold measures 2.9:1 and fails AA, so buttons in our slots
   carry ink instead: 6.4:1. Light or transparent buttons keep working, since ink
   reads on those too. Keyed on WooCommerce's own button classes rather than a
   .woocommerce ancestor, which the auth forms sit outside of. */
.mv-slot-page .woocommerce-button,
.mv-slot-page .woocommerce-form button[type="submit"],
.mv-slot-page .checkout-button,
.mv-slot-page .place-order button,
.mv-slot-page .woocommerce a.button,
.mv-slot-page .woocommerce button { color: var(--mv-ink, #171309); }
CSS
			. "\n</style>\n";
	},
	999
);