<?php
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
define( 'DB_NAME', 'wpdev1' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', '127.0.0.1' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', 'utf8mb4_unicode_ci' );

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
define( 'AUTH_KEY',          'yc#`6j]Z>:ZdM@IfS4Mayo`vx|^)vdYEqzw1f7!N7W?mZiV^(Z_omii*mPZM7GYE' );
define( 'SECURE_AUTH_KEY',   ']+L0K4/}s?SK?=5/`_ZXmZidDQfR%,u@5wrZtlFVW<jvi)op&v|}8YV?B!ZjWLI.' );
define( 'LOGGED_IN_KEY',     'J-l&4gsDfPnU((rzE%(yMf(DqR22 N36O5p%!],y3eHc~xN(/z5+]SWXIbUih{QW' );
define( 'NONCE_KEY',         'h}1G;HQ6[V*phu#sj[!_}qpBqf7/IM1fc^yf>QNxJ7]ZYfznG}{:v.5~N|99o;Sv' );
define( 'AUTH_SALT',         '>j!~av.LR$gZbM36>Nh!XN76eKW_YEY-% p2+$H/Pb`d7@{g,%_!c?;uoPHWy,cq' );
define( 'SECURE_AUTH_SALT',  'uuj~CAcO6$IwncO5YcO,CervAP)2x[!O<<h(a3*5PNVYbQJTUdP<lH&`YQ62AG?<' );
define( 'LOGGED_IN_SALT',    '{?CJU/$B7@2U1Iod%`#f>VOstvK|+8cI&g6L.m4KbbAdzT9[;$?^K3@zLrdkrh2O' );
define( 'NONCE_SALT',        '/jIHQx1}_KA~,{b%Cai{?Q%x;YSy(hfzJR94D9%ILC9+w>fG})Wm?KbXRX:C:|Va' );
define( 'WP_CACHE_KEY_SALT', 'lk8|uzYpTWo@ZdHJX%1X*qsR~&V~_W_Y.%(iV.Sh]36DP`!4#xA /:fSOm/-,c4 ' );


/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';


/* Add any custom values between this line and the "stop editing" line. */

define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', true);
define('SCRIPT_DEBUG', true);
@ini_set('display_errors', 1);


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
	define( 'WP_DEBUG', false );
}

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
