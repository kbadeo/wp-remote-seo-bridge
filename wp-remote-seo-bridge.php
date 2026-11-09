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
 * Version:      1.9.2
 * Author:       Karl Badeo
 * License:      MIT
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSEB_VERSION',   '1.9.2' );
define( 'WSEB_OPTION',    'wseb_settings' );
define( 'WSEB_NS',        'wseb/v1' );
define( 'WSEB_LOG_TABLE', 'wseb_audit_log' );

// =============================================================================
// ACTIVATION
// =============================================================================

register_activation_hook( __FILE__, 'wseb_activate' );
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

// =============================================================================
// SEO PLUGIN DETECTION
// =============================================================================

function wseb_detect_seo_plugin() {
    // An ACTIVE plugin (its version constant is defined) always wins. Leftover options from a plugin that was
    // deactivated/removed must not win just because they are checked first - that mis-detected Yoast sites as Rank Math.
    if ( defined( 'RANK_MATH_VERSION' ) ) return 'rankmath';
    if ( defined( 'WPSEO_VERSION' ) )     return 'yoast';
    // Nothing active: fall back to leftover options so reads of old data still work.
    if ( get_option( 'rank-math-options-general' ) !== false ) return 'rankmath';
    if ( get_option( 'wpseo' )                     !== false ) return 'yoast';
    return 'none';
}

// =============================================================================
// POST META READ / WRITE
// =============================================================================

function wseb_get_post_seo( $post_id ) {
    $seo = wseb_detect_seo_plugin();
    if ( $seo === 'rankmath' ) {
        return [
            'seo_plugin'  => 'rankmath',
            'title'       => get_post_meta( $post_id, 'rank_math_title', true ),
            'description' => get_post_meta( $post_id, 'rank_math_description', true ),
            'focus_kw'    => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
        ];
    }
    if ( $seo === 'yoast' ) {
        return [
            'seo_plugin'  => 'yoast',
            'title'       => get_post_meta( $post_id, '_yoast_wpseo_title', true ),
            'description' => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
            'focus_kw'    => get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
        ];
    }
    return [ 'seo_plugin' => 'none', 'title' => '', 'description' => '', 'focus_kw' => '' ];
}

function wseb_set_post_seo( $post_id, $title = null, $description = null, $focus_kw = null ) {
    $seo = wseb_detect_seo_plugin();
    if ( $seo === 'rankmath' ) {
        if ( $title       !== null ) update_post_meta( $post_id, 'rank_math_title',          $title );
        if ( $description !== null ) update_post_meta( $post_id, 'rank_math_description',    $description );
        if ( $focus_kw    !== null ) update_post_meta( $post_id, 'rank_math_focus_keyword',  $focus_kw );
        return 'rankmath';
    }
    if ( $seo === 'yoast' ) {
        if ( $title       !== null ) update_post_meta( $post_id, '_yoast_wpseo_title',    $title );
        if ( $description !== null ) update_post_meta( $post_id, '_yoast_wpseo_metadesc', $description );
        if ( $focus_kw    !== null ) update_post_meta( $post_id, '_yoast_wpseo_focuskw',  $focus_kw );
        return 'yoast';
    }
    return 'none';
}

// =============================================================================
// TAXONOMY TERM META READ / WRITE
// =============================================================================

/**
 * Get SEO meta for a taxonomy term.
 * Yoast stores all term meta in a single wp_options key: wpseo_taxonomy_meta
 * Rank Math stores it in: rank_math_term_meta
 */
function wseb_get_term_seo( $term_id, $taxonomy ) {
    $seo = wseb_detect_seo_plugin();

    if ( $seo === 'rankmath' ) {
        $all  = (array) get_option( 'rank_math_term_meta', [] );
        $data = $all[ $term_id ] ?? [];
        return [
            'seo_plugin'  => 'rankmath',
            'title'       => $data['rank_math_title']          ?? '',
            'description' => $data['rank_math_description']    ?? '',
            'focus_kw'    => $data['rank_math_focus_keyword']  ?? '',
        ];
    }

    if ( $seo === 'yoast' ) {
        $all  = (array) get_option( 'wpseo_taxonomy_meta', [] );
        $data = $all[ $taxonomy ][ $term_id ] ?? [];
        return [
            'seo_plugin'  => 'yoast',
            'title'       => $data['wpseo_title']   ?? '',
            'description' => $data['wpseo_desc']    ?? '',
            'focus_kw'    => $data['wpseo_focuskw'] ?? '',
        ];
    }

    return [ 'seo_plugin' => 'none', 'title' => '', 'description' => '', 'focus_kw' => '' ];
}

/**
 * Write SEO meta for a taxonomy term.
 */
function wseb_set_term_seo( $term_id, $taxonomy, $title = null, $description = null, $focus_kw = null ) {
    $seo = wseb_detect_seo_plugin();

    if ( $seo === 'rankmath' ) {
        $all = (array) get_option( 'rank_math_term_meta', [] );
        if ( ! isset( $all[ $term_id ] ) ) $all[ $term_id ] = [];
        if ( $title       !== null ) $all[ $term_id ]['rank_math_title']         = $title;
        if ( $description !== null ) $all[ $term_id ]['rank_math_description']   = $description;
        if ( $focus_kw    !== null ) $all[ $term_id ]['rank_math_focus_keyword'] = $focus_kw;
        update_option( 'rank_math_term_meta', $all );
        return 'rankmath';
    }

    if ( $seo === 'yoast' ) {
        $all = (array) get_option( 'wpseo_taxonomy_meta', [] );
        if ( ! isset( $all[ $taxonomy ] ) )           $all[ $taxonomy ] = [];
        if ( ! isset( $all[ $taxonomy ][ $term_id ] ) ) $all[ $taxonomy ][ $term_id ] = [];
        if ( $title       !== null ) $all[ $taxonomy ][ $term_id ]['wpseo_title']   = $title;
        if ( $description !== null ) $all[ $taxonomy ][ $term_id ]['wpseo_desc']    = $description;
        if ( $focus_kw    !== null ) $all[ $taxonomy ][ $term_id ]['wpseo_focuskw'] = $focus_kw;
        update_option( 'wpseo_taxonomy_meta', $all );
        return 'yoast';
    }

    return 'none';
}

// =============================================================================
// LLMS.TXT SETTINGS (Yoast SEO / Rank Math)
// =============================================================================

/**
 * Yoast stores the llms.txt on/off flag as 'enable_llms_txt' inside the
 * single-row 'wpseo' option, and page-selection settings in 'wpseo_llmstxt'.
 * Rank Math stores the on/off flag as module id 'llms-txt' in the
 * 'rank_math_modules' list, and its own field settings in the
 * 'rank-math-options-general' option (note the hyphens — this is the actual
 * CMB2 options-page key registered in RankMath\Settings::__construct(), not
 * the more guessable 'rank_math_general').
 * Writing via update_option() (rather than the plugins' internal option
 * helpers) is sufficient — both plugins react to the standard
 * 'update_option_{name}' hooks to regenerate/remove the actual llms.txt file.
 */
function wseb_get_llms_settings() {
    $seo = wseb_detect_seo_plugin();

    if ( $seo === 'yoast' ) {
        $wpseo   = (array) get_option( 'wpseo', [] );
        $llmstxt = (array) get_option( 'wpseo_llmstxt', [] );
        return [
            'seo_plugin'     => 'yoast',
            'enabled'        => ! empty( $wpseo['enable_llms_txt'] ),
            'selection_mode' => $llmstxt['llms_txt_selection_mode']  ?? 'auto',
            'pages'          => [
                'about_us_page'        => $llmstxt['about_us_page']        ?? null,
                'contact_page'         => $llmstxt['contact_page']         ?? null,
                'terms_page'           => $llmstxt['terms_page']           ?? null,
                'privacy_policy_page'  => $llmstxt['privacy_policy_page']  ?? null,
                'shop_page'            => $llmstxt['shop_page']            ?? null,
                'other_included_pages' => $llmstxt['other_included_pages'] ?? [],
            ],
        ];
    }

    if ( $seo === 'rankmath' ) {
        $general = (array) get_option( 'rank-math-options-general', [] );
        $modules = (array) get_option( 'rank_math_modules', [] );
        return [
            'seo_plugin'    => 'rankmath',
            'enabled'       => in_array( 'llms-txt', $modules, true ),
            'post_types'    => $general['llms_post_types']    ?? [],
            'taxonomies'    => $general['llms_taxonomies']    ?? [],
            'limit'         => $general['llms_limit']         ?? 50,
            'extra_content' => $general['llms_extra_content'] ?? '',
        ];
    }

    return [ 'seo_plugin' => 'none', 'enabled' => false ];
}

