<?php
/**
 * REST route registration for the wseb/v1 namespace.
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
