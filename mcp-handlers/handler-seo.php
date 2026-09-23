<?php
class AISEOC_SEO {

    /* All known Yoast meta keys */
    const YOAST_KEYS = [
        '_yoast_wpseo_title',
        '_yoast_wpseo_metadesc',
        '_yoast_wpseo_focuskw',
        '_yoast_wpseo_canonical',
        '_yoast_wpseo_opengraph-title',
        '_yoast_wpseo_opengraph-description',
        '_yoast_wpseo_opengraph-image',
        '_yoast_wpseo_twitter-title',
        '_yoast_wpseo_twitter-description',
        '_yoast_wpseo_twitter-image',
        '_yoast_wpseo_meta-robots-noindex',
        '_yoast_wpseo_meta-robots-nofollow',
        '_yoast_wpseo_meta-robots-adv',
        '_yoast_wpseo_schema_article_type',
        '_yoast_wpseo_schema_page_type',
        '_yoast_wpseo_is_cornerstone',
        '_yoast_wpseo_estimated-reading-time-minutes',
        '_yoast_wpseo_primary_category',
    ];

    /* All known RankMath meta keys. Unlike Yoast, RankMath stores noindex/
     * nofollow together in ONE array-valued meta key (rank_math_robots,
     * e.g. ['noindex','nofollow']) rather than two separate scalar keys --
     * get_meta()/set_meta() below translate that into the same noindex/
     * nofollow friendly fields Yoast uses, so callers never need to know
     * which SEO plugin is actually active. RankMath has no 1:1 equivalent
     * of Yoast's schema_article_type/schema_page_type (its schema system
     * is structured differently, under rank_math_snippet_* keys) -- those
     * two friendly fields are simply absent/no-op under RankMath rather
     * than guessed at.
     */
    const RANKMATH_KEYS = [
        'rank_math_title',
        'rank_math_description',
        'rank_math_focus_keyword',
        'rank_math_canonical_url',
        'rank_math_facebook_title',
        'rank_math_facebook_description',
        'rank_math_facebook_image',
        'rank_math_twitter_title',
        'rank_math_twitter_description',
        'rank_math_twitter_image',
        'rank_math_robots',
        'rank_math_pillar_content',
        'rank_math_primary_category',
    ];

    /** Which SEO plugin's fields to read/write -- Yoast, RankMath, or
     * neither active (falls back to Yoast's keys anyway so get/set never
     * hard-fail, matching this handler's existing behavior before RankMath
     * support existed). Checked live rather than cached, since a site
     * could switch SEO plugins between calls. */
    private static function active_provider(): string {
        if ( defined( 'WPSEO_VERSION' ) ) return 'yoast';
        if ( defined( 'RANK_MATH_VERSION' ) ) return 'rankmath';
        return 'yoast';
    }

    /* ── Get all SEO meta (Yoast or RankMath, whichever is active) ──── */
    public static function get_meta( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        if ( ! $post_id ) throw new Exception( 'post_id required.' );

        if ( self::active_provider() === 'rankmath' ) {
            return self::get_meta_rankmath( $post_id );
        }
        return self::get_meta_yoast( $post_id );
    }