function wseb_set_llms_settings( $body ) {
    $seo = wseb_detect_seo_plugin();

    if ( $seo === 'yoast' ) {
        if ( isset( $body['enabled'] ) ) {
            $wpseo = (array) get_option( 'wpseo', [] );
            $wpseo['enable_llms_txt'] = (bool) $body['enabled'];
            update_option( 'wpseo', $wpseo );
        }

        $llmstxt = (array) get_option( 'wpseo_llmstxt', [] );
        $dirty   = false;
        if ( isset( $body['selection_mode'] ) ) {
            $llmstxt['llms_txt_selection_mode'] = sanitize_key( $body['selection_mode'] );
            $dirty = true;
        }
        if ( isset( $body['pages'] ) && is_array( $body['pages'] ) ) {
            foreach ( [ 'about_us_page', 'contact_page', 'terms_page', 'privacy_policy_page', 'shop_page' ] as $key ) {
                if ( isset( $body['pages'][ $key ] ) ) {
                    $llmstxt[ $key ] = absint( $body['pages'][ $key ] );
                    $dirty = true;
                }
            }
            if ( isset( $body['pages']['other_included_pages'] ) && is_array( $body['pages']['other_included_pages'] ) ) {
                $llmstxt['other_included_pages'] = array_map( 'absint', $body['pages']['other_included_pages'] );
                $dirty = true;
            }
        }
        if ( $dirty ) update_option( 'wpseo_llmstxt', $llmstxt );

        return 'yoast';
    }

    if ( $seo === 'rankmath' ) {
        if ( isset( $body['enabled'] ) ) {
            $modules = (array) get_option( 'rank_math_modules', [] );
            $is_on   = in_array( 'llms-txt', $modules, true );
            if ( $body['enabled'] && ! $is_on ) {
                $modules[] = 'llms-txt';
                update_option( 'rank_math_modules', array_values( array_unique( $modules ) ) );
            } elseif ( ! $body['enabled'] && $is_on ) {
                $modules = array_diff( $modules, [ 'llms-txt' ] );
                update_option( 'rank_math_modules', array_values( $modules ) );
            }
        }

        $general = (array) get_option( 'rank-math-options-general', [] );
        $dirty   = false;

        // Unlike Yoast, Rank Math has no "auto" mode — add_post_types_data()
        // and add_taxonomies_data() silently skip any section left
        // unconfigured. Seed sensible defaults on first enable (unless the
        // caller explicitly set their own) so the file isn't just a bare
        // header + sitemap link.
        $enabling_now      = isset( $body['enabled'] ) && $body['enabled'] === true;
        $no_explicit_types = ! isset( $body['post_types'] ) && ! isset( $body['taxonomies'] );
        if ( $enabling_now && $no_explicit_types && empty( $general['llms_post_types'] ) ) {
            $public_post_types = get_post_types( [ 'public' => true ], 'names' );
            $public_taxonomies = get_taxonomies( [ 'public' => true ], 'names' );
            $general['llms_post_types'] = array_values( array_intersect( [ 'post', 'page', 'product' ], $public_post_types ) );
            $general['llms_taxonomies'] = array_values( array_intersect( [ 'category', 'product_cat' ], $public_taxonomies ) );
            $dirty = true;
        }

        if ( isset( $body['post_types'] ) && is_array( $body['post_types'] ) ) {
            $general['llms_post_types'] = array_map( 'sanitize_key', $body['post_types'] );
            $dirty = true;
        }
        if ( isset( $body['taxonomies'] ) && is_array( $body['taxonomies'] ) ) {
            $general['llms_taxonomies'] = array_map( 'sanitize_key', $body['taxonomies'] );
            $dirty = true;
        }
        if ( isset( $body['limit'] ) ) {
            $general['llms_limit'] = max( 1, absint( $body['limit'] ) );
            $dirty = true;
        }
        if ( isset( $body['extra_content'] ) ) {
            $general['llms_extra_content'] = sanitize_textarea_field( $body['extra_content'] );
            $dirty = true;
        }
        if ( $dirty ) update_option( 'rank-math-options-general', $general );

        return 'rankmath';
    }

    return 'none';
}

// =============================================================================
// CUSTOM LLMS.TXT (raw file push — bypasses Yoast/Rank Math generation)
// =============================================================================

/**
 * Writing a real llms.txt file at the site root wins regardless of which SEO
 * plugin (if any) is active: standard WP .htaccess/nginx rewrite rules always
 * serve a physical file directly, before WordPress's own rewrite dispatch is
 * ever invoked — so Rank Math's dynamically-served /llms.txt is pre-empted.
 * Yoast is even more explicit about it: it hashes the file content it wrote
 * and, on a later regeneration pass, backs off entirely if the file on disk
 * no longer matches that hash (i.e. someone/something else edited it) rather
 * than overwriting it. We also proactively flip 'enabled' off via
 * wseb_set_llms_settings() after a successful write so neither plugin's
 * cron/UI shows a stale "managed by us" state.
 */

function wseb_llms_txt_path() {
    return trailingslashit( ABSPATH ) . 'llms.txt';
}

function wseb_init_filesystem() {
    global $wp_filesystem;
    if ( ! $wp_filesystem ) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        WP_Filesystem();
    }
    return $wp_filesystem;
}

function wseb_read_llms_txt() {
    $fs   = wseb_init_filesystem();
    $path = wseb_llms_txt_path();
    if ( ! $fs || ! $fs->exists( $path ) ) return '';
    return (string) $fs->get_contents( $path );
}

function wseb_backup_llms_txt() {
    $current = wseb_read_llms_txt();
    if ( ! $current ) return;
    $history = (array) get_option( 'wseb_llms_txt_backup', [] );
    array_unshift( $history, [ 'time' => time(), 'content' => $current ] );
    $history = array_slice( $history, 0, 5 );
    update_option( 'wseb_llms_txt_backup', $history, false );
}

function wseb_write_llms_txt( $content ) {
    $fs = wseb_init_filesystem();
    if ( ! $fs ) return false;

    wseb_backup_llms_txt();

    $path = wseb_llms_txt_path();
    $ok   = $fs->put_contents( $path, trim( $content ) . "\n", FS_CHMOD_FILE );

    if ( $ok ) {
        // Stop the SEO plugin from fighting for control of this file.
        wseb_set_llms_settings( [ 'enabled' => false ] );
    }

    return (bool) $ok;
}

// =============================================================================
// SCHEMA HELPERS (Anand Kumar Header Footer Scripts)
// =============================================================================

function wseb_read_post_schema( $post_id ) {
    // Check shfs_all_post_meta first, fall back to _inpost_head_script
    $data = get_post_meta( $post_id, 'shfs_all_post_meta', true );
    if ( is_array( $data ) && ! empty( $data['synth_header_script'] ) ) {
        return $data['synth_header_script'];
    }
    $data2 = get_post_meta( $post_id, '_inpost_head_script', true );
    if ( is_array( $data2 ) && ! empty( $data2['synth_header_script'] ) ) {
        return $data2['synth_header_script'];
    }
    return '';
}

function wseb_backup_post_schema( $post_id ) {
    // Keep last 5 schema values per post for recovery
    $current = wseb_read_post_schema( $post_id );
    if ( ! $current ) return;
    $key     = 'wseb_schema_backup_' . $post_id;
    $history = (array) get_option( $key, [] );
    array_unshift( $history, [ 'time' => time(), 'schema' => $current ] );
    $history = array_slice( $history, 0, 5 );
    update_option( $key, $history, false );
}

