<?php
/**
 * Plugin Name:  WP Remote SEO Bridge
 * Plugin URI:   https://github.com/your-agency/wp-remote-seo-bridge
 * Description:  Secure REST API bridge for remote SEO auditing, meta updates, and schema injection. Plug-and-play across any WordPress site. Supports Yoast SEO and RankMath. Auto-detects header script plugins.
 * Version:      1.0.0
 * Author:       Your Agency
 * License:      GPL-2.0-or-later
 * Text Domain:  wp-remote-seo-bridge
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WSEB_VERSION',     '1.0.0' );
define( 'WSEB_OPTION_BASE', 'wseb_' );
define( 'WSEB_LOG_TABLE',   'wseb_log' );
define( 'WSEB_NAMESPACE',   'seo-bridge/v1' );

// ─── ACTIVATION / DEACTIVATION ────────────────────────────────────────────────
register_activation_hook( __FILE__, 'wseb_activate' );
register_deactivation_hook( __FILE__, 'wseb_deactivate' );

function wseb_activate() {
    // Generate admin token if not already set
    if ( ! get_option( WSEB_OPTION_BASE . 'admin_token' ) ) {
        update_option( WSEB_OPTION_BASE . 'admin_token', wseb_generate_token(), false );
    }
    // Create log table
    wseb_create_log_table();
    // Schedule self-deactivation cron if expiry is set
    wseb_schedule_expiry();
}

function wseb_deactivate() {
    wp_clear_scheduled_hook( 'wseb_self_deactivate' );
    wp_clear_scheduled_hook( 'wseb_purge_old_logs' );
}

function wseb_generate_token( $length = 48 ) {
    return bin2hex( random_bytes( $length / 2 ) );
}

function wseb_create_log_table() {
    global $wpdb;
    $table   = $wpdb->prefix . WSEB_LOG_TABLE;
    $charset = $wpdb->get_charset_collate();
    $sql     = "CREATE TABLE IF NOT EXISTS {$table} (
        id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
        endpoint   VARCHAR(100)    NOT NULL,
        method     VARCHAR(10)     NOT NULL,
        ip         VARCHAR(45)     NOT NULL,
        token_scope VARCHAR(20)    NOT NULL DEFAULT 'admin',
        payload    LONGTEXT,
        response   LONGTEXT,
        status     VARCHAR(20)     NOT NULL DEFAULT 'ok',
        PRIMARY KEY (id),
        KEY created_at (created_at)
    ) {$charset};";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( $sql );
}

// ─── CRON: SELF-DEACTIVATION ──────────────────────────────────────────────────
add_action( 'wseb_self_deactivate', 'wseb_do_self_deactivate' );
function wseb_do_self_deactivate() {
    deactivate_plugins( plugin_basename( __FILE__ ) );
    update_option( WSEB_OPTION_BASE . 'deactivated_at', current_time('mysql'), false );
}

function wseb_schedule_expiry() {
    $expiry_hours = (int) get_option( WSEB_OPTION_BASE . 'expiry_hours', 0 );
    wp_clear_scheduled_hook( 'wseb_self_deactivate' );
    if ( $expiry_hours > 0 ) {
        wp_schedule_single_event( time() + ( $expiry_hours * HOUR_IN_SECONDS ), 'wseb_self_deactivate' );
    }
}

// ─── CRON: PURGE OLD LOGS ─────────────────────────────────────────────────────
add_action( 'wseb_purge_old_logs', 'wseb_do_purge_old_logs' );
function wseb_do_purge_old_logs() {
    global $wpdb;
    $table = $wpdb->prefix . WSEB_LOG_TABLE;
    $wpdb->query( "DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)" );
}
if ( ! wp_next_scheduled('wseb_purge_old_logs') ) {
    wp_schedule_event( time(), 'daily', 'wseb_purge_old_logs' );
}

// ─── ADMIN PAGE ───────────────────────────────────────────────────────────────
add_action( 'admin_menu', function() {
    add_options_page( 'Remote SEO Bridge', 'Remote SEO Bridge', 'manage_options', 'wp-remote-seo-bridge', 'wseb_admin_page' );
});

function wseb_admin_page() {
    if ( ! current_user_can('manage_options') ) return;

    // Handle saves
    if ( isset($_POST['wseb_save']) && check_admin_referer('wseb_settings') ) {
        // Token settings
        if ( isset($_POST['wseb_regen_admin']) ) {
            update_option( WSEB_OPTION_BASE . 'admin_token', wseb_generate_token(), false );
        }
        update_option( WSEB_OPTION_BASE . 'scoped_tokens_enabled', isset($_POST['scoped_tokens_enabled']) ? 1 : 0, false );
        if ( isset($_POST['wseb_regen_read']) ) {
            update_option( WSEB_OPTION_BASE . 'read_token', wseb_generate_token(), false );
        }
        if ( isset($_POST['wseb_regen_write']) ) {
            update_option( WSEB_OPTION_BASE . 'write_token', wseb_generate_token(), false );
        }
        // Expiry
        $expiry = max( 0, (int) ( $_POST['expiry_hours'] ?? 0 ) );
        update_option( WSEB_OPTION_BASE . 'expiry_hours', $expiry, false );
        wseb_schedule_expiry();
        // Endpoint toggles
        foreach ( ['ping','scan','update','schema','health','options','purge_cache'] as $ep ) {
            update_option( WSEB_OPTION_BASE . 'ep_' . $ep, isset($_POST['ep_' . $ep]) ? 1 : 0, false );
        }
        // Webhook
        update_option( WSEB_OPTION_BASE . 'webhook_url', esc_url_raw( $_POST['webhook_url'] ?? '' ), false );
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    // Handle log clear
    if ( isset($_POST['wseb_clear_log']) && check_admin_referer('wseb_settings') ) {
        global $wpdb;
        $wpdb->query( "TRUNCATE TABLE " . $wpdb->prefix . WSEB_LOG_TABLE );
        echo '<div class="notice notice-success"><p>Log cleared.</p></div>';
    }

    $admin_token    = get_option( WSEB_OPTION_BASE . 'admin_token', '' );
    $scoped_enabled = get_option( WSEB_OPTION_BASE . 'scoped_tokens_enabled', 0 );
    $read_token     = get_option( WSEB_OPTION_BASE . 'read_token', '' );
    $write_token    = get_option( WSEB_OPTION_BASE . 'write_token', '' );
    $expiry_hours   = get_option( WSEB_OPTION_BASE . 'expiry_hours', 0 );
    $webhook_url    = get_option( WSEB_OPTION_BASE . 'webhook_url', '' );
    $seo_plugin     = wseb_detect_seo_plugin();
    $hfs_plugin     = wseb_detect_hfs_plugin();
    $base_url       = get_bloginfo('url') . '/wp-json/' . WSEB_NAMESPACE;
    $expiry_ts      = wp_next_scheduled('wseb_self_deactivate');

    $endpoints = ['ping','scan','update','schema','health','options','purge_cache'];

    ?>
    <div class="wrap">
    <h1>WP Remote SEO Bridge <span style="font-size:13px;font-weight:normal;color:#999">v<?php echo WSEB_VERSION ?></span></h1>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;max-width:1100px;margin-top:20px">

    <!-- LEFT COLUMN -->
    <div>
    <form method="post">
    <?php wp_nonce_field('wseb_settings') ?>

    <div class="postbox" style="padding:16px">
        <h2 style="margin-top:0">Site Detection</h2>
        <table class="widefat" style="font-size:13px">
            <tr><td><strong>SEO Plugin</strong></td><td><?php
                if ( $seo_plugin === 'both' )       echo '<span style="color:#d17c00">Both Yoast and RankMath active — deactivate one</span>';
                elseif ( $seo_plugin === 'yoast' )  echo '<span style="color:#1a6b3a">Yoast SEO detected</span>';
                elseif ( $seo_plugin === 'rankmath') echo '<span style="color:#1a6b3a">RankMath detected</span>';
                else                                echo '<span style="color:#c0392b">None detected</span>';
            ?></td></tr>
            <tr><td><strong>Header Script Plugin</strong></td><td><?php
                echo $hfs_plugin ? '<span style="color:#1a6b3a">' . esc_html($hfs_plugin['name']) . '</span>' : '<span style="color:#888">None detected</span>';
            ?></td></tr>
            <tr><td><strong>Base URL</strong></td><td><code style="font-size:11px"><?php echo esc_html($base_url) ?></code></td></tr>
            <?php if ($expiry_ts): ?>
            <tr><td><strong>Auto-deactivates</strong></td><td><span style="color:#c0392b"><?php echo date('Y-m-d H:i:s', $expiry_ts) ?></span></td></tr>
            <?php endif ?>
        </table>
    </div>

    <div class="postbox" style="padding:16px;margin-top:16px">
        <h2 style="margin-top:0">Admin Token <span style="font-size:12px;font-weight:normal;color:#555">(full access, always active)</span></h2>
        <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px">
            <input type="text" value="<?php echo esc_attr($admin_token) ?>" readonly style="width:100%;font-family:monospace;font-size:12px" onclick="this.select()">
            <button type="submit" name="wseb_regen_admin" class="button" onclick="return confirm('Regenerate admin token? All existing connections using this token will stop working.')">Regen</button>
        </div>
        <p style="color:#666;font-size:12px;margin:0">Pass as <code>X-SEO-Bridge-Token</code> header or <code>?token=</code> query param.</p>
    </div>

    <div class="postbox" style="padding:16px;margin-top:16px">
        <h2 style="margin-top:0">Scoped Tokens <span style="font-size:12px;font-weight:normal;color:#555">(optional)</span></h2>
        <label><input type="checkbox" name="scoped_tokens_enabled" value="1" <?php checked($scoped_enabled) ?>> Enable scoped tokens</label>
        <?php if ($scoped_enabled): ?>
        <table style="margin-top:12px;width:100%">
            <tr>
                <td style="width:60px;font-size:13px"><strong>Read</strong></td>
                <td><input type="text" value="<?php echo esc_attr($read_token ?: '(not generated)') ?>" readonly style="width:100%;font-family:monospace;font-size:11px" onclick="this.select()"></td>
                <td style="padding-left:8px"><button type="submit" name="wseb_regen_read" class="button button-small">Regen</button></td>
            </tr>
            <tr style="margin-top:8px">
                <td style="font-size:13px"><strong>Write</strong></td>
                <td><input type="text" value="<?php echo esc_attr($write_token ?: '(not generated)') ?>" readonly style="width:100%;font-family:monospace;font-size:11px" onclick="this.select()"></td>
                <td style="padding-left:8px"><button type="submit" name="wseb_regen_write" class="button button-small">Regen</button></td>
            </tr>
        </table>
        <p style="font-size:12px;color:#666;margin-top:8px">Read token: ping, scan, health, meta-scan. Write token: update, schema, options, purge-cache.</p>
        <?php endif ?>
    </div>

    <div class="postbox" style="padding:16px;margin-top:16px">
        <h2 style="margin-top:0">Self-Deactivation Timer</h2>
        <label>Deactivate plugin after
            <input type="number" name="expiry_hours" value="<?php echo (int)$expiry_hours ?>" min="0" max="720" style="width:60px"> hours
        </label>
        <p style="font-size:12px;color:#666;margin:4px 0 0">Set to 0 to disable. Plugin deactivates itself via WP-Cron when timer expires.</p>
    </div>

    <div class="postbox" style="padding:16px;margin-top:16px">
        <h2 style="margin-top:0">Webhook on Write</h2>
        <input type="url" name="webhook_url" value="<?php echo esc_attr($webhook_url) ?>" placeholder="https://hooks.make.com/..." style="width:100%">
        <p style="font-size:12px;color:#666;margin:4px 0 0">POSTs a JSON summary after every successful update or schema write. Leave blank to disable.</p>
    </div>

    <div class="postbox" style="padding:16px;margin-top:16px">
        <h2 style="margin-top:0">Endpoint Toggles</h2>
        <table class="widefat striped" style="font-size:13px">
        <?php foreach ($endpoints as $ep):
            $enabled = get_option(WSEB_OPTION_BASE . 'ep_' . $ep, 1);
        ?>
        <tr>
            <td style="width:140px"><code>/<?php echo str_replace('_','-',$ep) ?></code></td>
            <td><label><input type="checkbox" name="ep_<?php echo $ep ?>" value="1" <?php checked($enabled) ?>> Enabled</label></td>
        </tr>
        <?php endforeach ?>
        </table>
    </div>

    <p><input type="submit" name="wseb_save" class="button button-primary button-large" value="Save Settings"></p>
    </form>
    </div>

    <!-- RIGHT COLUMN: LOG -->
    <div>
    <div class="postbox" style="padding:16px">
        <h2 style="margin-top:0">Audit Log <span style="font-size:12px;font-weight:normal;color:#999">(last 50 calls)</span></h2>
        <?php wseb_render_log() ?>
        <form method="post" style="margin-top:12px">
        <?php wp_nonce_field('wseb_settings') ?>
        <input type="submit" name="wseb_clear_log" class="button button-small" value="Clear Log" onclick="return confirm('Clear all log entries?')">
        </form>
    </div>
    </div>

    </div><!-- grid -->
    </div><!-- wrap -->
    <?php
}

function wseb_render_log() {
    global $wpdb;
    $table = $wpdb->prefix . WSEB_LOG_TABLE;
    $rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT 50" );
    if ( empty($rows) ) { echo '<p style="color:#888">No log entries yet.</p>'; return; }
    echo '<div style="overflow-y:auto;max-height:500px">';
    echo '<table class="widefat striped" style="font-size:12px">';
    echo '<thead><tr><th>Time</th><th>Endpoint</th><th>Scope</th><th>IP</th><th>Status</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $col = $r->status === 'ok' ? '#1a6b3a' : '#c0392b';
        echo '<tr>';
        echo '<td style="white-space:nowrap">' . esc_html($r->created_at) . '</td>';
        echo '<td><code>' . esc_html($r->method) . ' /' . esc_html($r->endpoint) . '</code></td>';
        echo '<td>' . esc_html($r->token_scope) . '</td>';
        echo '<td>' . esc_html($r->ip) . '</td>';
        echo '<td style="color:' . $col . ';font-weight:bold">' . esc_html(strtoupper($r->status)) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}


// ═══════════════════════════════════════════════════════
// DETECTION HELPERS
// ═══════════════════════════════════════════════════════

function wseb_detect_seo_plugin() {
    $has_yoast    = defined('WPSEO_VERSION') || get_option('wpseo_titles') !== false;
    $has_rankmath = defined('RANK_MATH_VERSION') || get_option('rank_math_general') !== false;
    if ( $has_yoast && $has_rankmath ) return 'both';
    if ( $has_yoast )    return 'yoast';
    if ( $has_rankmath ) return 'rankmath';
    return 'none';
}

function wseb_detect_hfs_plugin() {
    // Known header/footer script plugins and their post meta keys
    $known = [
        [
            'name'      => 'Header and Footer Scripts (Anand Kumar)',
            'meta_key'  => '_inpost_head_script',
            'array_key' => 'synth_header_script',
            'type'      => 'serialized_array',
        ],
        [
            'name'      => 'WPCode',
            'meta_key'  => '_wpcode_head_script',
            'array_key' => null,
            'type'      => 'plain',
        ],
        [
            'name'      => 'Head, Footer and Post Injections',
            'meta_key'  => '_hfip_header',
            'array_key' => null,
            'type'      => 'plain',
        ],
    ];
    // Check which plugin's meta key exists on any recent page
    global $wpdb;
    foreach ( $known as $plugin ) {
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
            $plugin['meta_key']
        ) );
        if ( $exists ) return $plugin;
    }
    return null;
}

function wseb_detect_cache_plugin() {
    $plugins = [];
    if ( defined('LSCWP_V') || class_exists('LiteSpeed_Cache') ) $plugins[] = 'litespeed';
    if ( defined('WP_ROCKET_VERSION') )                           $plugins[] = 'wp_rocket';
    if ( defined('W3TC_VERSION') )                                $plugins[] = 'w3tc';
    if ( defined('WPCACHEHOME') )                                 $plugins[] = 'wp_super_cache';
    return $plugins;
}


// ═══════════════════════════════════════════════════════
// AUTH
// ═══════════════════════════════════════════════════════

function wseb_resolve_token( $request ) {
    $token = $request->get_header('X-SEO-Bridge-Token')
          ?: ( $_SERVER['HTTP_X_SEO_BRIDGE_TOKEN'] ?? '' )
          ?: ( $_GET['token'] ?? '' );
    return sanitize_text_field( trim($token) );
}

function wseb_check_auth( $request, $required_scope = 'admin' ) {
    $token          = wseb_resolve_token( $request );
    $admin_token    = get_option( WSEB_OPTION_BASE . 'admin_token', '' );
    $scoped_enabled = get_option( WSEB_OPTION_BASE . 'scoped_tokens_enabled', 0 );

    // Admin token always works
    if ( $token && hash_equals( $admin_token, $token ) ) {
        return 'admin';
    }

    // Scoped tokens (optional)
    if ( $scoped_enabled ) {
        $read_token  = get_option( WSEB_OPTION_BASE . 'read_token', '' );
        $write_token = get_option( WSEB_OPTION_BASE . 'write_token', '' );

        if ( in_array($required_scope, ['read']) && $read_token && hash_equals($read_token, $token) ) {
            return 'read';
        }
        if ( in_array($required_scope, ['read','write']) && $write_token && hash_equals($write_token, $token) ) {
            return 'write';
        }
    }

    return false;
}

function wseb_auth_read( $request )  {
    $scope = wseb_check_auth($request, 'read');
    return $scope ? true : new WP_Error('forbidden','Invalid or missing token.',['status'=>403]);
}
function wseb_auth_write( $request ) {
    $scope = wseb_check_auth($request, 'write');
    return $scope ? true : new WP_Error('forbidden','Invalid or missing token.',['status'=>403]);
}
function wseb_auth_admin( $request ) {
    $scope = wseb_check_auth($request, 'admin');
    return $scope ? true : new WP_Error('forbidden','Invalid or missing token.',['status'=>403]);
}

function wseb_is_endpoint_enabled( $ep ) {
    return (bool) get_option( WSEB_OPTION_BASE . 'ep_' . $ep, 1 );
}

function wseb_endpoint_disabled() {
    return new WP_Error('disabled','This endpoint is disabled.',['status'=>403]);
}


// ═══════════════════════════════════════════════════════
// LOGGING
// ═══════════════════════════════════════════════════════

function wseb_log( $endpoint, $method, $request, $response_data, $status = 'ok' ) {
    global $wpdb;
    $scope = wseb_check_auth($request, 'read') ?: 'unknown';
    $ip    = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $wpdb->insert( $wpdb->prefix . WSEB_LOG_TABLE, [
        'endpoint'    => substr($endpoint, 0, 100),
        'method'      => $method,
        'ip'          => substr($ip, 0, 45),
        'token_scope' => is_string($scope) ? $scope : 'unknown',
        'payload'     => null,
        'response'    => substr(json_encode($response_data), 0, 2000),
        'status'      => $status,
    ]);
}

function wseb_fire_webhook( $data ) {
    $url = get_option( WSEB_OPTION_BASE . 'webhook_url', '' );
    if ( ! $url ) return;
    wp_remote_post( $url, [
        'body'        => json_encode($data),
        'headers'     => ['Content-Type' => 'application/json'],
        'timeout'     => 10,
        'blocking'    => false,
    ]);
}


// ═══════════════════════════════════════════════════════
// BACKUP HELPERS
// ═══════════════════════════════════════════════════════

function wseb_backup_post_meta( $post_id, $meta_key ) {
    $val = get_post_meta($post_id, $meta_key, true);
    update_option(
        WSEB_OPTION_BASE . 'backup_post_' . $post_id . '_' . sanitize_key($meta_key),
        [ 'value' => $val, 'backed_up_at' => current_time('mysql') ],
        false
    );
}

function wseb_backup_option( $option_key ) {
    $val = get_option($option_key);
    update_option(
        WSEB_OPTION_BASE . 'backup_option_' . sanitize_key($option_key),
        [ 'value' => $val, 'backed_up_at' => current_time('mysql') ],
        false
    );
}


// ═══════════════════════════════════════════════════════
// SCHEMA VALIDATION
// ═══════════════════════════════════════════════════════

function wseb_validate_schema( $script_string ) {
    $warnings = [];
    // Must be wrapped in script tags
    if ( ! preg_match('/<script\s+type="application\/ld\+json">(.*?)<\/script>/s', $script_string, $m) ) {
        return [ 'valid' => false, 'error' => 'Missing <script type="application/ld+json"> wrapper', 'warnings' => [] ];
    }
    $json_str = trim($m[1]);
    $data     = json_decode($json_str, true);
    if ( $data === null ) {
        return [ 'valid' => false, 'error' => 'Invalid JSON: ' . json_last_error_msg(), 'warnings' => [] ];
    }
    // Check @context
    $context = $data['@context'] ?? $data['@graph'][0]['@context'] ?? '';
    if ( strpos($context, 'schema.org') === false ) {
        $warnings[] = '@context missing or does not reference schema.org';
    }
    // Check @type exists somewhere
    if ( empty($data['@type']) && empty($data['@graph']) ) {
        $warnings[] = 'No @type or @graph found';
    }
    // Check for known invalid properties
    $invalid_props = ['foundingOrganization','organizationFounder'];
    $json_flat     = json_encode($data);
    foreach ($invalid_props as $prop) {
        if ( strpos($json_flat, '"' . $prop . '"') !== false ) {
            $warnings[] = '"' . $prop . '" is not a valid Schema.org property';
        }
    }
    return [ 'valid' => true, 'error' => null, 'warnings' => $warnings ];
}


// ═══════════════════════════════════════════════════════
// REST API ROUTES
// ═══════════════════════════════════════════════════════

add_action( 'rest_api_init', function() {

    // ── GET /ping ────────────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/ping', [
        'methods'             => 'GET',
        'permission_callback' => 'wseb_auth_read',
        'callback'            => function($r) {
            if ( ! wseb_is_endpoint_enabled('ping') ) return wseb_endpoint_disabled();
            $seo    = wseb_detect_seo_plugin();
            $hfs    = wseb_detect_hfs_plugin();
            $data   = [
                'status'               => 'ok',
                'site'                 => get_bloginfo('name'),
                'url'                  => get_bloginfo('url'),
                'wp_version'           => get_bloginfo('version'),
                'php_version'          => PHP_VERSION,
                'bridge_version'       => WSEB_VERSION,
                'seo_plugin'           => $seo,
                'hfs_plugin'           => $hfs ? $hfs['name'] : null,
                'hfs_meta_key'         => $hfs ? $hfs['meta_key'] : null,
                'timestamp'            => current_time('mysql'),
            ];
            wseb_log('ping','GET',$r,$data);
            return rest_ensure_response($data);
        },
    ]);

    // ── GET /health ──────────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/health', [
        'methods'             => 'GET',
        'permission_callback' => 'wseb_auth_read',
        'callback'            => function($r) {
            if ( ! wseb_is_endpoint_enabled('health') ) return wseb_endpoint_disabled();
            global $wpdb;
            $seo          = wseb_detect_seo_plugin();
            $cache_plugins= wseb_detect_cache_plugin();
            $hfs          = wseb_detect_hfs_plugin();
            // Check sitemap accessibility
            $sitemap_url  = get_bloginfo('url') . '/sitemap_index.xml';
            $sitemap_resp = wp_remote_get($sitemap_url, ['timeout'=>10,'sslverify'=>false]);
            $sitemap_ok   = ! is_wp_error($sitemap_resp) && wp_remote_retrieve_response_code($sitemap_resp) === 200;
            // Check robots.txt
            $robots_url   = get_bloginfo('url') . '/robots.txt';
            $robots_resp  = wp_remote_get($robots_url, ['timeout'=>10,'sslverify'=>false]);
            $robots_ok    = ! is_wp_error($robots_resp) && wp_remote_retrieve_response_code($robots_resp) === 200;
            $robots_body  = is_wp_error($robots_resp) ? '' : wp_remote_retrieve_body($robots_resp);
            $robots_blocks_all = preg_match('/Disallow:\s*\//m', $robots_body) && preg_match('/User-agent:\s*\*/m', $robots_body);
            // DB check
            $db_ok        = $wpdb->get_var("SELECT 1") === '1';
            $data = [
                'wp_version'           => get_bloginfo('version'),
                'php_version'          => PHP_VERSION,
                'db_ok'                => $db_ok,
                'seo_plugin'           => $seo,
                'seo_plugin_version'   => defined('WPSEO_VERSION') ? WPSEO_VERSION : ( defined('RANK_MATH_VERSION') ? RANK_MATH_VERSION : null ),
                'hfs_plugin'           => $hfs ? $hfs['name'] : null,
                'cache_plugins'        => $cache_plugins,
                'sitemap_accessible'   => $sitemap_ok,
                'sitemap_url'          => $sitemap_url,
                'robots_accessible'    => $robots_ok,
                'robots_blocks_all'    => $robots_blocks_all,
                'multisite'            => is_multisite(),
                'active_plugins_count' => count(get_option('active_plugins',[])),
                'checked_at'           => current_time('mysql'),
            ];
            wseb_log('health','GET',$r,$data);
            return rest_ensure_response($data);
        },
    ]);

    // ── GET /scan ────────────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/scan', [
        'methods'             => 'GET',
        'permission_callback' => 'wseb_auth_read',
        'callback'            => function($r) {
            if ( ! wseb_is_endpoint_enabled('scan') ) return wseb_endpoint_disabled();
            global $wpdb;
            $seo = wseb_detect_seo_plugin();
            // Build meta key list based on detected SEO plugin
            $title_key = in_array($seo,['yoast','both']) ? '_yoast_wpseo_title' : 'rank_math_title';
            $desc_key  = in_array($seo,['yoast','both']) ? '_yoast_wpseo_metadesc' : 'rank_math_description';
            $posts = $wpdb->get_results("
                SELECT p.ID, p.post_type, p.post_status, p.post_name, p.post_title,
                       MAX(CASE WHEN pm.meta_key='{$title_key}' THEN pm.meta_value ELSE NULL END) AS seo_title,
                       MAX(CASE WHEN pm.meta_key='{$desc_key}'  THEN pm.meta_value ELSE NULL END) AS seo_desc,
                       MAX(CASE WHEN pm.meta_key='_yoast_wpseo_title'     THEN pm.meta_value ELSE NULL END) AS yoast_title,
                       MAX(CASE WHEN pm.meta_key='_yoast_wpseo_metadesc'  THEN pm.meta_value ELSE NULL END) AS yoast_desc,
                       MAX(CASE WHEN pm.meta_key='rank_math_title'        THEN pm.meta_value ELSE NULL END) AS rm_title,
                       MAX(CASE WHEN pm.meta_key='rank_math_description'  THEN pm.meta_value ELSE NULL END) AS rm_desc
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
                    AND pm.meta_key IN ('{$title_key}','{$desc_key}','_yoast_wpseo_title','_yoast_wpseo_metadesc','rank_math_title','rank_math_description')
                WHERE p.post_status IN ('publish','draft','private')
                  AND p.post_type NOT IN ('revision','nav_menu_item','custom_css','customize_changeset','oembed_cache',
                      'user_request','wp_block','wp_template','wp_template_part','wp_global_styles','wp_navigation',
                      'attachment','acf-field','acf-field-group','bp-email')
                GROUP BY p.ID ORDER BY p.post_type, p.post_name
            ", ARRAY_A);
            // Terms
            $terms_raw = $wpdb->get_results("
                SELECT t.term_id, tt.taxonomy, t.slug, t.name
                FROM {$wpdb->terms} t
                INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id
                WHERE tt.taxonomy NOT IN ('nav_menu','link_category','post_format','bp-email-type',
                    'elementor_library_type','elementor_library_category','product_type','product_visibility','wpcode_type','wpcode_tags','wpcode_location')
                ORDER BY tt.taxonomy, t.slug
            ", ARRAY_A);
            $yoast_tax  = (array) get_option('wpseo_taxonomy_meta',[]);
            $rm_tax     = (array) get_option('rank_math_term_meta',[]);
            foreach ($terms_raw as &$term) {
                $tid = (int)$term['term_id']; $tax = $term['taxonomy'];
                $term['yoast_title'] = $yoast_tax[$tax][$tid]['wpseo_title'] ?? '';
                $term['yoast_desc']  = $yoast_tax[$tax][$tid]['wpseo_desc']  ?? '';
                $term['rm_title']    = $rm_tax[$tid]['title'] ?? '';
                $term['rm_desc']     = $rm_tax[$tid]['description'] ?? '';
            }
            unset($term);
            $data = [
                'seo_plugin'     => $seo,
                'front_page_id'  => (int) get_option('page_on_front'),
                'total_posts'    => count($posts),
                'total_terms'    => count($terms_raw),
                'posts'          => $posts,
                'terms'          => $terms_raw,
            ];
            wseb_log('scan','GET',$r,['total_posts'=>count($posts),'total_terms'=>count($terms_raw)]);
            return rest_ensure_response($data);
        },
    ]);

    // ── GET /taxonomies ──────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/taxonomies', [
        'methods'             => 'GET',
        'permission_callback' => 'wseb_auth_read',
        'callback'            => function($r) {
            global $wpdb;
            $rows = $wpdb->get_results("SELECT taxonomy, COUNT(*) as term_count FROM {$wpdb->term_taxonomy} GROUP BY taxonomy ORDER BY taxonomy", ARRAY_A);
            wseb_log('taxonomies','GET',$r,['count'=>count($rows)]);
            return rest_ensure_response(['taxonomies'=>$rows]);
        },
    ]);

    // ── GET /meta-scan?post_id=X ─────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/meta-scan', [
        'methods'             => 'GET',
        'permission_callback' => 'wseb_auth_read',
        'callback'            => function($r) {
            $post_id = (int) $r->get_param('post_id');
            if (!$post_id) return new WP_Error('bad_request','post_id required',['status'=>400]);
            $all_meta = get_post_meta($post_id);
            $hfs      = wseb_detect_hfs_plugin();
            $hfs_data = $hfs ? get_post_meta($post_id, $hfs['meta_key'], true) : null;
            $hfs_head = '';
            if ($hfs && $hfs['type'] === 'serialized_array' && is_array($hfs_data)) {
                $hfs_head = $hfs_data[$hfs['array_key']] ?? '';
            } elseif ($hfs && $hfs['type'] === 'plain') {
                $hfs_head = (string) $hfs_data;
            }
            $data = [
                'post_id'          => $post_id,
                'all_keys'         => array_keys($all_meta),
                'hfs_plugin'       => $hfs ? $hfs['name'] : null,
                'hfs_head_content' => $hfs_head,
                'yoast_title'      => get_post_meta($post_id,'_yoast_wpseo_title',true),
                'yoast_desc'       => get_post_meta($post_id,'_yoast_wpseo_metadesc',true),
                'rm_title'         => get_post_meta($post_id,'rank_math_title',true),
                'rm_desc'          => get_post_meta($post_id,'rank_math_description',true),
            ];
            wseb_log('meta-scan','GET',$r,$data);
            return rest_ensure_response($data);
        },
    ]);

    // ── POST /lookup ─────────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/lookup', [
        'methods'             => 'POST',
        'permission_callback' => 'wseb_auth_read',
        'callback'            => function($r) {
            global $wpdb;
            $body    = $r->get_json_params();
            $results = [];
            foreach ((array)($body['posts']??[]) as $slug) {
                $slug = sanitize_title($slug);
                $id   = (int)$wpdb->get_var($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_name=%s AND post_status IN ('publish','draft','private') AND post_type NOT IN ('revision','nav_menu_item','attachment') LIMIT 1",$slug));
                $results['posts'][$slug] = $id ?: null;
            }
            if (!empty($body['terms'])) {
                $tax  = sanitize_text_field($body['terms']['taxonomy']??'');
                foreach ((array)($body['terms']['slugs']??[]) as $slug) {
                    $slug = sanitize_title($slug);
                    $id   = (int)$wpdb->get_var($wpdb->prepare(
                        "SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE t.slug=%s AND tt.taxonomy=%s LIMIT 1",$slug,$tax));
                    $results['terms'][$slug] = $id ?: null;
                }
            }
            wseb_log('lookup','POST',$r,$results);
            return rest_ensure_response($results);
        },
    ]);

} ); // end rest_api_init part 1


