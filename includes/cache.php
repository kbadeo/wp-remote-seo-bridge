<?php
/**
 * Cache purging after a push.
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =============================================================================
// CACHE PURGE
// =============================================================================

function wseb_purge_cache( $post_id = null ) {
    // LiteSpeed Cache
    if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
        if ( $post_id ) LiteSpeed_Cache_API::purge_post( $post_id );
        else            LiteSpeed_Cache_API::purge_all();
    }
    // WP Rocket
    if ( function_exists( 'rocket_clean_post' ) && $post_id ) rocket_clean_post( $post_id );
    if ( function_exists( 'rocket_clean_domain' ) && ! $post_id ) rocket_clean_domain();
    // W3 Total Cache
    if ( function_exists( 'w3tc_flush_post' ) && $post_id ) w3tc_flush_post( $post_id );
    if ( function_exists( 'w3tc_flush_all' )  && ! $post_id ) w3tc_flush_all();
    // WP Super Cache
    if ( function_exists( 'wp_cache_clear_cache' ) && ! $post_id ) wp_cache_clear_cache();
}