function wseb_write_post_schema( $post_id, $schema_html ) {
    // Backup existing schema before overwriting
    wseb_backup_post_schema( $post_id );

    $schema_html = trim( $schema_html );

    // Write to shfs_all_post_meta (what the bridge reads/writes)
    // WordPress auto-serializes arrays on update_post_meta — never call serialize() manually
    $data = get_post_meta( $post_id, 'shfs_all_post_meta', true );
    if ( ! is_array( $data ) ) $data = [];
    $existing = $data['synth_header_script'] ?? '';
    $existing = preg_replace( '/<script\s+type=["\'\']application\/ld\+json["\'\'][^>]*>.*?<\/script>/si', '', $existing );
    $data['synth_header_script'] = $schema_html;
    update_post_meta( $post_id, 'shfs_all_post_meta', $data );

    // Also write to _inpost_head_script so it is visible in the HFS admin UI metabox
    $data2 = get_post_meta( $post_id, '_inpost_head_script', true );
    if ( ! is_array( $data2 ) ) $data2 = [];
    $existing2 = $data2['synth_header_script'] ?? '';
    $existing2 = preg_replace( '/<script\s+type=["\'\']application\/ld\+json["\'\'][^>]*>.*?<\/script>/si', '', $existing2 );
    $data2['synth_header_script'] = $schema_html;
    update_post_meta( $post_id, '_inpost_head_script', $data2 );
}

// =============================================================================
// SCHEMA HELPERS (Taxonomy Terms — via ACF field, since HFS plugins don't
// attach to term/category pages)
// =============================================================================

function wseb_term_schema_field() {
    $s = wseb_settings();
    return ! empty( $s['term_schema_field'] ) ? $s['term_schema_field'] : 'header_script';
}

function wseb_acf_active() {
    return function_exists( 'acf_import_field_group' );
}

/**
 * Creates (or re-syncs) the ACF field group backing the term schema field,
 * with a location rule for every currently registered public taxonomy.
 * Safe to call repeatedly — acf_import_field_group() matches on 'key' and
 * updates the existing field group rather than duplicating it.
 */
function wseb_sync_acf_term_schema_field() {
    if ( ! wseb_acf_active() ) return false;

    $field_name = wseb_term_schema_field();
    $taxonomies = get_taxonomies( [ 'public' => true ], 'names' );
    if ( empty( $taxonomies ) ) return false;

    $locations = [];
    foreach ( $taxonomies as $tax ) {
        $locations[] = [
            [
                'param'    => 'taxonomy',
                'operator' => '==',
                'value'    => $tax,
            ],
        ];
    }

    acf_import_field_group( [
        'key'        => 'group_wseb_term_schema',
        'title'      => 'WP Remote SEO Bridge — Term Schema',
        'fields'     => [
            [
                'key'          => 'field_wseb_term_schema',
                'label'        => 'Header Script / Schema (JSON-LD)',
                'name'         => $field_name,
                'type'         => 'textarea',
                'instructions' => 'Populated remotely by WP Remote SEO Bridge. Output this in your theme\'s <head> for this term\'s archive page — see the snippet on the Remote SEO Bridge settings page.',
                'rows'         => 8,
            ],
        ],
        'location'   => $locations,
        'menu_order' => 0,
        'position'   => 'normal',
        'style'      => 'default',
        'active'     => true,
    ] );

    update_option( 'wseb_acf_field_synced_at', time(), false );
    return true;
}

function wseb_read_term_schema( $term_id, $taxonomy ) {
    $field = wseb_term_schema_field();
    if ( function_exists( 'get_field' ) ) {
        return (string) get_field( $field, $taxonomy . '_' . $term_id );
    }
    return (string) get_term_meta( $term_id, $field, true );
}

function wseb_backup_term_schema( $term_id, $taxonomy ) {
    $current = wseb_read_term_schema( $term_id, $taxonomy );
    if ( ! $current ) return;
    $key     = 'wseb_term_schema_backup_' . $taxonomy . '_' . $term_id;
    $history = (array) get_option( $key, [] );
    array_unshift( $history, [ 'time' => time(), 'schema' => $current ] );
    $history = array_slice( $history, 0, 5 );
    update_option( $key, $history, false );
}

function wseb_write_term_schema( $term_id, $taxonomy, $schema_html ) {
    wseb_backup_term_schema( $term_id, $taxonomy );

    $field       = wseb_term_schema_field();
    $schema_html = trim( $schema_html );

    if ( function_exists( 'update_field' ) ) {
        update_field( $field, $schema_html, $taxonomy . '_' . $term_id );
    } else {
        update_term_meta( $term_id, $field, $schema_html );
    }
}

function wseb_detect_hfs_option_key() {
    // All known Anand Kumar HFS global header option keys
    $candidates = [ 'jamify_hfs_insert_header', 'shfs_insert_header', 'hfs_insert_header' ];
    foreach ( $candidates as $k ) {
        if ( get_option( $k ) !== false ) return $k;
    }
    // Not found — default to shfs_insert_header and create it
    return 'shfs_insert_header';
}

function wseb_backup_sitewide_schema() {
    $key     = wseb_detect_hfs_option_key();
    $current = (string) get_option( $key, '' );
    if ( ! $current ) return;
    $history = (array) get_option( 'wseb_sitewide_schema_backup', [] );
    array_unshift( $history, [ 'time' => time(), 'schema' => $current ] );
    $history = array_slice( $history, 0, 5 );
    update_option( 'wseb_sitewide_schema_backup', $history, false );
}

function wseb_write_sitewide_schema( $schema_html ) {
    // Backup before overwriting
    wseb_backup_sitewide_schema();

    $key      = wseb_detect_hfs_option_key();
    $existing = (string) get_option( $key, '' );

    // Strip ALL existing ld+json blocks from global header
    $existing = preg_replace( '/<script\s+type=["\'\']application\/ld\+json["\'\'][^>]*>.*?<\/script>/si', '', $existing );
    $existing = trim( $existing );

    // Write non-schema content + new schema
    $new_value = $existing ? $existing . "\n\n" . trim( $schema_html ) : trim( $schema_html );
    update_option( $key, $new_value );
}

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

// =============================================================================
// SEO EXPORTER (merged from the standalone SEO Data Exporter plugin, plus
// the bridge's own per-post/term/sitewide schema columns)
// =============================================================================

/**
 * Detects the active SEO plugin for export purposes. Broader than
 * wseb_detect_seo_plugin() (adds All in One SEO and a raw-postmeta fallback)
 * since the exporter is read-only and benefits from covering more plugins;
 * the core meta/schema write endpoints intentionally stay narrower.
 */
function wseb_exporter_detect_plugin() {
    if ( defined( 'WPSEO_VERSION' ) )     return 'yoast';
    if ( defined( 'RANK_MATH_VERSION' ) ) return 'rankmath';
    if ( defined( 'AIOSEO_VERSION' ) )    return 'aioseo';
    return 'raw';
}

function wseb_exporter_plugin_name( $slug ) {
    $names = [
        'yoast'    => 'Yoast SEO',
        'rankmath' => 'Rank Math',
        'aioseo'   => 'All in One SEO',
        'raw'      => 'None detected (raw postmeta)',
    ];
    return $names[ $slug ] ?? 'Unknown';
}

function wseb_exporter_empty_fields() {
    return [
        'seo_title' => '', 'seo_description' => '', 'canonical' => '',
        'robots_noindex' => '', 'robots_nofollow' => '',
        'og_title' => '', 'og_description' => '', 'og_image' => '',
        'twitter_title' => '', 'twitter_desc' => '', 'twitter_image' => '',
        'focus_keyword' => '', 'cornerstone' => '',
    ];
}