// ─── WRITE ROUTES ─────────────────────────────────────────────────────────────
add_action( 'rest_api_init', function() {

    // ── POST /update ─────────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/update', [
        'methods'             => 'POST',
        'permission_callback' => 'wseb_auth_write',
        'callback'            => function($r) {
            if (!wseb_is_endpoint_enabled('update')) return wseb_endpoint_disabled();
            $body     = $r->get_json_params();
            $dry_run  = !empty($body['dry_run']);
            $updates  = $body['updates'] ?? [];
            if (empty($updates)||!is_array($updates)) return new WP_Error('bad_request','No updates provided.',['status'=>400]);

            $seo      = wseb_detect_seo_plugin();
            // Use Yoast keys if Yoast active, RankMath otherwise
            $use_yoast = in_array($seo,['yoast','both']);

            $yoast_tax  = (array) get_option('wpseo_taxonomy_meta',[]);
            $rm_tax     = (array) get_option('rank_math_term_meta',[]);
            $tax_dirty_y = false;
            $tax_dirty_r = false;
            $results    = [];

            foreach ($updates as $u) {
                $type  = $u['type']        ?? '';
                $title = $u['title']       ?? '';
                $desc  = $u['description'] ?? '';

                if ($type === 'post') {
                    $id = (int)($u['id']??0);
                    if (!$id) { $results[]=['type'=>'post','id'=>$id,'status'=>'invalid_id']; continue; }
                    if ($dry_run) {
                        $cur_t = $use_yoast ? get_post_meta($id,'_yoast_wpseo_title',true) : get_post_meta($id,'rank_math_title',true);
                        $cur_d = $use_yoast ? get_post_meta($id,'_yoast_wpseo_metadesc',true) : get_post_meta($id,'rank_math_description',true);
                        $results[]=[ 'type'=>'post','id'=>$id,'status'=>($cur_t===$title&&$cur_d===$desc)?'already_set':'would_update','dry_run'=>true ];
                    } else {
                        wseb_backup_post_meta($id, $use_yoast?'_yoast_wpseo_title':'rank_math_title');
                        if ($use_yoast) {
                            update_post_meta($id,'_yoast_wpseo_title',$title);
                            update_post_meta($id,'_yoast_wpseo_metadesc',$desc);
                            $st=(string)get_post_meta($id,'_yoast_wpseo_title',true);
                            $sd=(string)get_post_meta($id,'_yoast_wpseo_metadesc',true);
                        } else {
                            update_post_meta($id,'rank_math_title',$title);
                            update_post_meta($id,'rank_math_description',$desc);
                            $st=(string)get_post_meta($id,'rank_math_title',true);
                            $sd=(string)get_post_meta($id,'rank_math_description',true);
                        }
                        $results[]=[ 'type'=>'post','id'=>$id,'status'=>($st===$title&&$sd===$desc)?'updated':'write_failed' ];
                    }

                } elseif ($type === 'term') {
                    $id  = (int)($u['id']??0);
                    $tax = sanitize_text_field($u['taxonomy']??'');
                    if (!$id||!$tax) { $results[]=[ 'type'=>'term','id'=>$id,'status'=>'invalid_id' ]; continue; }
                    if ($dry_run) {
                        $cur_t = $use_yoast ? ($yoast_tax[$tax][$id]['wpseo_title']??'') : ($rm_tax[$id]['title']??'');
                        $cur_d = $use_yoast ? ($yoast_tax[$tax][$id]['wpseo_desc']??'')  : ($rm_tax[$id]['description']??'');
                        $results[]=[ 'type'=>'term','id'=>$id,'taxonomy'=>$tax,'status'=>($cur_t===$title&&$cur_d===$desc)?'already_set':'would_update','dry_run'=>true ];
                    } else {
                        if ($use_yoast) {
                            wseb_backup_option('wpseo_taxonomy_meta');
                            if (!isset($yoast_tax[$tax]))      $yoast_tax[$tax]=[];
                            if (!isset($yoast_tax[$tax][$id])) $yoast_tax[$tax][$id]=[];
                            $yoast_tax[$tax][$id]['wpseo_title'] = $title;
                            $yoast_tax[$tax][$id]['wpseo_desc']  = $desc;
                            $tax_dirty_y = true;
                            $results[]=[ 'type'=>'term','id'=>$id,'taxonomy'=>$tax,'status'=>'queued' ];
                        } else {
                            wseb_backup_option('rank_math_term_meta');
                            if (!isset($rm_tax[$id])) $rm_tax[$id]=[];
                            $rm_tax[$id]['title']       = $title;
                            $rm_tax[$id]['description'] = $desc;
                            $tax_dirty_r = true;
                            $results[]=[ 'type'=>'term','id'=>$id,'taxonomy'=>$tax,'status'=>'queued' ];
                        }
                    }
                } else {
                    $results[]=[ 'type'=>$type,'status'=>'unknown_type' ];
                }
            }

            if (!$dry_run) {
                if ($tax_dirty_y) {
                    update_option('wpseo_taxonomy_meta',$yoast_tax);
                    $check_y = (array)get_option('wpseo_taxonomy_meta',[]);
                    foreach ($results as &$res) {
                        if ($res['type']==='term'&&$res['status']==='queued') {
                            $tid=$res['id'];$tx=$res['taxonomy'];
                            $ue=null; foreach($updates as $u){if((int)($u['id']??0)===$tid&&($u['taxonomy']??'')===$tx){$ue=$u;break;}}
                            if($ue){$st=(string)($check_y[$tx][$tid]['wpseo_title']??'');$sd=(string)($check_y[$tx][$tid]['wpseo_desc']??'');$res['status']=($st===$ue['title']&&$sd===$ue['description'])?'updated':'write_failed';}
                        }
                    }unset($res);
                }
                if ($tax_dirty_r) {
                    update_option('rank_math_term_meta',$rm_tax);
                    foreach ($results as &$res) {
                        if ($res['type']==='term'&&$res['status']==='queued') $res['status']='updated';
                    }unset($res);
                }
            }

            $ok  = count(array_filter($results,fn($x)=>in_array($x['status'],['updated','already_set','would_update'])));
            $bad = count($results)-$ok;
            $out = ['dry_run'=>$dry_run,'seo_plugin_used'=>$use_yoast?'yoast':'rankmath','summary'=>['total'=>count($results),'ok'=>$ok,'failed'=>$bad],'results'=>$results];
            wseb_log('update','POST',$r,$out,$bad>0?'warn':'ok');
            if(!$dry_run) wseb_fire_webhook(['event'=>'update','site'=>get_bloginfo('url'),'summary'=>$out['summary']]);
            return rest_ensure_response($out);
        },
    ]);

    // ── POST /schema ─────────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/schema', [
        'methods'             => 'POST',
        'permission_callback' => 'wseb_auth_write',
        'callback'            => function($r) {
            if (!wseb_is_endpoint_enabled('schema')) return wseb_endpoint_disabled();
            $body    = $r->get_json_params();
            $dry_run = !empty($body['dry_run']);
            $hfs     = wseb_detect_hfs_plugin();
            $results = [];

            // Sitewide schema → wp_options
            if (!empty($body['sitewide_schema'])) {
                $schema   = $body['sitewide_schema'];
                $validate = wseb_validate_schema($schema);
                if (!$validate['valid']) {
                    $results['sitewide']=['status'=>'invalid','error'=>$validate['error']];
                } elseif ($dry_run) {
                    $results['sitewide']=['status'=>'would_update','warnings'=>$validate['warnings'],'dry_run'=>true];
                } else {
                    wseb_backup_option('seo_bridge_sitewide_schema');
                    update_option('seo_bridge_sitewide_schema',$schema,false);
                    $saved = get_option('seo_bridge_sitewide_schema','');
                    $results['sitewide']=['status'=>$saved===$schema?'updated':'write_failed','warnings'=>$validate['warnings']];
                }
            }

            // Per-page schemas
            $page_results=[];
            foreach ((array)($body['page_schemas']??[]) as $item) {
                $post_id=(int)($item['post_id']??0);
                $schema=$item['schema']??'';
                if (!$post_id||!$schema){$page_results[]=['post_id'=>$post_id,'status'=>'invalid'];continue;}
                $validate=wseb_validate_schema($schema);
                if (!$validate['valid']){$page_results[]=['post_id'=>$post_id,'status'=>'invalid','error'=>$validate['error']];continue;}
                if ($dry_run){$page_results[]=['post_id'=>$post_id,'status'=>'would_update','warnings'=>$validate['warnings'],'dry_run'=>true];continue;}
                if (!$hfs){$page_results[]=['post_id'=>$post_id,'status'=>'no_hfs_plugin'];continue;}
                wseb_backup_post_meta($post_id,$hfs['meta_key']);
                if ($hfs['type']==='serialized_array') {
                    $data=get_post_meta($post_id,$hfs['meta_key'],true);
                    if (!is_array($data)) $data=[];
                    $existing=preg_replace('/<script\s+type=["\']application\/ld\+json["\']>.*?<\/script>/s','',$data[$hfs['array_key']]??'');
                    $data[$hfs['array_key']]=trim($existing)?trim($existing)."\n\n".$schema:$schema;
                    update_post_meta($post_id,$hfs['meta_key'],$data);
                    $check=get_post_meta($post_id,$hfs['meta_key'],true);
                    $ok=is_array($check)&&strpos($check[$hfs['array_key']]??'','application/ld+json')!==false;
                } else {
                    $existing=preg_replace('/<script\s+type=["\']application\/ld\+json["\']>.*?<\/script>/s','',(string)get_post_meta($post_id,$hfs['meta_key'],true));
                    $new=trim($existing)?trim($existing)."\n\n".$schema:$schema;
                    update_post_meta($post_id,$hfs['meta_key'],$new);
                    $ok=strpos((string)get_post_meta($post_id,$hfs['meta_key'],true),'application/ld+json')!==false;
                }
                $page_results[]=['post_id'=>$post_id,'status'=>$ok?'updated':'write_failed','warnings'=>$validate['warnings']];
            }
            $results['pages']=$page_results;

            // Term schemas → wp_options
            $term_results=[];
            foreach ((array)($body['term_schemas']??[]) as $item) {
                $term_id=(int)($item['term_id']??0);
                $taxonomy=sanitize_text_field($item['taxonomy']??'');
                $schema=$item['schema']??'';
                if (!$term_id||!$taxonomy||!$schema){$term_results[]=['term_id'=>$term_id,'status'=>'invalid'];continue;}
                $validate=wseb_validate_schema($schema);
                if (!$validate['valid']){$term_results[]=['term_id'=>$term_id,'status'=>'invalid','error'=>$validate['error']];continue;}
                if ($dry_run){$term_results[]=['term_id'=>$term_id,'taxonomy'=>$taxonomy,'status'=>'would_update','warnings'=>$validate['warnings'],'dry_run'=>true];continue;}
                $key='seo_bridge_term_schema_'.$taxonomy.'_'.$term_id;
                wseb_backup_option($key);
                update_option($key,$schema,false);
                $saved=get_option($key,'');
                $term_results[]=['term_id'=>$term_id,'taxonomy'=>$taxonomy,'status'=>$saved===$schema?'updated':'write_failed','warnings'=>$validate['warnings']];
            }
            $results['terms']=$term_results;

            $all=array_merge($page_results,$term_results);
            $ok_count=count(array_filter($all,fn($x)=>in_array($x['status'],['updated','would_update'])));
            $out=['dry_run'=>$dry_run,'hfs_plugin'=>$hfs?$hfs['name']:null,'summary'=>['total'=>count($all),'ok'=>$ok_count,'failed'=>count($all)-$ok_count],'results'=>$results];
            wseb_log('schema','POST',$r,$out,count($all)-$ok_count>0?'warn':'ok');
            if(!$dry_run) wseb_fire_webhook(['event'=>'schema','site'=>get_bloginfo('url'),'summary'=>$out['summary']]);
            return rest_ensure_response($out);
        },
    ]);

    // ── GET+POST /options ────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/options', [
        'methods'             => 'GET',
        'permission_callback' => 'wseb_auth_admin',
        'callback'            => function($r) {
            if (!wseb_is_endpoint_enabled('options')) return wseb_endpoint_disabled();
            $keys   = (array)($r->get_param('keys')??[]);
            $out    = [];
            foreach ($keys as $k) { $out[sanitize_key($k)] = get_option(sanitize_key($k)); }
            wseb_log('options','GET',$r,['keys'=>$keys]);
            return rest_ensure_response($out);
        },
    ]);
    register_rest_route( WSEB_NAMESPACE, '/options', [
        'methods'             => 'POST',
        'permission_callback' => 'wseb_auth_admin',
        'callback'            => function($r) {
            if (!wseb_is_endpoint_enabled('options')) return wseb_endpoint_disabled();
            $body    = $r->get_json_params();
            $options = $body['options']??[];
            $results = [];
            foreach ($options as $k=>$v) {
                $k = sanitize_key($k);
                wseb_backup_option($k);
                update_option($k,$v,false);
                $results[$k]=['status'=>'updated'];
            }
            wseb_log('options','POST',$r,$results);
            wseb_fire_webhook(['event'=>'options','site'=>get_bloginfo('url'),'keys'=>array_keys($results)]);
            return rest_ensure_response(['results'=>$results]);
        },
    ]);

    // ── POST /purge-cache ────────────────────────────────
    register_rest_route( WSEB_NAMESPACE, '/purge-cache', [
        'methods'             => 'POST',
        'permission_callback' => 'wseb_auth_write',
        'callback'            => function($r) {
            if (!wseb_is_endpoint_enabled('purge_cache')) return wseb_endpoint_disabled();
            $purged=[];
            // LiteSpeed Cache
            if (class_exists('LiteSpeed_Cache_API')) { LiteSpeed_Cache_API::purge_all(); $purged[]='litespeed'; }
            elseif (function_exists('lscwp_purge_all')) { lscwp_purge_all(); $purged[]='litespeed'; }
            // WP Rocket
            if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); $purged[]='wp_rocket'; }
            // W3 Total Cache
            if (function_exists('w3tc_pgcache_flush')) { w3tc_pgcache_flush(); $purged[]='w3tc'; }
            // WP Super Cache
            if (function_exists('wp_cache_clear_cache')) { wp_cache_clear_cache(); $purged[]='wp_super_cache'; }
            // Generic: delete transients
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
            $out=['purged_plugins'=>$purged,'transients_cleared'=>true];
            wseb_log('purge-cache','POST',$r,$out);
            wseb_fire_webhook(['event'=>'cache_purged','site'=>get_bloginfo('url'),'purged'=>$purged]);
            return rest_ensure_response($out);
        },
    ]);

}); // end rest_api_init part 2

// ─── WP HEAD: INJECT TERM SCHEMA ─────────────────────────────────────────────
add_action('wp_head', function() {
    if (!is_tax()&&!is_category()&&!is_tag()) return;
    $term = get_queried_object();
    if (!$term||!isset($term->term_id,$term->taxonomy)) return;
    $schema = get_option('seo_bridge_term_schema_'.$term->taxonomy.'_'.$term->term_id,'');
    if ($schema) echo "\n".$schema."\n";
}, 2);

