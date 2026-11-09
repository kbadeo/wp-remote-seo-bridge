<?php
/**
 * Settings access, token authentication, and the standard response helpers.
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =============================================================================
// HELPERS
// =============================================================================

function wseb_settings() {
    return (array) get_option( WSEB_OPTION, [] );
}

function wseb_ep_enabled( $key ) {
    $s = wseb_settings();
    return ! empty( $s[ 'ep_' . $key ] );
}

function wseb_auth( WP_REST_Request $req ) {
    $s     = wseb_settings();
    $token = $s['token'] ?? '';
    if ( ! $token ) return false;
    $sent  = $req->get_header( 'X-WSEB-Token' )
          ?: $req->get_header( 'X-SEO-Bridge-Token' ); // back-compat alias
    if ( ! hash_equals( $token, (string) $sent ) ) return false;
    if ( ! empty( $s['expiry_hours'] ) ) {
        $created = $s['token_created'] ?? 0;
        if ( time() - $created > (int) $s['expiry_hours'] * 3600 ) return false;
    }
    return true;
}

function wseb_deny( $msg = 'Unauthorized' ) {
    return new WP_Error( 'wseb_denied', $msg, [ 'status' => 401 ] );
}

/**
 * Build the permission_callback for a REST route. Every route must use this,
 * so the endpoint toggle and token check run in one place before the handler
 * and a new route cannot be left open by forgetting the check.
 */
function wseb_permission( $endpoint_key ) {
    return function ( WP_REST_Request $req ) use ( $endpoint_key ) {
        if ( ! wseb_ep_enabled( $endpoint_key ) ) return wseb_deny( 'Endpoint disabled' );
        if ( ! wseb_auth( $req ) )                return wseb_deny();
        return true;
    };
}

function wseb_ok( $data = [] ) {
    return rest_ensure_response( array_merge( [ 'success' => true ], $data ) );
}
