<?php
class AISEOC_Content {

    const MAX_PER_PAGE     = 100;
    const ALLOWED_STATUSES = [ 'draft', 'publish', 'private', 'pending', 'future', 'trash' ];

    /**
     * Meta keys matching these prefixes are never returned or written:
     * payment/order/customer data (e.g. WooCommerce order fields) and
     * WordPress/plugin internals.
     */
    const BLOCKED_META_PREFIXES = [
        '_stripe_', '_paypal_', '_wc_', '_edd_', '_password', '_auth_', 'session_',
        '_transient_', 'auth_key', '_billing_', '_shipping_', '_customer_', '_order_',
        '_payment_', '_transaction_', '_wp_', '_edit_', 'aiseoc_',
    ];

    /** Meta keys containing any of these are treated as secrets. */
    const BLOCKED_META_WORDS = [ 'password', 'secret', 'token', 'api_key', 'apikey', 'private_key' ];

    /* ── Create post ─────────────────────────────────────── */
    public static function create_post( array $p ): array {
        $status = sanitize_key( $p['status'] ?? 'draft' );
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
            $status = 'draft';
        }

        $type = sanitize_key( $p['type'] ?? 'post' );
        if ( ! in_array( $type, self::allowed_post_types(), true ) ) {
            throw new InvalidArgumentException( "Post type '{$type}' is not supported." );
        }

        $author = intval( $p['author_id'] ?? get_current_user_id() );
        if ( isset( $p['author_id'] ) && ! get_userdata( $author ) ) {
            throw new InvalidArgumentException( "User #{$author} does not exist." );
        }

        $meta = self::checked_meta( $p['meta'] ?? [] );

        $args = [
            'post_title'    => sanitize_text_field( $p['title'] ?? 'Untitled' ),
            'post_content'  => wp_kses_post( $p['content'] ?? '' ),
            'post_status'   => $status,
            'post_type'     => $type,
            'post_excerpt'  => sanitize_textarea_field( $p['excerpt'] ?? '' ),
            'post_password' => sanitize_text_field( $p['password'] ?? '' ),
            'post_author'   => $author,
            'comment_status'=> in_array( $p['comment_status'] ?? 'open', [ 'open', 'closed' ], true )
                               ? $p['comment_status'] : 'open',
        ];

        if ( ! empty( $p['date'] ) ) {
            $args['post_date']     = sanitize_text_field( $p['date'] );
            $args['post_date_gmt'] = get_gmt_from_date( sanitize_text_field( $p['date'] ) );
            $args['post_status']   = 'future';
        }

        if ( ! empty( $p['slug'] ) ) {
            $args['post_name'] = sanitize_title( $p['slug'] );
        }

