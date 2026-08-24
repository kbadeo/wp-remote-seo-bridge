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
 * Version:      1.7.0
 * Author:       Karl Badeo
 * License:      MIT
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'WSEB_VERSION',   '1.7.0' );
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

function wseb_ok( $data = [] ) {
    return rest_ensure_response( array_merge( [ 'success' => true ], $data ) );
}

// =============================================================================
// SEO PLUGIN DETECTION
// =============================================================================

function wseb_detect_seo_plugin() {
    if ( defined( 'RANK_MATH_VERSION' ) || get_option( 'rank_math_general' ) !== false ) return 'rankmath';
    if ( defined( 'WPSEO_VERSION' )     || get_option( 'wpseo' )             !== false ) return 'yoast';
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
 * 'rank_math_modules' list, and its own field settings in 'rank_math_general'.
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
        $general = (array) get_option( 'rank_math_general', [] );
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

        $general = (array) get_option( 'rank_math_general', [] );
        $dirty   = false;
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
        if ( $dirty ) update_option( 'rank_math_general', $general );

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

    ?>
    <div class="wrap">
        <h1>WP Remote SEO Bridge <span style="font-size:13px;color:#999;">v<?php echo WSEB_VERSION; ?></span></h1>
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
    </div>
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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'ping' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )         return wseb_deny();
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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'health' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )            return wseb_deny();
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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'scan' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )          return wseb_deny();

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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'scan_terms' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )                return wseb_deny();

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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'meta' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )          return wseb_deny();

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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'meta_terms' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )                return wseb_deny();

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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'llms_settings' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )                   return wseb_deny();
            wseb_log( 'llms_settings', 'GET', 'read', 'ok' );
            return wseb_ok( wseb_get_llms_settings() );
        },
    ] );

    // ── POST /llms-settings — update llms.txt config for the detected SEO plugin
    register_rest_route( WSEB_NS, '/llms-settings', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'llms_settings' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )                   return wseb_deny();

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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'llms_txt' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )              return wseb_deny();
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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'llms_txt' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )              return wseb_deny();

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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'schema' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )             return wseb_deny();

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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'sitewide_schema' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )                     return wseb_deny();

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
        'permission_callback' => '__return_true',
        'callback'            => function ( WP_REST_Request $req ) {
            if ( ! wseb_ep_enabled( 'purge_cache' ) ) return wseb_deny( 'Endpoint disabled' );
            if ( ! wseb_auth( $req ) )                 return wseb_deny();
            $body    = $req->get_json_params();
            $post_id = absint( $body['post_id'] ?? 0 ) ?: null;
            wseb_purge_cache( $post_id );
            wseb_log( 'purge_cache', 'POST', 'write', 'ok', null, [ 'post_id' => $post_id ] );
            return wseb_ok( [ 'post_id' => $post_id ] );
        },
    ] );

} // end wseb_register_routes