    private static function get_meta_yoast( int $post_id ): array {
        $meta = [];
        foreach ( self::YOAST_KEYS as $key ) {
            $meta[ $key ] = get_post_meta( $post_id, $key, true );
        }

        return [
            'post_id'              => $post_id,
            'seo_plugin'           => 'yoast',
            'seo_title'            => $meta['_yoast_wpseo_title'],
            'meta_description'     => $meta['_yoast_wpseo_metadesc'],
            'focus_keyword'        => $meta['_yoast_wpseo_focuskw'],
            'canonical_url'        => $meta['_yoast_wpseo_canonical'],
            'og_title'             => $meta['_yoast_wpseo_opengraph-title'],
            'og_description'       => $meta['_yoast_wpseo_opengraph-description'],
            'og_image'             => $meta['_yoast_wpseo_opengraph-image'],
            'twitter_title'        => $meta['_yoast_wpseo_twitter-title'],
            'twitter_description'  => $meta['_yoast_wpseo_twitter-description'],
            'noindex'              => $meta['_yoast_wpseo_meta-robots-noindex'],
            'nofollow'             => $meta['_yoast_wpseo_meta-robots-nofollow'],
            'is_cornerstone'       => $meta['_yoast_wpseo_is_cornerstone'],
            'schema_article_type'  => $meta['_yoast_wpseo_schema_article_type'],
            'schema_page_type'     => $meta['_yoast_wpseo_schema_page_type'],
            'primary_category'     => $meta['_yoast_wpseo_primary_category'],
            'raw'                  => $meta,
        ];
    }

    private static function get_meta_rankmath( int $post_id ): array {
        $meta = [];
        foreach ( self::RANKMATH_KEYS as $key ) {
            $meta[ $key ] = get_post_meta( $post_id, $key, true );
        }

        $robots = is_array( $meta['rank_math_robots'] ) ? $meta['rank_math_robots'] : [];

        return [
            'post_id'              => $post_id,
            'seo_plugin'           => 'rankmath',
            'seo_title'            => $meta['rank_math_title'],
            'meta_description'     => $meta['rank_math_description'],
            'focus_keyword'        => $meta['rank_math_focus_keyword'],
            'canonical_url'        => $meta['rank_math_canonical_url'],
            'og_title'             => $meta['rank_math_facebook_title'],
            'og_description'       => $meta['rank_math_facebook_description'],
            'og_image'             => $meta['rank_math_facebook_image'],
            'twitter_title'        => $meta['rank_math_twitter_title'],
            'twitter_description'  => $meta['rank_math_twitter_description'],
            'noindex'              => in_array( 'noindex', $robots, true ) ? '1' : '',
            'nofollow'             => in_array( 'nofollow', $robots, true ) ? '1' : '',
            'is_cornerstone'       => $meta['rank_math_pillar_content'],
            'schema_article_type'  => '', // No 1:1 RankMath equivalent -- see class docblock above.
            'schema_page_type'     => '',
            'primary_category'     => $meta['rank_math_primary_category'],
            'raw'                  => $meta,
        ];
    }

    /* ── Set SEO meta (Yoast or RankMath, whichever is active) ──────── */
    public static function set_meta( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        if ( ! $post_id ) throw new Exception( 'post_id required.' );

        if ( self::active_provider() === 'rankmath' ) {
            return self::set_meta_rankmath( $post_id, $p );
        }
        return self::set_meta_yoast( $post_id, $p );
    }

    private static function set_meta_yoast( int $post_id, array $p ): array {
        $map = [
            'seo_title'            => '_yoast_wpseo_title',
            'meta_description'     => '_yoast_wpseo_metadesc',
            'focus_keyword'        => '_yoast_wpseo_focuskw',
            'canonical_url'        => '_yoast_wpseo_canonical',
            'og_title'             => '_yoast_wpseo_opengraph-title',
            'og_description'       => '_yoast_wpseo_opengraph-description',
            'og_image'             => '_yoast_wpseo_opengraph-image',
            'twitter_title'        => '_yoast_wpseo_twitter-title',
            'twitter_description'  => '_yoast_wpseo_twitter-description',
            'twitter_image'        => '_yoast_wpseo_twitter-image',
            'noindex'              => '_yoast_wpseo_meta-robots-noindex',
            'nofollow'             => '_yoast_wpseo_meta-robots-nofollow',
            'is_cornerstone'       => '_yoast_wpseo_is_cornerstone',
            'schema_article_type'  => '_yoast_wpseo_schema_article_type',
            'schema_page_type'     => '_yoast_wpseo_schema_page_type',
            'primary_category'     => '_yoast_wpseo_primary_category',
        ];

        $updated = [];
        foreach ( $map as $friendly => $meta_key ) {
            if ( isset( $p[ $friendly ] ) ) {
                update_post_meta( $post_id, $meta_key, $p[ $friendly ] );
                $updated[] = $friendly;
            }
        }

        if ( ! empty( $p['raw'] ) && is_array( $p['raw'] ) ) {
            foreach ( $p['raw'] as $key => $value ) {
                if ( strpos( $key, '_yoast_' ) === 0 ) {
                    update_post_meta( $post_id, $key, $value );
                    $updated[] = $key;
                }
            }
        }

        AISEOC_Logger::log( 'info', "Updated Yoast SEO meta on post #{$post_id}: " . implode( ', ', $updated ) );
        return [ 'post_id' => $post_id, 'seo_plugin' => 'yoast', 'updated_fields' => $updated ];
    }

