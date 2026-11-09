<?php
/**
 * Plugin Name:  WP Remote SEO Bridge
 * Plugin URI:   https://github.com/kbadeo/wp-remote-seo-bridge
 * Description:  Secure REST API bridge for remote bulk SEO meta and schema injection.
 *               Supports Yoast SEO and Rank Math. Covers posts, pages, custom post types,
 *               WooCommerce products, and taxonomy terms (product categories, tags, etc).
 *               Integrates with Header and Footer Scripts by Anand Kumar for post/page schema,
 *               and an ACF field for taxonomy term schema (categories, product categories, etc).
 *               Also reads/updates each SEO plugin's llms.txt (AI visibility) settings,
 *               and can push a fully custom llms.txt file directly to the site root.
 *               Includes a CSV SEO exporter (Yoast/Rank Math/AIOSEO), with per-post/term
 *               and sitewide JSON-LD schema columns, on its own admin settings tab.
 * Version:      1.9.3
 * Author:       Karl Badeo
 * License:      MIT
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSEB_VERSION',   '1.9.3' );
define( 'WSEB_OPTION',    'wseb_settings' );
define( 'WSEB_NS',        'wseb/v1' );
define( 'WSEB_LOG_TABLE', 'wseb_audit_log' );

// =============================================================================
// LOAD MODULES
// =============================================================================

$wseb_modules = [ 'activation', 'helpers', 'seo-meta', 'llms', 'schema', 'cache', 'admin-page', 'exporter', 'rest-routes' ];
$wseb_missing = [];
foreach ( $wseb_modules as $wseb_module ) {
    if ( ! is_readable( __DIR__ . '/includes/' . $wseb_module . '.php' ) ) $wseb_missing[] = $wseb_module . '.php';
}
if ( $wseb_missing ) {
    // Copying only this file (as older versions allowed) would otherwise be a fatal error on every
    // request. Check every module first so a partial install loads nothing, show a notice, and stay
    // inactive.
    add_action( 'admin_notices', function () use ( $wseb_missing ) {
        echo '<div class="notice notice-error"><p>WP Remote SEO Bridge is incomplete and inactive. '
            . 'Install the whole plugin folder, including includes/. Missing: '
            . esc_html( implode( ', ', $wseb_missing ) ) . '.</p></div>';
    } );
    return;
}
foreach ( $wseb_modules as $wseb_module ) {
    require_once __DIR__ . '/includes/' . $wseb_module . '.php';
}

// Must be registered from the main plugin file so __FILE__ is the plugin's own path.
register_activation_hook( __FILE__, 'wseb_activate' );
