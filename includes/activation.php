<?php
/**
 * Activation hook callback and the audit-log table.
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =============================================================================
// ACTIVATION
// =============================================================================

function wseb_activate() {
    if ( ! get_option( WSEB_OPTION ) ) {
        update_option( WSEB_OPTION, [
            'token'              => wp_generate_password( 40, false ),
            'token_created'      => time(),
            'expiry_hours'       => 0,
            'webhook_url'        => '',
            'ep_ping'            => 1,
            'ep_health'          => 1,
            'ep_scan'            => 1,
            'ep_scan_terms'      => 1,
            'ep_meta'            => 1,
            'ep_meta_terms'      => 1,
            'ep_llms_settings'   => 1,
            'ep_llms_txt'        => 1,
            'ep_schema'          => 1,
            'ep_sitewide_schema' => 1,
            'ep_purge_cache'     => 1,
            'term_schema_field'  => 'header_script',
        ] );
    }
    wseb_create_log_table();
    wseb_sync_acf_term_schema_field();
}

// =============================================================================
// LOG TABLE
// =============================================================================

function wseb_create_log_table() {
    global $wpdb;
    $table   = $wpdb->prefix . WSEB_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS {$table} (
        id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        endpoint    VARCHAR(60) NOT NULL,
        method      VARCHAR(10) NOT NULL,
        scope       VARCHAR(20) NOT NULL,
        status      VARCHAR(10) NOT NULL,
        ip          VARCHAR(45),
        note        TEXT,
        PRIMARY KEY (id)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

function wseb_log( $endpoint, $method, $scope, $status, $ip = null, $note = [] ) {
    global $wpdb;
    $wpdb->insert( $wpdb->prefix . WSEB_LOG_TABLE, [
        'endpoint'   => $endpoint,
        'method'     => $method,
        'scope'      => $scope,
        'status'     => $status,
        'ip'         => $ip ?? ( $_SERVER['REMOTE_ADDR'] ?? '' ),
        'note'       => ! empty( $note ) ? wp_json_encode( $note ) : null,
    ] );
}