function wseb_exporter_get_post_seo( $post_id ) {
    $plugin = wseb_exporter_detect_plugin();

    if ( $plugin === 'yoast' ) {
        $robots_noindex = '';
        $meta = get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
        if ( $meta === '1' )      $robots_noindex = 'noindex';
        elseif ( $meta === '2' )  $robots_noindex = 'index';
        return [
            'seo_title'       => get_post_meta( $post_id, '_yoast_wpseo_title', true ),
            'seo_description' => get_post_meta( $post_id, '_yoast_wpseo_metadesc', true ),
            'canonical'       => get_post_meta( $post_id, '_yoast_wpseo_canonical', true ),
            'robots_noindex'  => $robots_noindex,
            'robots_nofollow' => get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true ) === '1' ? 'nofollow' : '',
            'og_title'        => get_post_meta( $post_id, '_yoast_wpseo_opengraph-title', true ),
            'og_description'  => get_post_meta( $post_id, '_yoast_wpseo_opengraph-description', true ),
            'og_image'        => get_post_meta( $post_id, '_yoast_wpseo_opengraph-image', true ),
            'twitter_title'   => get_post_meta( $post_id, '_yoast_wpseo_twitter-title', true ),
            'twitter_desc'    => get_post_meta( $post_id, '_yoast_wpseo_twitter-description', true ),
            'twitter_image'   => get_post_meta( $post_id, '_yoast_wpseo_twitter-image', true ),
            'focus_keyword'   => get_post_meta( $post_id, '_yoast_wpseo_focuskw', true ),
            'cornerstone'     => get_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', true ),
        ];
    }

    if ( $plugin === 'rankmath' ) {
        $robots_raw = get_post_meta( $post_id, 'rank_math_robots', true );
        $robots     = is_array( $robots_raw ) ? implode( ', ', $robots_raw ) : (string) $robots_raw;
        return [
            'seo_title'       => get_post_meta( $post_id, 'rank_math_title', true ),
            'seo_description' => get_post_meta( $post_id, 'rank_math_description', true ),
            'canonical'       => get_post_meta( $post_id, 'rank_math_canonical_url', true ),
            'robots_noindex'  => strpos( $robots, 'noindex' ) !== false ? 'noindex' : '',
            'robots_nofollow' => strpos( $robots, 'nofollow' ) !== false ? 'nofollow' : '',
            'og_title'        => get_post_meta( $post_id, 'rank_math_og_title', true ),
            'og_description'  => get_post_meta( $post_id, 'rank_math_og_description', true ),
            'og_image'        => get_post_meta( $post_id, 'rank_math_og_thumbnail_url', true ),
            'twitter_title'   => get_post_meta( $post_id, 'rank_math_twitter_title', true ),
            'twitter_desc'    => get_post_meta( $post_id, 'rank_math_twitter_description', true ),
            'twitter_image'   => get_post_meta( $post_id, 'rank_math_twitter_image_url', true ),
            'focus_keyword'   => get_post_meta( $post_id, 'rank_math_focus_keyword', true ),
            'cornerstone'     => '',
        ];
    }

    if ( $plugin === 'aioseo' ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aioseo_posts WHERE post_id = %d LIMIT 1", $post_id ), ARRAY_A );
        if ( empty( $row ) ) return wseb_exporter_empty_fields();
        $robots = ! empty( $row['robots_settings'] ) ? json_decode( $row['robots_settings'], true ) : [];
        return [
            'seo_title'       => $row['title'] ?? '',
            'seo_description' => $row['description'] ?? '',
            'canonical'       => $row['canonical_url'] ?? '',
            'robots_noindex'  => ! empty( $robots['noindex'] ) ? 'noindex' : '',
            'robots_nofollow' => ! empty( $robots['nofollow'] ) ? 'nofollow' : '',
            'og_title'        => $row['og_title'] ?? '',
            'og_description'  => $row['og_description'] ?? '',
            'og_image'        => $row['og_image_url'] ?? '',
            'twitter_title'   => $row['twitter_title'] ?? '',
            'twitter_desc'    => $row['twitter_description'] ?? '',
            'twitter_image'   => $row['twitter_image_url'] ?? '',
            'focus_keyword'   => $row['keyphrases'] ?? '',
            'cornerstone'     => '',
        ];
    }

    return [
        'seo_title'       => get_post_meta( $post_id, '_seo_title', true ),
        'seo_description' => get_post_meta( $post_id, '_seo_description', true ),
        'canonical'       => get_post_meta( $post_id, '_canonical', true ),
        'robots_noindex'  => '', 'robots_nofollow' => '',
        'og_title'        => '', 'og_description' => '', 'og_image' => '',
        'twitter_title'   => '', 'twitter_desc' => '', 'twitter_image' => '',
        'focus_keyword'   => '', 'cornerstone' => '',
    ];
}

function wseb_exporter_get_term_seo( $term_id, $taxonomy ) {
    $plugin = wseb_exporter_detect_plugin();

    if ( $plugin === 'yoast' ) {
        $all  = (array) get_option( 'wpseo_taxonomy_meta', [] );
        $meta = $all[ $taxonomy ][ $term_id ] ?? [];
        return [
            'seo_title'       => $meta['wpseo_title'] ?? '',
            'seo_description' => $meta['wpseo_desc'] ?? '',
            'canonical'       => $meta['wpseo_canonical'] ?? '',
            'robots_noindex'  => ! empty( $meta['wpseo_noindex'] ) && $meta['wpseo_noindex'] !== 'default' ? $meta['wpseo_noindex'] : '',
            'robots_nofollow' => '',
            'og_title'        => $meta['wpseo_opengraph-title'] ?? '',
            'og_description'  => $meta['wpseo_opengraph-description'] ?? '',
            'og_image'        => $meta['wpseo_opengraph-image'] ?? '',
            'twitter_title'   => $meta['wpseo_twitter-title'] ?? '',
            'twitter_desc'    => $meta['wpseo_twitter-description'] ?? '',
            'twitter_image'   => $meta['wpseo_twitter-image'] ?? '',
            'focus_keyword'   => '',
            'cornerstone'     => '',
        ];
    }

    if ( $plugin === 'rankmath' ) {
        $robots_raw = get_term_meta( $term_id, 'rank_math_robots', true );
        $robots     = is_array( $robots_raw ) ? implode( ', ', $robots_raw ) : (string) $robots_raw;
        return [
            'seo_title'       => get_term_meta( $term_id, 'rank_math_title', true ),
            'seo_description' => get_term_meta( $term_id, 'rank_math_description', true ),
            'canonical'       => get_term_meta( $term_id, 'rank_math_canonical_url', true ),
            'robots_noindex'  => strpos( $robots, 'noindex' ) !== false ? 'noindex' : '',
            'robots_nofollow' => strpos( $robots, 'nofollow' ) !== false ? 'nofollow' : '',
            'og_title'        => get_term_meta( $term_id, 'rank_math_og_title', true ),
            'og_description'  => get_term_meta( $term_id, 'rank_math_og_description', true ),
            'og_image'        => get_term_meta( $term_id, 'rank_math_og_thumbnail_url', true ),
            'twitter_title'   => get_term_meta( $term_id, 'rank_math_twitter_title', true ),
            'twitter_desc'    => get_term_meta( $term_id, 'rank_math_twitter_description', true ),
            'twitter_image'   => get_term_meta( $term_id, 'rank_math_twitter_image_url', true ),
            'focus_keyword'   => get_term_meta( $term_id, 'rank_math_focus_keyword', true ),
            'cornerstone'     => '',
        ];
    }

    if ( $plugin === 'aioseo' ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}aioseo_terms WHERE term_id = %d LIMIT 1", $term_id ), ARRAY_A );
        if ( empty( $row ) ) return wseb_exporter_empty_fields();
        $robots = ! empty( $row['robots_settings'] ) ? json_decode( $row['robots_settings'], true ) : [];
        return [
            'seo_title'       => $row['title'] ?? '',
            'seo_description' => $row['description'] ?? '',
            'canonical'       => $row['canonical_url'] ?? '',
            'robots_noindex'  => ! empty( $robots['noindex'] ) ? 'noindex' : '',
            'robots_nofollow' => ! empty( $robots['nofollow'] ) ? 'nofollow' : '',
            'og_title'        => $row['og_title'] ?? '',
            'og_description'  => $row['og_description'] ?? '',
            'og_image'        => $row['og_image_url'] ?? '',
            'twitter_title'   => $row['twitter_title'] ?? '',
            'twitter_desc'    => $row['twitter_description'] ?? '',
            'twitter_image'   => $row['twitter_image_url'] ?? '',
            'focus_keyword'   => '',
            'cornerstone'     => '',
        ];
    }

    return wseb_exporter_empty_fields();
}

