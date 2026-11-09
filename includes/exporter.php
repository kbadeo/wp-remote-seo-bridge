<?php
/**
 * The CSV SEO exporter tab, merged from the standalone SEO Data Exporter plugin.
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
