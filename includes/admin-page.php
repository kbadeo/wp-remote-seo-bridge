<?php
/**
 * The Settings > Remote SEO Bridge admin page (settings tab).
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =============================================================================
// ADMIN PAGE
// =============================================================================

add_action( 'admin_menu', function () {
    add_options_page(
        'Remote SEO Bridge',
        'Remote SEO Bridge',
        'manage_options',
        'wseb-settings',
        'wseb_admin_page'
    );
} );

function wseb_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    $s = wseb_settings();

    // Handle ACF field group create/sync
    if ( isset( $_POST['wseb_sync_acf'] ) && check_admin_referer( 'wseb_save' ) ) {
        if ( wseb_acf_active() ) {
            wseb_sync_acf_term_schema_field();
            echo '<div class="notice notice-success"><p>ACF field "<code>' . esc_html( wseb_term_schema_field() ) . '</code>" created/synced for all public taxonomies.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>ACF is not active on this site. Install/activate Advanced Custom Fields first.</p></div>';
        }
    }

    // Handle llms.txt enable/disable toggle (runs through the detected SEO
    // plugin's own settings — no REST payload needed for the common case)
    if ( isset( $_POST['wseb_toggle_llms'] ) && check_admin_referer( 'wseb_save' ) ) {
        $current     = wseb_get_llms_settings();
        $new_enabled = empty( $current['enabled'] );
        $plugin      = wseb_set_llms_settings( [ 'enabled' => $new_enabled ] );
        if ( $plugin === 'none' ) {
            echo '<div class="notice notice-error"><p>No supported SEO plugin (Yoast or Rank Math) detected.</p></div>';
        } else {
            echo '<div class="notice notice-success"><p>llms.txt ' . ( $new_enabled ? 'enabled' : 'disabled' ) . ' for <strong>' . esc_html( $plugin ) . '</strong>.</p></div>';
        }
    }

    // Handle save
    if ( isset( $_POST['wseb_save'] ) && check_admin_referer( 'wseb_save' ) ) {
        if ( isset( $_POST['wseb_regenerate'] ) ) {
            $s['token']         = wp_generate_password( 40, false );
            $s['token_created'] = time();
        }
        $s['expiry_hours']      = max( 0, intval( $_POST['expiry_hours'] ?? 0 ) );
        $s['webhook_url']       = esc_url_raw( $_POST['webhook_url'] ?? '' );
        $s['term_schema_field'] = sanitize_key( $_POST['term_schema_field'] ?? 'header_script' ) ?: 'header_script';
        $eps = [ 'ping','health','scan','scan_terms','meta','meta_terms','llms_settings','llms_txt','schema','sitewide_schema','purge_cache' ];
        foreach ( $eps as $ep ) {
            $s[ 'ep_' . $ep ] = ! empty( $_POST[ 'ep_' . $ep ] ) ? 1 : 0;
        }
        update_option( WSEB_OPTION, $s );
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        $s = wseb_settings();
    }

    $token     = $s['token'] ?? '';
    $base      = get_rest_url( null, WSEB_NS );
    $seo_found = wseb_detect_seo_plugin();
    $tab       = in_array( $_GET['tab'] ?? '', [ 'settings', 'exporter' ], true ) ? $_GET['tab'] : 'settings';

    ?>
    <div class="wrap">
        <h1>WP Remote SEO Bridge <span style="font-size:13px;color:#999;">v<?php echo WSEB_VERSION; ?></span></h1>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wseb-settings&tab=settings' ) ); ?>" class="nav-tab<?php echo $tab === 'settings' ? ' nav-tab-active' : ''; ?>">Settings</a>
            <a href="<?php echo esc_url( admin_url( 'options-general.php?page=wseb-settings&tab=exporter' ) ); ?>" class="nav-tab<?php echo $tab === 'exporter' ? ' nav-tab-active' : ''; ?>">SEO Exporter</a>
        </h2>

        <?php if ( $tab === 'settings' ) : ?>

        <p>Detected SEO plugin: <strong><?php echo esc_html( $seo_found ); ?></strong> &nbsp;|&nbsp; Base URL: <code><?php echo esc_url( $base ); ?></code></p>

        <table class="form-table">
            <tr><th>API Token</th><td>
                <code style="font-size:13px;user-select:all;"><?php echo esc_html( $token ); ?></code>
                <p class="description">Send in request header: <code>X-WSEB-Token: <?php echo esc_html( $token ); ?></code></p>
            </td></tr>
        </table>

        <form method="post">
            <?php wp_nonce_field( 'wseb_save' ); ?>
            <table class="form-table">
                <tr><th>Regenerate Token</th><td>
                    <label><input type="checkbox" name="wseb_regenerate" value="1"> Yes, generate a new token on save</label>
                </td></tr>
                <tr><th>Token Expiry (hours)</th><td>
                    <input type="number" name="expiry_hours" value="<?php echo esc_attr( $s['expiry_hours'] ?? 0 ); ?>" min="0" style="width:80px;">
                    <p class="description">0 = never expires.</p>
                </td></tr>
                <tr><th>Webhook URL</th><td>
                    <input type="url" name="webhook_url" value="<?php echo esc_attr( $s['webhook_url'] ?? '' ); ?>" style="width:420px;">
                    <p class="description">Optional. Receives a POST notification on each write.</p>
                </td></tr>
                <tr><th>Term Schema ACF Field</th><td>
                    <input type="text" name="term_schema_field" value="<?php echo esc_attr( $s['term_schema_field'] ?? 'header_script' ); ?>" style="width:220px;">
                    <p class="description">ACF field name on taxonomy terms (categories, product categories, etc.) that <code>/schema</code> writes to when <code>term_id</code>/<code>taxonomy</code> are sent instead of <code>post_id</code>. HFS plugins don't attach to term pages, so schema for terms is pushed here instead.</p>
                </td></tr>
                <tr><th>Enabled Endpoints</th><td>
                    <?php
                    $eps = [
                        'ping'            => '/ping — health check',
                        'health'          => '/health — environment info',
                        'scan'            => '/scan — list all posts/pages/products',
                        'scan_terms'      => '/scan-terms — list all taxonomy terms',
                        'meta'            => '/meta — write SEO meta to posts/products',
                        'meta_terms'      => '/meta-terms — write SEO meta to taxonomy terms',
                        'llms_settings'   => '/llms-settings — read/update the SEO plugin\'s llms.txt config',
                        'llms_txt'        => '/llms-txt — push a fully custom llms.txt file, bypassing the SEO plugin',
                        'schema'          => '/schema — inject per-page schema',
                        'sitewide_schema' => '/sitewide-schema — inject global schema',
                        'purge_cache'     => '/purge-cache — clear site cache',
                    ];
                    foreach ( $eps as $key => $label ) :
                    ?>
                        <label style="display:block;margin-bottom:5px;">
                            <input type="checkbox" name="ep_<?php echo $key; ?>" value="1" <?php checked( ! empty( $s[ 'ep_' . $key ] ) ); ?>>
                            <code>/<?php echo str_replace( '_', '-', $key ); ?></code> — <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </td></tr>
            </table>
            <p class="submit">
                <input type="submit" name="wseb_save" class="button button-primary" value="Save Settings">
            </p>
        </form>

        <h2>llms.txt (AI Visibility)</h2>
        <div class="postbox" style="padding:16px;max-width:800px;">
            <?php $llms = wseb_get_llms_settings(); ?>
            <p>
                Detected SEO plugin: <strong><?php echo esc_html( $llms['seo_plugin'] ); ?></strong>
                &nbsp;|&nbsp; llms.txt: <strong><?php echo ! empty( $llms['enabled'] ) ? '<span style="color:#1a6b3a">Enabled</span>' : '<span style="color:#c0392b">Disabled</span>'; ?></strong>
            </p>
            <?php if ( $llms['seo_plugin'] === 'rankmath' ) : ?>
                <p class="description" style="max-width:640px;">
                    Post types: <code><?php echo esc_html( implode( ', ', $llms['post_types'] ?: [ 'none' ] ) ); ?></code>
                    &nbsp;|&nbsp; Taxonomies: <code><?php echo esc_html( implode( ', ', $llms['taxonomies'] ?: [ 'none' ] ) ); ?></code>
                    &nbsp;|&nbsp; Limit: <code><?php echo esc_html( $llms['limit'] ); ?></code>
                </p>
            <?php elseif ( $llms['seo_plugin'] === 'yoast' ) : ?>
                <p class="description" style="max-width:640px;">
                    Selection mode: <code><?php echo esc_html( $llms['selection_mode'] ); ?></code>
                </p>
            <?php endif; ?>
            <p class="description" style="max-width:640px;">
                Toggling this runs through the detected plugin's own settings (Yoast's <code>enable_llms_txt</code>, or Rank Math's
                <code>llms-txt</code> module) — the same thing <code>POST /llms-settings</code> does remotely, available here for a
                one-click change. Enabling Rank Math for the first time auto-fills sensible post types/taxonomies, since — unlike
                Yoast — it has no auto mode and would otherwise generate a near-empty file.
            </p>
            <form method="post">
                <?php wp_nonce_field( 'wseb_save' ); ?>
                <input type="submit" name="wseb_toggle_llms" class="button<?php echo empty( $llms['enabled'] ) ? ' button-primary' : ''; ?>"
                    value="<?php echo empty( $llms['enabled'] ) ? 'Enable llms.txt' : 'Disable llms.txt'; ?>"
                    <?php disabled( $llms['seo_plugin'] === 'none' ); ?>>
                <?php if ( $llms['seo_plugin'] === 'none' ) : ?>
                    <span class="description">&nbsp;No supported SEO plugin (Yoast or Rank Math) detected.</span>
                <?php endif; ?>
            </form>
        </div>

        <h2>Taxonomy Term Schema (ACF)</h2>
        <div class="postbox" style="padding:16px;max-width:800px;">
            <?php
            $acf_active = wseb_acf_active();
            $synced_at  = get_option( 'wseb_acf_field_synced_at' );
            $field_name = wseb_term_schema_field();
            ?>
            <p>
                ACF detected: <strong><?php echo $acf_active ? '<span style="color:#1a6b3a">Yes</span>' : '<span style="color:#c0392b">No</span>'; ?></strong>
                <?php if ( $synced_at ) : ?>
                    &nbsp;|&nbsp; Field last synced: <?php echo esc_html( date( 'Y-m-d H:i:s', $synced_at ) ); ?>
                <?php endif; ?>
            </p>
            <p class="description" style="max-width:640px;">
                Header/Footer Script plugins don't attach to taxonomy term (category, product category, tag, etc.) archive pages,
                so <code>/schema</code> writes term schema into an ACF field (<code><?php echo esc_html( $field_name ); ?></code>) instead.
                This field group and location rules (one per public taxonomy) are created automatically on plugin activation if ACF
                is already active. If you installed ACF afterwards, or added new taxonomies, use the button below to (re)create/sync it —
                safe to run any time.
            </p>
            <form method="post">
                <?php wp_nonce_field( 'wseb_save' ); ?>
                <input type="submit" name="wseb_sync_acf" class="button" value="Create / Sync ACF Field"
                    <?php disabled( ! $acf_active ); ?>>
                <?php if ( ! $acf_active ) : ?>
                    <span class="description">&nbsp;Install and activate Advanced Custom Fields to enable this.</span>
                <?php endif; ?>
            </form>

            <p style="margin-top:16px;"><strong>Front-end output hook</strong></p>
            <p class="description" style="max-width:640px;">
                Every site's theme is wired up differently, so this isn't injected automatically — paste the snippet below into your
                theme's <code>functions.php</code> (or a snippets plugin) wherever it makes sense for your build, to output the pushed
                schema in <code>&lt;head&gt;</code> on term archive pages.
            </p>
            <textarea readonly rows="12" style="width:100%;font-family:monospace;font-size:12px;" onclick="this.select()"><?php
                echo esc_textarea(
"add_action( 'wp_head', function () {
    if ( ! is_tax() && ! is_category() && ! is_tag() ) return;
    \$term = get_queried_object();
    if ( ! \$term || empty( \$term->term_id ) ) return;
    \$schema = function_exists( 'get_field' )
        ? get_field( '{$field_name}', \$term->taxonomy . '_' . \$term->term_id )
        : get_term_meta( \$term->term_id, '{$field_name}', true );
    if ( \$schema ) echo \"\\n\" . \$schema . \"\\n\";
}, 5 );"
                );
            ?></textarea>
        </div>

        <h2>Audit Log (last 50)</h2>
        <?php
        global $wpdb;
        $table = $wpdb->prefix . WSEB_LOG_TABLE;
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table ) {
            $rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 50" );
            if ( $rows ) {
                echo '<table class="widefat striped" style="margin-top:10px;"><thead><tr>';
                echo '<th>Time</th><th>Endpoint</th><th>Method</th><th>Status</th><th>IP</th><th>Note</th>';
                echo '</tr></thead><tbody>';
                foreach ( $rows as $r ) {
                    printf(
                        '<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                        esc_html( $r->created_at ), esc_html( $r->endpoint ),
                        esc_html( $r->method ),     esc_html( $r->status ),
                        esc_html( $r->ip ),         esc_html( $r->note )
                    );
                }
                echo '</tbody></table>';
            } else {
                echo '<p>No log entries yet.</p>';
            }
        }
        ?>

        <?php endif; // $tab === 'settings' ?>

        <?php if ( $tab === 'exporter' ) : ?>
            <?php wseb_render_exporter_tab(); ?>
        <?php endif; ?>

    </div>
    <?php
}