function wseb_exporter_get_post_types() {
    $post_types = get_post_types( [ 'public' => true ], 'objects' );
    unset( $post_types['attachment'], $post_types['nav_menu_item'] );
    return $post_types;
}

function wseb_exporter_get_taxonomies() {
    $taxonomies = get_taxonomies( [ 'public' => true ], 'objects' );
    unset( $taxonomies['post_format'] );
    return $taxonomies;
}

function wseb_exporter_csv_headers() {
    return [
        'ID', 'Type', 'Post Type / Taxonomy', 'Title', 'URL', 'Status',
        'SEO Title', 'SEO Description', 'Canonical URL',
        'Robots Noindex', 'Robots Nofollow',
        'OG Title', 'OG Description', 'OG Image',
        'Twitter Title', 'Twitter Description', 'Twitter Image',
        'Focus Keyword', 'Cornerstone', 'Schema', 'SEO Plugin',
    ];
}

function wseb_exporter_get_post_rows( array $post_types, $batch_size = 200 ) {
    $rows   = [];
    $paged  = 1;
    $plugin = wseb_exporter_plugin_name( wseb_exporter_detect_plugin() );

    do {
        $query = new WP_Query( [
            'post_type'      => $post_types,
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => $batch_size,
            'paged'          => $paged,
            'no_found_rows'  => false,
            'fields'         => 'ids',
        ] );

        if ( empty( $query->posts ) ) break;

        foreach ( $query->posts as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) continue;

            $seo    = wseb_exporter_get_post_seo( $post_id );
            $rows[] = array_merge(
                [
                    'ID'                   => $post_id,
                    'Type'                 => 'post',
                    'Post Type / Taxonomy' => $post->post_type,
                    'Title'                => $post->post_title,
                    'URL'                  => get_permalink( $post_id ),
                    'Status'               => $post->post_status,
                ],
                $seo,
                [ 'schema' => wseb_read_post_schema( $post_id ), 'SEO Plugin' => $plugin ]
            );
        }

        $paged++;
        $max_pages = $query->max_num_pages;
        wp_reset_postdata();
    } while ( $paged <= $max_pages );

    return $rows;
}

function wseb_exporter_get_term_rows( array $taxonomies ) {
    $rows   = [];
    $plugin = wseb_exporter_plugin_name( wseb_exporter_detect_plugin() );

    foreach ( $taxonomies as $taxonomy ) {
        $terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 0 ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) continue;

        foreach ( $terms as $term ) {
            $seo    = wseb_exporter_get_term_seo( $term->term_id, $taxonomy );
            $url    = get_term_link( $term );
            $rows[] = array_merge(
                [
                    'ID'                   => $term->term_id,
                    'Type'                 => 'term',
                    'Post Type / Taxonomy' => $taxonomy,
                    'Title'                => $term->name,
                    'URL'                  => is_wp_error( $url ) ? '' : $url,
                    'Status'               => 'published',
                ],
                $seo,
                [ 'schema' => wseb_read_term_schema( $term->term_id, $taxonomy ), 'SEO Plugin' => $plugin ]
            );
        }
    }

    return $rows;
}

function wseb_exporter_map_row( array $row ) {
    return [
        $row['ID']                   ?? '',
        $row['Type']                 ?? '',
        $row['Post Type / Taxonomy'] ?? '',
        $row['Title']                ?? '',
        $row['URL']                  ?? '',
        $row['Status']               ?? '',
        $row['seo_title']            ?? '',
        $row['seo_description']      ?? '',
        $row['canonical']            ?? '',
        $row['robots_noindex']       ?? '',
        $row['robots_nofollow']      ?? '',
        $row['og_title']             ?? '',
        $row['og_description']       ?? '',
        $row['og_image']             ?? '',
        $row['twitter_title']        ?? '',
        $row['twitter_desc']         ?? '',
        $row['twitter_image']        ?? '',
        $row['focus_keyword']        ?? '',
        $row['cornerstone']          ?? '',
        $row['schema']               ?? '',
        $row['SEO Plugin']           ?? '',
    ];
}

function wseb_exporter_count_posts( array $post_types ) {
    global $wpdb;
    if ( empty( $post_types ) ) return 0;
    $placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
    return (int) $wpdb->get_var( $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status IN ('publish','draft','pending','private')",
        $post_types
    ) );
}

function wseb_exporter_count_terms( array $taxonomies ) {
    $total = 0;
    foreach ( $taxonomies as $tax ) {
        $count = wp_count_terms( [ 'taxonomy' => $tax, 'hide_empty' => false ] );
        $total += is_wp_error( $count ) ? 0 : (int) $count;
    }
    return $total;
}