    private static function set_meta_rankmath( int $post_id, array $p ): array {
        $map = [
            'seo_title'            => 'rank_math_title',
            'meta_description'     => 'rank_math_description',
            'focus_keyword'        => 'rank_math_focus_keyword',
            'canonical_url'        => 'rank_math_canonical_url',
            'og_title'             => 'rank_math_facebook_title',
            'og_description'       => 'rank_math_facebook_description',
            'og_image'             => 'rank_math_facebook_image',
            'twitter_title'        => 'rank_math_twitter_title',
            'twitter_description'  => 'rank_math_twitter_description',
            'twitter_image'        => 'rank_math_twitter_image',
            'is_cornerstone'       => 'rank_math_pillar_content',
            'primary_category'     => 'rank_math_primary_category',
        ];

        $updated = [];
        foreach ( $map as $friendly => $meta_key ) {
            if ( isset( $p[ $friendly ] ) ) {
                update_post_meta( $post_id, $meta_key, $p[ $friendly ] );
                $updated[] = $friendly;
            }
        }

        // noindex/nofollow: merge into RankMath's single rank_math_robots
        // array rather than overwriting it, so setting noindex doesn't
        // silently clear an existing nofollow (or vice versa).
        if ( isset( $p['noindex'] ) || isset( $p['nofollow'] ) ) {
            $robots = get_post_meta( $post_id, 'rank_math_robots', true );
            $robots = is_array( $robots ) ? $robots : [];

            foreach ( [ 'noindex', 'nofollow' ] as $flag ) {
                if ( ! isset( $p[ $flag ] ) ) continue;
                $has = in_array( $flag, $robots, true );
                if ( $p[ $flag ] && ! $has ) {
                    $robots[] = $flag;
                } elseif ( ! $p[ $flag ] && $has ) {
                    $robots = array_values( array_diff( $robots, [ $flag ] ) );
                }
                $updated[] = $flag;
            }
            update_post_meta( $post_id, 'rank_math_robots', $robots );
        }

        if ( ! empty( $p['raw'] ) && is_array( $p['raw'] ) ) {
            foreach ( $p['raw'] as $key => $value ) {
                if ( strpos( $key, 'rank_math_' ) === 0 ) {
                    update_post_meta( $post_id, $key, $value );
                    $updated[] = $key;
                }
            }
        }

        AISEOC_Logger::log( 'info', "Updated RankMath SEO meta on post #{$post_id}: " . implode( ', ', $updated ) );
        return [ 'post_id' => $post_id, 'seo_plugin' => 'rankmath', 'updated_fields' => $updated ];
    }

