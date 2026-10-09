<?php
define( 'WP_CACHE', true );

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * Localized language
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'u255640043_merryDBvic0' );

/** Database username */
define( 'DB_USER', 'u255640043_Dbmvicgold' );

/** Database password */
define( 'DB_PASSWORD', '!QaZ@W8SX#2EDC%RDZ' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',          '{J<T!Xtf(5{T]?AP+JSb/c+OX/rYO|+DJVSk,^pqwuK3[diAuNZ{H~`k.H9029ZN' );
define( 'SECURE_AUTH_KEY',   ')KYaSxyuZ-N?i<`gNH(CN=,7wr2Nml,:#gI<_jxWsm^k,BgTfX,F9&U*3FEMy(96' );
define( 'LOGGED_IN_KEY',     'jZq@{X-Zub$?n9zh&Inko+di}&;!8jIEe,qN3_6{%YBahyTD-TsTzV>cD7{7tm4}' );
define( 'NONCE_KEY',         'UKiqyyI+pY)?>%QjiJ?]4F,v&jP.3`eQ0Lh(^Mw2D1NL1-dW{ub94=i,kaI,+a{b' );
define( 'AUTH_SALT',         '^>tn<oRN&.AFGE{?Tl8gw,6/f.+C1,I5YD91f6{>Q^l</#v8K{w`;40 .pDT2-ra' );
define( 'SECURE_AUTH_SALT',  '(|&/v~Tid=I}Y^9As0Q!::rDec5$4)>_^WoA|RO25UW*eJ5$PFPbvJ}e~+,|m,1/' );
define( 'LOGGED_IN_SALT',    'Unc#Ir3 $>PSQ<#L2 B&tbs6`3@?@4CzeQGealVXN!ST,-sz%OhM5>@,BkkudW{Y' );
define( 'NONCE_SALT',        '}h_W UQl) zb&.3~U?UHPwaq0EeEAWZBh/kWP,-$08F[&>V%,5Mx4Gaotf$;% x7' );
define( 'WP_CACHE_KEY_SALT', 'Qn`TqsG=Uw )!%Blq{-]z>MC^P$sr|0IDb>Zv.Y!HHYZM;<.@-Gs_5/r+5xSw6@T' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'mv_';


/* Add any custom values between this line and the "stop editing" line. */



/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
 */
if ( ! defined( 'WP_DEBUG' ) ) {
	define( 'WP_DEBUG', true );
	define( 'WP_DEBUG_LOG', true );
    define( 'WP_DEBUG_DISPLAY', false );
}

define( 'FS_METHOD', 'direct' );
define( 'COOKIEHASH', '8e3625759d80f98759353a5943fcb1da' );
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
define( 'DISALLOW_FILE_EDIT', true );
# define('WP_POST_REVISIONS', 2 );
define('AUTOSAVE_INTERVAL', 259200 );

// define( 'FS_METHOD', 'direct' );
define( 'LITESPEED_OBJECT_CACHE', true );

/* That's all, stop editing! Happy publishing. */

// Localhost URL override for development
if ( isset( $_SERVER['HTTP_HOST'] ) && in_array( strtolower( $_SERVER['HTTP_HOST'] ), array( 'localhost', '127.0.0.1', '[::1]' ), true ) ) {
    $home_url = getenv( 'WP_HOME' ) ?: 'http://localhost/merryvic';
    $site_url = getenv( 'WP_SITEURL' ) ?: $home_url;
    define( 'WP_HOME', $home_url );
    define( 'WP_SITEURL', $site_url );
}

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
