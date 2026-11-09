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

require_once __DIR__ . '/includes/activation.php';
require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/seo-meta.php';
require_once __DIR__ . '/includes/llms.php';
require_once __DIR__ . '/includes/schema.php';
require_once __DIR__ . '/includes/cache.php';
require_once __DIR__ . '/includes/admin-page.php';
require_once __DIR__ . '/includes/exporter.php';
require_once __DIR__ . '/includes/rest-routes.php';

// Must be registered from the main plugin file so __FILE__ is the plugin's own path.
register_activation_hook( __FILE__, 'wseb_activate' );
