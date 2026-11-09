<?php
/**
 * JSON-LD schema storage for posts, taxonomy terms and the sitewide header.
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
