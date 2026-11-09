<?php
/**
 * SEO plugin detection and reading/writing post and taxonomy-term SEO meta.
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
