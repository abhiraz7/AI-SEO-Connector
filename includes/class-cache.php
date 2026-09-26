<?php
/**
 * Clears caches after a fix is written, so the change actually shows up.
 *
 * WordPress's own object cache is cleared with core functions. Page caches
 * belong to whichever cache plugin the site runs, so each supported plugin
 * is called through its own public purge function or action -- only when
 * that plugin is active. Most of them already purge on save_post, but SEO
 * meta is written with update_post_meta(), which doesn't fire save_post,
 * so without this a new title can sit behind a stale page cache.
 *
 * Every method returns the names of the caches it cleared, so callers can
 * report what really happened instead of promising "visible immediately".
 * CDN/edge caches (e.g. Cloudflare) are not reachable from here.
 */
class AISEOC_Cache {

    /** Clear caches for one post. Returns the names of caches cleared. */
    public static function purge_post( int $post_id ): array {
        if ( $post_id <= 0 ) return [];

        clean_post_cache( $post_id );
        $done = [ 'object_cache' ];

        if ( function_exists( 'rocket_clean_post' ) ) {
            rocket_clean_post( $post_id );
            $done[] = 'wp_rocket';
        }
        if ( defined( 'LSCWP_V' ) ) {
            do_action( 'litespeed_purge_post', $post_id );
            $done[] = 'litespeed';
        }
        if ( function_exists( 'w3tc_flush_post' ) ) {
            w3tc_flush_post( $post_id );
            $done[] = 'w3_total_cache';
        }
        if ( function_exists( 'wp_cache_post_change' ) ) {
            wp_cache_post_change( $post_id );
            $done[] = 'wp_super_cache';
        }
        if ( class_exists( 'WpFastestCache' ) ) {
            do_action( 'wpfc_clear_post_cache_by_id', false, $post_id );
            $done[] = 'wp_fastest_cache';
        }
        if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
            $url = get_permalink( $post_id );
            if ( $url ) {
                sg_cachepress_purge_cache( $url );
                $done[] = 'siteground';
            }
        }

        return $done;
    }

    /**
     * Clear caches for one taxonomy term's archive page. Term meta writes fire
     * no save_post, so as with posts nothing purges on its own. Only the cache
     * plugins whose public API purges by URL are covered (the same set that
     * has a by-URL call); others are not touched, and the returned list says
     * exactly what was cleared. Not yet exercised against any page-cache
     * plugin -- the one site this was tried on has none active.
     */
    public static function purge_term( int $term_id, string $taxonomy ): array {
        if ( $term_id <= 0 ) return [];

        clean_term_cache( $term_id, $taxonomy );
        $done = [ 'object_cache' ];

        $url = get_term_link( $term_id, $taxonomy );
        if ( is_wp_error( $url ) || ! $url ) return $done;

        if ( function_exists( 'rocket_clean_files' ) ) {
            rocket_clean_files( $url );
            $done[] = 'wp_rocket';
        }
        if ( defined( 'LSCWP_V' ) ) {
            do_action( 'litespeed_purge_url', $url );
            $done[] = 'litespeed';
        }
        if ( function_exists( 'w3tc_flush_url' ) ) {
            w3tc_flush_url( $url );
            $done[] = 'w3_total_cache';
        }
        if ( function_exists( 'wpsc_delete_url_cache' ) ) {
            wpsc_delete_url_cache( $url );
            $done[] = 'wp_super_cache';
        }
        if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
            sg_cachepress_purge_cache( $url );
            $done[] = 'siteground';
        }

        return $done;
    }

    /** Clear every cache we can reach. Returns the names of caches cleared. */
    public static function purge_all(): array {
        wp_cache_flush();
        $done = [ 'object_cache' ];

        if ( function_exists( 'rocket_clean_domain' ) ) {
            rocket_clean_domain();
            $done[] = 'wp_rocket';
        }
        if ( defined( 'LSCWP_V' ) ) {
            do_action( 'litespeed_purge_all' );
            $done[] = 'litespeed';
        }
        if ( function_exists( 'w3tc_flush_all' ) ) {
            w3tc_flush_all();
            $done[] = 'w3_total_cache';
        }
        if ( function_exists( 'wp_cache_clear_cache' ) ) {
            wp_cache_clear_cache();
            $done[] = 'wp_super_cache';
        }
        if ( class_exists( 'WpFastestCache' ) ) {
            do_action( 'wpfc_clear_all_cache' );
            $done[] = 'wp_fastest_cache';
        }
        if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
            sg_cachepress_purge_everything();
            $done[] = 'siteground';
        }

        return $done;
    }
}
