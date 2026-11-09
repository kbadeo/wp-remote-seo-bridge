<?php
/**
 * llms.txt settings (Yoast / Rank Math) and the custom llms.txt file push.
 *
 * Part of WP Remote SEO Bridge; loaded from wp-remote-seo-bridge.php.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
