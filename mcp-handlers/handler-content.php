<?php
class AISEOC_Content {

    const MAX_PER_PAGE     = 100;
    const ALLOWED_STATUSES = [ 'draft', 'publish', 'private', 'pending', 'future', 'trash' ];

    /* ── Create post ─────────────────────────────────────── */
    public static function create_post( array $p ): array {
        $status = sanitize_key( $p['status'] ?? 'draft' );
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) {
            $status = 'draft';
        }

        $args = [
            'post_title'    => sanitize_text_field( $p['title'] ?? 'Untitled' ),
            'post_content'  => wp_kses_post( $p['content'] ?? '' ),
            'post_status'   => $status,
            'post_type'     => sanitize_key( $p['type'] ?? 'post' ),
            'post_excerpt'  => sanitize_textarea_field( $p['excerpt'] ?? '' ),
            'post_password' => sanitize_text_field( $p['password'] ?? '' ),
            'post_author'   => intval( $p['author_id'] ?? get_current_user_id() ),
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

        $post_id = wp_insert_post( $args, true );
        if ( is_wp_error( $post_id ) ) throw new Exception( $post_id->get_error_message() );

        if ( ! empty( $p['meta'] ) && is_array( $p['meta'] ) ) {
            foreach ( $p['meta'] as $key => $value ) {
                update_post_meta( $post_id, sanitize_key( $key ), $value );
            }
        }

        if ( ! empty( $p['terms'] ) && is_array( $p['terms'] ) ) {
            foreach ( $p['terms'] as $taxonomy => $term_ids ) {
                wp_set_post_terms( $post_id, array_map( 'intval', $term_ids ), sanitize_key( $taxonomy ) );
            }
        }

        AISEOC_Logger::log( 'info', "Created post #{$post_id}: {$args['post_title']}" );
        return [ 'post_id' => $post_id, 'url' => get_permalink( $post_id ) ];
    }

    /* ── Update post ─────────────────────────────────────── */
    public static function update_post( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        if ( ! $post_id ) throw new Exception( 'post_id required.' );

        $args = [ 'ID' => $post_id ];

        if ( isset( $p['title'] ) )   $args['post_title']   = sanitize_text_field( $p['title'] );
        if ( isset( $p['content'] ) ) $args['post_content'] = wp_kses_post( $p['content'] );
        if ( isset( $p['excerpt'] ) ) $args['post_excerpt'] = sanitize_textarea_field( $p['excerpt'] );
        if ( isset( $p['slug'] ) )    $args['post_name']    = sanitize_title( $p['slug'] );
        if ( isset( $p['date'] ) )    $args['post_date']    = sanitize_text_field( $p['date'] );

        if ( isset( $p['status'] ) ) {
            $status = sanitize_key( $p['status'] );
            $args['post_status'] = in_array( $status, self::ALLOWED_STATUSES, true ) ? $status : 'draft';
        }

        $result = wp_update_post( $args, true );
        if ( is_wp_error( $result ) ) throw new Exception( $result->get_error_message() );

        if ( ! empty( $p['meta'] ) ) {
            foreach ( $p['meta'] as $key => $value ) {
                update_post_meta( $post_id, sanitize_key( $key ), $value );
            }
        }

        AISEOC_Logger::log( 'info', "Updated post #{$post_id}" );
        return [ 'post_id' => $post_id, 'url' => get_permalink( $post_id ) ];
    }

    /* ── Get post ─────────────────────────────────────────── */
    public static function get_post( array $p ): array {
        $post_id = intval( $p['post_id'] ?? 0 );
        $post    = get_post( $post_id );
        if ( ! $post ) throw new Exception( "Post #{$post_id} not found." );

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

        $args = [
            'post_type'      => sanitize_key( $p['type'] ?? 'post' ),
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

        $result = wp_update_post( [
            'ID'            => $post_id,
            'post_status'   => 'future',
            'post_date'     => $date,
            'post_date_gmt' => get_gmt_from_date( $date ),
        ], true );

        if ( is_wp_error( $result ) ) throw new Exception( $result->get_error_message() );
        AISEOC_Logger::log( 'info', "Scheduled post #{$post_id} for {$date}" );
        return [ 'post_id' => $post_id, 'scheduled_for' => $date ];
    }

    /* ── Featured image ──────────────────────────────────── */
    public static function set_featured_image( array $p ): array {
        $post_id  = intval( $p['post_id'] ?? 0 );
        $media_id = intval( $p['media_id'] ?? 0 );
        if ( ! $post_id ) throw new Exception( 'post_id required.' );

        if ( $media_id ) {
            set_post_thumbnail( $post_id, $media_id );
            AISEOC_Logger::log( 'info', "Set featured image #{$media_id} on post #{$post_id}" );
            return [ 'post_id' => $post_id, 'thumbnail_id' => $media_id ];
        } else {
            delete_post_thumbnail( $post_id );
            return [ 'post_id' => $post_id, 'thumbnail_id' => null ];
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
        $taxonomy = sanitize_key( $p['taxonomy'] ?? 'category' );
        $term_ids = array_map( 'intval', $p['term_ids'] ?? [] );
        $append   = ! empty( $p['append'] );

        wp_set_post_terms( $post_id, $term_ids, $taxonomy, $append );
        return [ 'post_id' => $post_id, 'taxonomy' => $taxonomy, 'term_ids' => $term_ids ];
    }

    /* ── Helpers ─────────────────────────────────────────── */

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
     * Return post meta with sensitive/internal keys stripped.
     */
    private static function safe_post_meta( int $post_id ): array {
        $blocked_prefixes = [
            '_stripe_', '_paypal_', '_wc_', '_edd_', '_password',
            '_auth_', 'session_', '_transient_', 'auth_key',
        ];
        $blocked_keys = [
            '_wp_page_template', '_edit_lock', '_edit_last',
        ];

        $raw    = get_post_meta( $post_id );
        $result = [];
        foreach ( $raw as $key => $values ) {
            if ( in_array( $key, $blocked_keys, true ) ) continue;
            foreach ( $blocked_prefixes as $prefix ) {
                if ( strpos( $key, $prefix ) === 0 ) continue 2;
            }
            $result[ $key ] = count( $values ) === 1 ? $values[0] : $values;
        }
        return $result;
    }
}