function wseb_exporter_stream_csv( array $post_types, array $taxonomies, $include_sitewide ) {
    while ( ob_get_level() ) ob_end_clean();

    $filename = 'seo-export-' . gmdate( 'Y-m-d-His' ) . '.csv';
    header( 'Content-Type: text/csv; charset=UTF-8' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Pragma: no-cache' );
    header( 'Expires: 0' );

    $out = fopen( 'php://output', 'w' );
    fputs( $out, "\xEF\xBB\xBF" );
    fputcsv( $out, wseb_exporter_csv_headers() );

    if ( $include_sitewide ) {
        $sitewide = (string) get_option( wseb_detect_hfs_option_key(), '' );
        if ( $sitewide !== '' ) {
            fputcsv( $out, wseb_exporter_map_row( [
                'ID' => '', 'Type' => 'sitewide', 'Post Type / Taxonomy' => '',
                'Title' => 'Sitewide Schema', 'URL' => home_url( '/' ), 'Status' => '',
                'schema' => $sitewide, 'SEO Plugin' => '',
            ] ) );
        }
    }

    if ( ! empty( $post_types ) ) {
        foreach ( wseb_exporter_get_post_rows( $post_types ) as $row ) {
            fputcsv( $out, wseb_exporter_map_row( $row ) );
        }
    }

    if ( ! empty( $taxonomies ) ) {
        foreach ( wseb_exporter_get_term_rows( $taxonomies ) as $row ) {
            fputcsv( $out, wseb_exporter_map_row( $row ) );
        }
    }

    fclose( $out );
    exit;
}

// ── admin-post handler: CSV download ───────────────────────────────────────
add_action( 'admin_post_wseb_export_download', function () {
    if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Permission denied.' );
    check_admin_referer( 'wseb_export_download', 'wseb_export_nonce' );

    $raw_post_types   = isset( $_POST['post_types'] ) ? (array) $_POST['post_types'] : [];
    $raw_taxonomies   = isset( $_POST['taxonomies'] ) ? (array) $_POST['taxonomies'] : [];
    $include_sitewide = ! empty( $_POST['include_sitewide'] );

    $allowed_post_types = wp_list_pluck( wseb_exporter_get_post_types(), 'name' );
    $allowed_taxonomies = wp_list_pluck( wseb_exporter_get_taxonomies(), 'name' );

    $post_types = array_values( array_intersect( array_map( 'sanitize_key', $raw_post_types ), $allowed_post_types ) );
    $taxonomies = array_values( array_intersect( array_map( 'sanitize_key', $raw_taxonomies ), $allowed_taxonomies ) );

    if ( empty( $post_types ) && empty( $taxonomies ) && ! $include_sitewide ) {
        wp_safe_redirect( admin_url( 'options-general.php?page=wseb-settings&tab=exporter&wseb_export_msg=nothing_selected' ) );
        exit;
    }

    wseb_exporter_stream_csv( $post_types, $taxonomies, $include_sitewide );
} );

// ── AJAX: live row count preview ───────────────────────────────────────────
add_action( 'wp_ajax_wseb_export_counts', function () {
    check_ajax_referer( 'wseb_export_counts', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permission denied.' );

    $raw_post_types = isset( $_POST['post_types'] ) ? (array) $_POST['post_types'] : [];
    $raw_taxonomies = isset( $_POST['taxonomies'] ) ? (array) $_POST['taxonomies'] : [];

    $allowed_post_types = wp_list_pluck( wseb_exporter_get_post_types(), 'name' );
    $allowed_taxonomies = wp_list_pluck( wseb_exporter_get_taxonomies(), 'name' );

    $post_types = array_values( array_intersect( array_map( 'sanitize_key', $raw_post_types ), $allowed_post_types ) );
    $taxonomies = array_values( array_intersect( array_map( 'sanitize_key', $raw_taxonomies ), $allowed_taxonomies ) );

    $post_count = wseb_exporter_count_posts( $post_types );
    $term_count = wseb_exporter_count_terms( $taxonomies );

    wp_send_json_success( [ 'posts' => $post_count, 'terms' => $term_count, 'total' => $post_count + $term_count ] );
} );

// ── Render the "SEO Exporter" tab ──────────────────────────────────────────
function wseb_render_exporter_tab() {
    $plugin_name    = wseb_exporter_plugin_name( wseb_exporter_detect_plugin() );
    $all_post_types = wseb_exporter_get_post_types();
    $all_taxonomies = wseb_exporter_get_taxonomies();
    $sitewide       = (string) get_option( wseb_detect_hfs_option_key(), '' );
    ?>
    <div style="max-width:1100px;">

        <?php if ( isset( $_GET['wseb_export_msg'] ) && $_GET['wseb_export_msg'] === 'nothing_selected' ) : ?>
            <div class="notice notice-error"><p>Select at least one post type, taxonomy, or the sitewide schema before exporting.</p></div>
        <?php endif; ?>

        <div style="display:flex;align-items:center;gap:8px;background:#f0f6fc;border-left:4px solid #2271b1;padding:12px 16px;margin-bottom:20px;border-radius:0 4px 4px 0;font-size:14px;">
            Detected SEO plugin: <strong><?php echo esc_html( $plugin_name ); ?></strong>
        </div>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="wseb-export-form">
            <?php wp_nonce_field( 'wseb_export_download', 'wseb_export_nonce' ); ?>
            <input type="hidden" name="action" value="wseb_export_download">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">

                <div class="postbox" style="padding:16px 20px;">
                    <h2 style="margin-top:0;font-size:15px;">Post Types</h2>
                    <p class="description">Select the post types to include in the export.</p>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <?php foreach ( $all_post_types as $pt ) : ?>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                                <input type="checkbox" name="post_types[]" value="<?php echo esc_attr( $pt->name ); ?>" checked>
                                <span style="font-weight:500;"><?php echo esc_html( $pt->labels->singular_name ); ?></span>
                                <span style="color:#8c8f94;font-size:12px;">(<?php echo esc_html( $pt->name ); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="postbox" style="padding:16px 20px;">
                    <h2 style="margin-top:0;font-size:15px;">Taxonomy Terms</h2>
                    <p class="description">Select taxonomies to include in the export.</p>
                    <div style="display:flex;flex-direction:column;gap:8px;">
                        <?php foreach ( $all_taxonomies as $tax ) : ?>
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                                <input type="checkbox" name="taxonomies[]" value="<?php echo esc_attr( $tax->name ); ?>" checked>
                                <span style="font-weight:500;"><?php echo esc_html( $tax->labels->singular_name ); ?></span>
                                <span style="color:#8c8f94;font-size:12px;">(<?php echo esc_html( $tax->name ); ?>)</span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

            </div>

            <div class="postbox" style="padding:16px 20px;margin-bottom:20px;">
                <label style="display:flex;align-items:center;gap:8px;font-size:13px;">
                    <input type="checkbox" name="include_sitewide" value="1" <?php checked( $sitewide !== '' ); ?> <?php disabled( $sitewide === '' ); ?>>
                    <span style="font-weight:500;">Include sitewide schema</span>
                    <span style="color:#8c8f94;font-size:12px;">(added as its own row)</span>
                </label>
                <?php if ( $sitewide === '' ) : ?>
                    <p class="description" style="margin:6px 0 0;">No sitewide schema currently set.</p>
                <?php endif; ?>
            </div>

            <div id="wseb-export-summary" style="display:none;align-items:center;gap:8px;background:#eefaee;border-left:4px solid #00a32a;padding:12px 16px;margin-bottom:16px;border-radius:0 4px 4px 0;font-size:14px;">
                <span id="wseb-export-count-text"></span>
            </div>

            <p class="submit">
                <input type="submit" class="button button-primary button-large" value="Export to CSV">
                <span class="description">&nbsp;A UTF-8 encoded CSV file will be downloaded. Open it in Excel, Google Sheets, or any spreadsheet app.</span>
            </p>
        </form>

        <h3>Exported columns</h3>
        <table class="widefat striped">
            <thead><tr><th>Column</th><th>Description</th></tr></thead>
            <tbody>
                <?php
                $legend = [
                    'ID'                   => 'Post ID or Term ID',
                    'Type'                 => 'post, term, or sitewide',
                    'Post Type / Taxonomy' => 'post, page, product, category, product_cat, etc.',
                    'Title'                => 'The native WordPress title',
                    'URL'                  => 'The full permalink',
                    'Status'               => 'publish, draft, pending, private, or published (for terms)',
                    'SEO Title'            => 'SEO plugin title override',
                    'SEO Description'      => 'Meta description',
                    'Canonical URL'        => 'Canonical tag value if set',
                    'Robots Noindex'       => 'noindex if explicitly set, blank if default',
                    'Robots Nofollow'      => 'nofollow if explicitly set, blank if default',
                    'OG Title'             => 'Open Graph title override',
                    'OG Description'       => 'Open Graph description override',
                    'OG Image'             => 'Open Graph image URL',
                    'Twitter Title'        => 'Twitter card title override',
                    'Twitter Description'  => 'Twitter card description override',
                    'Twitter Image'        => 'Twitter card image URL',
                    'Focus Keyword'        => 'Focus keyphrase (Yoast or Rank Math)',
                    'Cornerstone'          => 'Cornerstone content flag (Yoast only)',
                    'Schema'               => 'JSON-LD schema — per-post/term via /schema, or the sitewide block on its own row',
                    'SEO Plugin'           => 'Which SEO plugin was read from',
                ];
                foreach ( $legend as $col => $desc ) : ?>
                    <tr><td><code><?php echo esc_html( $col ); ?></code></td><td><?php echo esc_html( $desc ); ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

    </div>
    <script>
    jQuery(function ($) {
        var $form      = $('#wseb-export-form');
        var $summary   = $('#wseb-export-summary');
        var $countText = $('#wseb-export-count-text');
        var timer;

        function updateCounts() {
            clearTimeout(timer);
            timer = setTimeout(function () {
                var postTypes  = $form.find('input[name="post_types[]"]:checked').map(function () { return this.value; }).get();
                var taxonomies = $form.find('input[name="taxonomies[]"]:checked').map(function () { return this.value; }).get();
                var sitewide   = $form.find('input[name="include_sitewide"]').is(':checked');

                if (postTypes.length === 0 && taxonomies.length === 0 && !sitewide) {
                    $summary.hide();
                    return;
                }

                $.post(ajaxurl, {
                    action: 'wseb_export_counts',
                    nonce: '<?php echo esc_js( wp_create_nonce( 'wseb_export_counts' ) ); ?>',
                    post_types: postTypes,
                    taxonomies: taxonomies
                }, function (response) {
                    if (response.success) {
                        var d = response.data;
                        var total = d.total + (sitewide ? 1 : 0);
                        var parts = [];
                        if (d.posts > 0) parts.push(d.posts + ' post' + (d.posts !== 1 ? 's/pages' : '/page'));
                        if (d.terms > 0) parts.push(d.terms + ' term' + (d.terms !== 1 ? 's' : ''));
                        if (sitewide) parts.push('1 sitewide schema');
                        $countText.text('Ready to export ' + total + ' rows (' + parts.join(' + ') + ')');
                        $summary.css('display', 'flex');
                    }
                });
            }, 300);
        }

        $form.on('change', 'input[type="checkbox"]', updateCounts);
        updateCounts();
    });
    </script>
    <?php
}

// =============================================================================
// REST API ROUTES
// =============================================================================

add_action( 'rest_api_init', 'wseb_register_routes' );
function wseb_register_routes() {

    // ── GET /ping ─────────────────────────────────────────────────────────────
    register_rest_route( WSEB_NS, '/ping', [
        'methods'             => 'GET',
        'permission_callback' => wseb_permission( 'ping' ),
        'callback'            => function ( WP_REST_Request $req ) {
            wseb_log( 'ping', 'GET', 'read', 'ok' );
            return wseb_ok( [
                'version'    => WSEB_VERSION,
                'site'       => get_bloginfo( 'url' ),
                'seo_plugin' => wseb_detect_seo_plugin(),
                'php'        => PHP_VERSION,
            ] );
        },
    ] );

    // ── GET /health ───────────────────────────────────────────────────────────
    register_rest_route( WSEB_NS, '/health', [
        'methods'             => 'GET',
        'permission_callback' => wseb_permission( 'health' ),
        'callback'            => function ( WP_REST_Request $req ) {
            global $wpdb;
            wseb_log( 'health', 'GET', 'read', 'ok' );
            return wseb_ok( [
                'seo_plugin'   => wseb_detect_seo_plugin(),
                'hfs_plugin'   => get_option( 'jamify_hfs_insert_header' ) !== false ? 'jamify' : ( get_option( 'shfs_insert_header' ) !== false ? 'shfs' : 'none' ),
                'woocommerce'  => class_exists( 'WooCommerce' ) ? WC()->version ?? 'active' : 'not active',
                'wp_version'   => get_bloginfo( 'version' ),
                'php_version'  => PHP_VERSION,
                'db_version'   => $wpdb->db_version(),
                'plugin_version' => WSEB_VERSION,
            ] );
        },
    ] );

    // ── GET /scan — list all published posts, pages, products, CPTs ───────────
    register_rest_route( WSEB_NS, '/scan', [
        'methods'             => 'GET',
        'permission_callback' => wseb_permission( 'scan' ),
        'callback'            => function ( WP_REST_Request $req ) {

            $post_type_param = sanitize_text_field( $req->get_param( 'post_type' ) ?: 'any' );
            $per_page        = min( absint( $req->get_param( 'per_page' ) ?: 200 ), 500 );
            $page            = max( 1, absint( $req->get_param( 'page' ) ?: 1 ) );

            // Resolve post types
            if ( $post_type_param === 'any' ) {
                $post_types = array_keys( get_post_types( [ 'public' => true ], 'names' ) );
            } else {
                $post_types = array_map( 'sanitize_key', explode( ',', $post_type_param ) );
            }

            $query = new WP_Query( [
                'post_type'           => $post_types,
                'post_status'         => 'publish',
                'posts_per_page'      => $per_page,
                'paged'               => $page,
                'no_found_rows'       => false,
                'ignore_sticky_posts' => true,
            ] );

            $rows = [];
            foreach ( $query->posts as $p ) {
                $seo    = wseb_get_post_seo( $p->ID );
                $rows[] = [
                    'id'          => $p->ID,
                    'url'         => get_permalink( $p->ID ),
                    'post_type'   => $p->post_type,
                    'post_title'  => $p->post_title,
                    'seo_title'   => $seo['title'],
                    'seo_desc'    => $seo['description'],
                    'seo_plugin'  => $seo['seo_plugin'],
                ];
            }

            wseb_log( 'scan', 'GET', 'read', 'ok', null, [ 'count' => count( $rows ) ] );
            return wseb_ok( [
                'total'       => $query->found_posts,
                'page'        => $page,
                'per_page'    => $per_page,
                'total_pages' => $query->max_num_pages,
                'posts'       => $rows,
            ] );
        },
    ] );

    // ── GET /scan-terms — list all terms in public taxonomies ─────────────────
    register_rest_route( WSEB_NS, '/scan-terms', [
        'methods'             => 'GET',
        'permission_callback' => wseb_permission( 'scan_terms' ),
        'callback'            => function ( WP_REST_Request $req ) {

            $tax_param = sanitize_text_field( $req->get_param( 'taxonomy' ) ?: 'any' );

            // Build taxonomy list
            if ( $tax_param === 'any' ) {
                $taxonomies = array_keys( get_taxonomies( [ 'public' => true ], 'names' ) );
            } else {
                $taxonomies = array_map( 'sanitize_key', explode( ',', $tax_param ) );
            }

            $rows = [];
            foreach ( $taxonomies as $taxonomy ) {
                $terms = get_terms( [
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'number'     => 0,
                ] );
                if ( is_wp_error( $terms ) ) continue;
                foreach ( $terms as $term ) {
                    $seo    = wseb_get_term_seo( $term->term_id, $taxonomy );
                    $rows[] = [
                        'term_id'     => $term->term_id,
                        'taxonomy'    => $taxonomy,
                        'name'        => $term->name,
                        'slug'        => $term->slug,
                        'url'         => get_term_link( $term ),
                        'count'       => $term->count,
                        'seo_title'   => $seo['title'],
                        'seo_desc'    => $seo['description'],
                        'seo_plugin'  => $seo['seo_plugin'],
                    ];
                }
            }

            wseb_log( 'scan_terms', 'GET', 'read', 'ok', null, [ 'count' => count( $rows ) ] );
            return wseb_ok( [ 'terms' => $rows, 'total' => count( $rows ) ] );
        },
    ] );

    // ── POST /meta — write SEO meta to posts, pages, products ────────────────
    register_rest_route( WSEB_NS, '/meta', [
        'methods'             => 'POST',
        'permission_callback' => wseb_permission( 'meta' ),
        'callback'            => function ( WP_REST_Request $req ) {

            $body = $req->get_json_params();
            if ( ! $body ) return new WP_Error( 'wseb_bad_request', 'Empty or invalid JSON body', [ 'status' => 400 ] );

            // Accept single item or array
            $items   = isset( $body[0] ) ? $body : [ $body ];
            $results = [];

            foreach ( $items as $item ) {
                $post_id = absint( $item['post_id'] ?? 0 );
                if ( ! $post_id || ! get_post( $post_id ) ) {
                    $results[] = [ 'post_id' => $post_id, 'success' => false, 'error' => 'Post not found' ];
                    continue;
                }
                $title   = isset( $item['title'] )          ? sanitize_text_field( $item['title'] )          : null;
                $desc    = isset( $item['description'] )    ? sanitize_textarea_field( $item['description'] ) : null;
                $kw      = isset( $item['focus_keyword'] )  ? sanitize_text_field( $item['focus_keyword'] )   : null;

                $plugin = wseb_set_post_seo( $post_id, $title, $desc, $kw );
                wseb_purge_cache( $post_id );
                wseb_log( 'meta', 'POST', 'write', 'ok', null, [ 'post_id' => $post_id ] );
                $results[] = [ 'post_id' => $post_id, 'success' => true, 'seo_plugin' => $plugin ];
            }

            return rest_ensure_response( $results );
        },
    ] );

    // ── POST /meta-terms — write SEO meta to taxonomy terms ──────────────────
    register_rest_route( WSEB_NS, '/meta-terms', [
        'methods'             => 'POST',
        'permission_callback' => wseb_permission( 'meta_terms' ),
        'callback'            => function ( WP_REST_Request $req ) {

            $body = $req->get_json_params();
            if ( ! $body ) return new WP_Error( 'wseb_bad_request', 'Empty or invalid JSON body', [ 'status' => 400 ] );

            $items   = isset( $body[0] ) ? $body : [ $body ];
            $results = [];

            foreach ( $items as $item ) {
                $term_id  = absint( $item['term_id']  ?? 0 );
                $taxonomy = sanitize_key( $item['taxonomy'] ?? '' );

                if ( ! $term_id || ! $taxonomy ) {
                    $results[] = [ 'term_id' => $term_id, 'success' => false, 'error' => 'term_id and taxonomy required' ];
                    continue;
                }
                $term = get_term( $term_id, $taxonomy );
                if ( is_wp_error( $term ) || ! $term ) {
                    $results[] = [ 'term_id' => $term_id, 'success' => false, 'error' => 'Term not found' ];
                    continue;
                }

                $title = isset( $item['title'] )         ? sanitize_text_field( $item['title'] )          : null;
                $desc  = isset( $item['description'] )   ? sanitize_textarea_field( $item['description'] ) : null;
                $kw    = isset( $item['focus_keyword'] )  ? sanitize_text_field( $item['focus_keyword'] )   : null;

                $plugin = wseb_set_term_seo( $term_id, $taxonomy, $title, $desc, $kw );
                wseb_log( 'meta_terms', 'POST', 'write', 'ok', null, [ 'term_id' => $term_id, 'taxonomy' => $taxonomy ] );
                $results[] = [ 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'success' => true, 'seo_plugin' => $plugin ];
            }

            return rest_ensure_response( $results );
        },
    ] );

    // ── GET /llms-settings — read llms.txt config for the detected SEO plugin ─
    register_rest_route( WSEB_NS, '/llms-settings', [
        'methods'             => 'GET',
        'permission_callback' => wseb_permission( 'llms_settings' ),
        'callback'            => function ( WP_REST_Request $req ) {
            wseb_log( 'llms_settings', 'GET', 'read', 'ok' );
            return wseb_ok( wseb_get_llms_settings() );
        },
    ] );

    // ── POST /llms-settings — update llms.txt config for the detected SEO plugin
    register_rest_route( WSEB_NS, '/llms-settings', [
        'methods'             => 'POST',
        'permission_callback' => wseb_permission( 'llms_settings' ),
        'callback'            => function ( WP_REST_Request $req ) {

            $body = $req->get_json_params();
            if ( ! is_array( $body ) ) return new WP_Error( 'wseb_bad_request', 'Empty or invalid JSON body', [ 'status' => 400 ] );

            $plugin = wseb_set_llms_settings( $body );
            if ( $plugin === 'none' ) {
                return new WP_Error( 'wseb_no_seo_plugin', 'No supported SEO plugin (Yoast or Rank Math) detected.', [ 'status' => 400 ] );
            }

            wseb_log( 'llms_settings', 'POST', 'write', 'ok', null, [ 'seo_plugin' => $plugin ] );
            return wseb_ok( wseb_get_llms_settings() );
        },
    ] );

    // ── GET /llms-txt — read the raw llms.txt file at the site root ──────────
    register_rest_route( WSEB_NS, '/llms-txt', [
        'methods'             => 'GET',
        'permission_callback' => wseb_permission( 'llms_txt' ),
        'callback'            => function ( WP_REST_Request $req ) {
            $fs   = wseb_init_filesystem();
            $path = wseb_llms_txt_path();
            wseb_log( 'llms_txt', 'GET', 'read', 'ok' );
            return wseb_ok( [
                'exists'   => $fs ? $fs->exists( $path ) : false,
                'writable' => $fs ? $fs->is_writable( dirname( $path ) ) : false,
                'content'  => wseb_read_llms_txt(),
            ] );
        },
    ] );

    // ── POST /llms-txt — push fully custom llms.txt content, written ─────────
    //    directly to the site root. Bypasses Yoast/Rank Math's own generators
    //    entirely — a real file at this path wins at the webserver level.
    register_rest_route( WSEB_NS, '/llms-txt', [
        'methods'             => 'POST',
        'permission_callback' => wseb_permission( 'llms_txt' ),
        'callback'            => function ( WP_REST_Request $req ) {

            $body    = $req->get_json_params();
            $content = $body['content'] ?? '';
            if ( ! is_string( $content ) || trim( $content ) === '' ) {
                return new WP_Error( 'wseb_bad_request', 'content is required', [ 'status' => 400 ] );
            }

            $ok = wseb_write_llms_txt( $content );
            if ( ! $ok ) {
                return new WP_Error( 'wseb_write_failed', 'Could not write llms.txt — check filesystem permissions on the site root.', [ 'status' => 500 ] );
            }

            wseb_purge_cache();
            wseb_log( 'llms_txt', 'POST', 'write', 'ok' );
            return wseb_ok( [ 'path' => wseb_llms_txt_path() ] );
        },
    ] );

    // ── POST /schema — inject schema into a post (via HFS) or a taxonomy
    //    term / product category (via ACF field, since HFS plugins don't
    //    attach to term pages) ───────────────────────────────────────────────
    register_rest_route( WSEB_NS, '/schema', [
        'methods'             => 'POST',
        'permission_callback' => wseb_permission( 'schema' ),
        'callback'            => function ( WP_REST_Request $req ) {

            $body     = $req->get_json_params();
            $schema   = $body['schema'] ?? '';
            $post_id  = absint( $body['post_id'] ?? 0 );
            $term_id  = absint( $body['term_id'] ?? 0 );
            $taxonomy = sanitize_key( $body['taxonomy'] ?? '' );

            if ( ! $schema ) {
                return new WP_Error( 'wseb_bad_request', 'schema is required', [ 'status' => 400 ] );
            }

            if ( $term_id || $taxonomy ) {
                if ( ! $term_id || ! $taxonomy ) {
                    return new WP_Error( 'wseb_bad_request', 'term_id and taxonomy are both required', [ 'status' => 400 ] );
                }
                $term = get_term( $term_id, $taxonomy );
                if ( is_wp_error( $term ) || ! $term ) {
                    return new WP_Error( 'wseb_not_found', 'Term not found', [ 'status' => 404 ] );
                }

                wseb_write_term_schema( $term_id, $taxonomy, $schema );
                wseb_purge_cache();
                wseb_log( 'schema', 'POST', 'write', 'ok', null, [ 'term_id' => $term_id, 'taxonomy' => $taxonomy ] );
                return wseb_ok( [ 'term_id' => $term_id, 'taxonomy' => $taxonomy ] );
            }

            if ( ! $post_id || ! get_post( $post_id ) ) {
                return new WP_Error( 'wseb_not_found', 'Post not found', [ 'status' => 404 ] );
            }

            wseb_write_post_schema( $post_id, $schema );
            wseb_purge_cache( $post_id );
            wseb_log( 'schema', 'POST', 'write', 'ok', null, [ 'post_id' => $post_id ] );
            return wseb_ok( [ 'post_id' => $post_id ] );
        },
    ] );

    // ── POST /sitewide-schema — write to global HFS head ─────────────────────
    register_rest_route( WSEB_NS, '/sitewide-schema', [
        'methods'             => 'POST',
        'permission_callback' => wseb_permission( 'sitewide_schema' ),
        'callback'            => function ( WP_REST_Request $req ) {

            $body   = $req->get_json_params();
            $schema = $body['schema'] ?? '';
            if ( ! $schema ) return new WP_Error( 'wseb_bad_request', 'schema is required', [ 'status' => 400 ] );

            wseb_write_sitewide_schema( $schema );
            wseb_purge_cache();
            wseb_log( 'sitewide_schema', 'POST', 'write', 'ok' );
            return wseb_ok();
        },
    ] );

    // ── POST /purge-cache ─────────────────────────────────────────────────────
    register_rest_route( WSEB_NS, '/purge-cache', [
        'methods'             => 'POST',
        'permission_callback' => wseb_permission( 'purge_cache' ),
        'callback'            => function ( WP_REST_Request $req ) {
            $body    = $req->get_json_params();
            $post_id = absint( $body['post_id'] ?? 0 ) ?: null;
            wseb_purge_cache( $post_id );
            wseb_log( 'purge_cache', 'POST', 'write', 'ok', null, [ 'post_id' => $post_id ] );
            return wseb_ok( [ 'post_id' => $post_id ] );
        },
    ] );

} // end wseb_register_routes