        $post_id = self::save_post( $args );
        if ( is_wp_error( $post_id ) ) throw new Exception( $post_id->get_error_message() );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }

        if ( ! empty( $p['terms'] ) && is_array( $p['terms'] ) ) {
            foreach ( $p['terms'] as $taxonomy => $term_ids ) {
                wp_set_post_terms( $post_id, array_map( 'intval', (array) $term_ids ), sanitize_key( $taxonomy ) );
            }
        }

        AISEOC_Logger::log( 'info', "Created post #{$post_id}: {$args['post_title']}" );
        return [ 'post_id' => $post_id, 'url' => get_permalink( $post_id ) ];
    }

    /* ── Update post ─────────────────────────────────────── */
    public static function update_post( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        if ( ! $post_id ) throw new Exception( 'post_id required.' );
        self::require_post( $post_id );

        $meta = self::checked_meta( $p['meta'] ?? [] );
        $args = [ 'ID' => $post_id ];

        if ( isset( $p['title'] ) )   $args['post_title']   = sanitize_text_field( $p['title'] );
        if ( isset( $p['content'] ) ) $args['post_content'] = wp_kses_post( $p['content'] );
        if ( isset( $p['excerpt'] ) ) $args['post_excerpt'] = sanitize_textarea_field( $p['excerpt'] );
        if ( isset( $p['slug'] ) )    $args['post_name']    = sanitize_title( $p['slug'] );
        if ( isset( $p['date'] ) ) {
            $args['post_date']     = sanitize_text_field( $p['date'] );
            $args['post_date_gmt'] = get_gmt_from_date( $args['post_date'] );
            // Without edit_date, WordPress discards a new date on drafts.
            $args['edit_date']     = true;
        }

        if ( isset( $p['status'] ) ) {
            $status = sanitize_key( $p['status'] );
            $args['post_status'] = in_array( $status, self::ALLOWED_STATUSES, true ) ? $status : 'draft';
        }

        $result = self::save_post( $args );
        if ( is_wp_error( $result ) ) throw new Exception( $result->get_error_message() );

        foreach ( $meta as $key => $value ) {
            update_post_meta( $post_id, $key, $value );
        }

        AISEOC_Logger::log( 'info', "Updated post #{$post_id}" );
        return [
            'post_id'       => $post_id,
            'url'           => get_permalink( $post_id ),
            'caches_purged' => AISEOC_Cache::purge_post( $post_id ),
        ];
    }

    /* ── Get post ─────────────────────────────────────────── */
    public static function get_post( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        $post    = self::require_post( $post_id );

        $meta         = self::safe_post_meta( $post_id );
        $thumbnail_id = get_post_thumbnail_id( $post_id );

        return [
            'id'            => $post->ID,
            'title'         => $post->post_title,
            'content'       => $post->post_content,
            'excerpt'       => $post->post_excerpt,
            'status'        => $post->post_status,
            'type'          => $post->post_type,
            'date'          => $post->post_date,
            'modified'      => $post->post_modified,
            'slug'          => $post->post_name,
            'url'           => get_permalink( $post_id ),
            'author_id'     => $post->post_author,
            'thumbnail_id'  => $thumbnail_id,
            'thumbnail_url' => $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : null,
            'meta'          => $meta,
            'terms'         => self::get_post_terms( $post_id ),
        ];
    }

    /* ── List posts ──────────────────────────────────────── */
    public static function list_posts( array $p ): array {
        $per_page = min( intval( $p['per_page'] ?? 20 ), self::MAX_PER_PAGE );

        $type = sanitize_key( $p['type'] ?? 'post' );
        if ( ! in_array( $type, self::allowed_post_types(), true ) ) {
            throw new InvalidArgumentException( "Post type '{$type}' is not supported." );
        }

        $args = [
            'post_type'      => $type,
            'post_status'    => 'publish', // default to published only for security
            'posts_per_page' => $per_page,
            'paged'          => max( 1, intval( $p['page'] ?? 1 ) ),
            's'              => sanitize_text_field( $p['search'] ?? '' ),
        ];

        if ( ! empty( $p['status'] ) ) {
            $status = sanitize_key( $p['status'] );
            if ( in_array( $status, self::ALLOWED_STATUSES, true ) || $status === 'any' ) {
                $args['post_status'] = $status;
            }
        }

        if ( ! empty( $p['author_id'] ) ) $args['author'] = intval( $p['author_id'] );

        $query = new WP_Query( $args );
        $posts = [];
        foreach ( $query->posts as $post ) {
            $posts[] = [
                'id'     => $post->ID,
                'title'  => $post->post_title,
                'status' => $post->post_status,
                'type'   => $post->post_type,
                'date'   => $post->post_date,
                'url'    => get_permalink( $post->ID ),
            ];
        }
        return [ 'posts' => $posts, 'total' => $query->found_posts ];
    }

    /* ── Delete post ─────────────────────────────────────── */
    public static function delete_post( array $p ): array {
        $post_id  = intval( $p['post_id'] ?? 0 );
        self::require_post( $post_id );
        $force    = ! empty( $p['force'] );
        $result   = wp_delete_post( $post_id, $force );
        if ( ! $result ) throw new Exception( "Could not delete post #{$post_id}." );
        AISEOC_Logger::log( 'warn', "Deleted post #{$post_id}" );
        return [ 'deleted' => true, 'post_id' => $post_id ];
    }

    /* ── Schedule post ───────────────────────────────────── */
    public static function schedule_post( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        $date    = sanitize_text_field( $p['date'] ?? '' );
        if ( ! $post_id || ! $date ) throw new Exception( 'post_id and date required.' );
        self::require_post( $post_id );

        $result = self::save_post( [
            'ID'            => $post_id,
            'post_status'   => 'future',
            'post_date'     => $date,
            'post_date_gmt' => get_gmt_from_date( $date ),
            // Without edit_date, WordPress discards the date on a draft and
            // publishes it immediately instead of scheduling it.
            'edit_date'     => true,
        ] );

        if ( is_wp_error( $result ) ) throw new Exception( $result->get_error_message() );
        AISEOC_Logger::log( 'info', "Scheduled post #{$post_id} for {$date}" );
        return [ 'post_id' => $post_id, 'scheduled_for' => $date ];
    }

    /* ── Featured image ──────────────────────────────────── */
    public static function set_featured_image( array $p ): array {
        $post_id  = intval( $p['post_id'] ?? 0 );
        $media_id = intval( $p['media_id'] ?? 0 );
        if ( ! $post_id ) throw new Exception( 'post_id required.' );
        self::require_post( $post_id );

        if ( $media_id ) {
            set_post_thumbnail( $post_id, $media_id );
            AISEOC_Logger::log( 'info', "Set featured image #{$media_id} on post #{$post_id}" );
            return [ 'post_id' => $post_id, 'thumbnail_id' => $media_id, 'caches_purged' => AISEOC_Cache::purge_post( $post_id ) ];
        } else {
            delete_post_thumbnail( $post_id );
            return [ 'post_id' => $post_id, 'thumbnail_id' => null, 'caches_purged' => AISEOC_Cache::purge_post( $post_id ) ];
        }
    }

    /* ── Taxonomies ──────────────────────────────────────── */
    public static function get_taxonomies( array $p ): array {
        $taxonomies = get_taxonomies( [], 'objects' );
        $result     = [];
        foreach ( $taxonomies as $slug => $tax ) {
            $terms = get_terms( [ 'taxonomy' => $slug, 'hide_empty' => false, 'number' => 200 ] );
            $result[ $slug ] = [
                'label' => $tax->label,
                'terms' => is_wp_error( $terms ) ? [] : array_map( fn($t) => [
                    'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug,
                ], $terms ),
            ];
        }
        return $result;
    }

    /* ── Assign terms ────────────────────────────────────── */
    public static function assign_terms( array $p ): array {
        $post_id  = intval( $p['post_id'] ?? 0 );
        $post     = self::require_post( $post_id );
        $taxonomy = sanitize_key( $p['taxonomy'] ?? 'category' );
        $term_ids = array_map( 'intval', (array) ( $p['term_ids'] ?? [] ) );
        $append   = ! empty( $p['append'] );

        if ( ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
            throw new InvalidArgumentException( "Taxonomy '{$taxonomy}' does not apply to this post type." );
        }

        wp_set_post_terms( $post_id, $term_ids, $taxonomy, $append );
        return [ 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'term_ids' => $term_ids ];
    }

    /* ── Helpers ─────────────────────────────────────────── */

    /**
     * Post types the content tools may touch: public ones (posts, pages,
     * products...), excluding attachments, which have their own media tools.
     * Private types such as orders, templates or changesets are out of reach.
     */
    public static function allowed_post_types(): array {
        return array_values( array_diff( get_post_types( [ 'public' => true ] ), [ 'attachment' ] ) );
    }

    /**
     * Load a post the content tools are allowed to touch. Posts of any other
     * type are reported as "not found" so the API doesn't confirm they exist.
     */
    public static function require_post( int $post_id ): WP_Post {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || ! in_array( $post->post_type, self::allowed_post_types(), true ) ) {
            throw new InvalidArgumentException( "Post #{$post_id} not found." );
        }
        return $post;
    }

    /** True if a meta key must never be read or written through this API. */
    public static function is_blocked_meta_key( string $key ): bool {
        $lower = strtolower( $key );
        foreach ( self::BLOCKED_META_PREFIXES as $prefix ) {
            if ( strpos( $lower, $prefix ) === 0 ) return true;
        }
        foreach ( self::BLOCKED_META_WORDS as $word ) {
            if ( strpos( $lower, $word ) !== false ) return true;
        }
        return false;
    }

    /**
     * Validate caller-supplied meta before anything is saved, so a rejected
     * key can't leave a half-finished create/update behind.
     */
    private static function checked_meta( $meta ): array {
        if ( empty( $meta ) || ! is_array( $meta ) ) return [];
        $clean = [];
        foreach ( $meta as $key => $value ) {
            $key = sanitize_key( $key );
            if ( $key === '' || self::is_blocked_meta_key( $key ) ) {
                throw new InvalidArgumentException( "Meta key '{$key}' can't be written through this API." );
            }
            $clean[ $key ] = $value;
        }
        return $clean;
    }

    /**
     * Insert or update a post without re-filtering fields the caller didn't send.
     *
     * Token requests run with no logged-in user, so WordPress's kses filters
     * are active for the whole request. wp_update_post() loads the existing
     * post and saves ALL of it again, so without this the existing content
     * would be filtered too -- silently stripping iframes, scripts and embeds
     * the site owner added, even on a title-only change. Callers sanitize the
     * fields they pass in; everything else is saved back exactly as it was.
     * kses_init() puts the filters back the way WordPress had them.
     *
     * @return int|WP_Error Post ID, or WP_Error on failure.
     */
    public static function save_post( array $args ) {
        kses_remove_filters();
        try {
            // wp_insert_post()/wp_update_post() expect slashed input.
            return empty( $args['ID'] )
                ? wp_insert_post( wp_slash( $args ), true )
                : wp_update_post( wp_slash( $args ), true );
        } finally {
            kses_init();
        }
    }

    private static function get_post_terms( int $post_id ): array {
        $taxonomies = get_post_taxonomies( $post_id );
        $result     = [];
        foreach ( $taxonomies as $tax ) {
            $terms = get_the_terms( $post_id, $tax );
            if ( $terms && ! is_wp_error( $terms ) ) {
                $result[ $tax ] = array_map( fn($t) => [
                    'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug,
                ], $terms );
            }
        }
        return $result;
    }

    /**
     * Return post meta with sensitive/internal keys stripped. Used by both
     * get_post and the MCP resources/read endpoint.
     */
    public static function safe_post_meta( int $post_id ): array {
        $raw    = get_post_meta( $post_id );
        $result = [];
        foreach ( $raw as $key => $values ) {
            if ( self::is_blocked_meta_key( (string) $key ) ) continue;
            $result[ $key ] = count( $values ) === 1 ? $values[0] : $values;
        }
        return $result;
    }
}