    /* ── Audit post SEO ──────────────────────────────────── */
    public static function audit_post( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        if ( ! $post_id ) throw new Exception( 'post_id required.' );

        $post   = get_post( $post_id );
        $meta   = self::get_meta( $p );
        $issues = [];
        $passes = [];

        if ( empty( $meta['seo_title'] ) ) {
            $issues[] = [ 'severity' => 'error', 'field' => 'seo_title', 'message' => 'SEO title is missing.' ];
        } elseif ( strlen( $meta['seo_title'] ) > 60 ) {
            $issues[] = [ 'severity' => 'warn', 'field' => 'seo_title', 'message' => 'SEO title exceeds 60 characters (' . strlen( $meta['seo_title'] ) . ').' ];
        } else {
            $passes[] = 'SEO title is set and within length.';
        }

        if ( empty( $meta['meta_description'] ) ) {
            $issues[] = [ 'severity' => 'error', 'field' => 'meta_description', 'message' => 'Meta description is missing.' ];
        } elseif ( strlen( $meta['meta_description'] ) > 155 ) {
            $issues[] = [ 'severity' => 'warn', 'field' => 'meta_description', 'message' => 'Meta description exceeds 155 characters.' ];
        } else {
            $passes[] = 'Meta description OK.';
        }

        if ( empty( $meta['focus_keyword'] ) ) {
            $issues[] = [ 'severity' => 'warn', 'field' => 'focus_keyword', 'message' => 'Focus keyword not set.' ];
        } else {
            $kw = strtolower( $meta['focus_keyword'] );
            if ( $post && strpos( strtolower( $post->post_title ), $kw ) === false ) {
                $issues[] = [ 'severity' => 'warn', 'field' => 'focus_keyword', 'message' => "Focus keyword \"{$kw}\" not found in post title." ];
            }
            if ( $post && strpos( strtolower( $post->post_content ), $kw ) === false ) {
                $issues[] = [ 'severity' => 'warn', 'field' => 'focus_keyword', 'message' => "Focus keyword \"{$kw}\" not found in post content." ];
            }
        }

        if ( ! has_post_thumbnail( $post_id ) ) {
            $issues[] = [ 'severity' => 'warn', 'field' => 'featured_image', 'message' => 'No featured image set.' ];
        } else {
            $passes[] = 'Featured image is set.';
        }

        if ( empty( $meta['og_title'] ) && empty( $meta['seo_title'] ) ) {
            $issues[] = [ 'severity' => 'info', 'field' => 'og_title', 'message' => 'OG title not set (will fallback to post title).' ];
        }

        if ( $post ) {
            $word_count = str_word_count( strip_tags( $post->post_content ) );
            if ( $word_count < 300 ) {
                $issues[] = [ 'severity' => 'warn', 'field' => 'content', 'message' => "Content is only {$word_count} words. Recommended: 300+." ];
            } else {
                $passes[] = "Content length OK ({$word_count} words).";
            }
        }

        if ( empty( $meta['canonical_url'] ) ) {
            $issues[] = [ 'severity' => 'info', 'field' => 'canonical', 'message' => 'No canonical URL set (using default).' ];
        }

        return [
            'post_id' => $post_id,
            'issues'  => $issues,
            'passes'  => $passes,
            'score'   => count( $passes ) . '/' . ( count( $passes ) + count( array_filter( $issues, fn($i) => $i['severity'] === 'error' ) ) ),
        ];
    }

    /* ── Ping sitemap to search engines ─────────────────── */
    public static function ping_sitemap( array $p ): array {
        $sitemap_url = get_bloginfo( 'url' ) . '/sitemap_index.xml';
        $endpoints   = [
            'Google' => 'https://www.google.com/ping?sitemap=' . rawurlencode( $sitemap_url ),
            'Bing'   => 'https://www.bing.com/ping?sitemap='   . rawurlencode( $sitemap_url ),
        ];

        $results = [];
        foreach ( $endpoints as $engine => $url ) {
            $response = wp_remote_get( $url, [ 'timeout' => 10 ] );
            $results[ $engine ] = is_wp_error( $response )
                ? 'error: ' . $response->get_error_message()
                : 'HTTP ' . wp_remote_retrieve_response_code( $response );
        }

        AISEOC_Logger::log( 'info', 'Pinged sitemaps: ' . wp_json_encode( $results ) );
        return [ 'sitemap' => $sitemap_url, 'results' => $results ];
    }
}
