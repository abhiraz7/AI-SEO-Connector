<?php
class AISEOC_Media {

    const MAX_PER_PAGE = 100;

    // MIME types that must never be uploaded regardless of filename.
    const BLOCKED_MIME_TYPES = [
        'application/x-php', 'application/php', 'application/x-httpd-php',
        'text/php', 'text/x-php', 'application/x-httpd-php-source',
        'application/x-perl', 'text/x-perl',
        'application/x-sh', 'text/x-sh',
        'application/x-csh',
        'application/x-python', 'text/x-python',
    ];

    // File extensions that must never be uploaded.
    const BLOCKED_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar',
        'pl', 'py', 'rb', 'cgi', 'sh', 'bash', 'asp', 'aspx', 'jsp',
        'exe', 'dll', 'so', 'bat', 'cmd',
    ];

    /* ── Upload from URL or base64 ───────────────────────── */
    public static function upload( array $p ): array {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $title   = sanitize_text_field( $p['title'] ?? '' );
        $alt     = sanitize_text_field( $p['alt'] ?? '' );
        $caption = sanitize_text_field( $p['caption'] ?? '' );
        $parent  = intval( $p['post_id'] ?? 0 );

        if ( ! empty( $p['url'] ) ) {
            $url = esc_url_raw( $p['url'] );
            self::assert_safe_url( $url );

            $tmp_file = download_url( $url );
            if ( is_wp_error( $tmp_file ) ) throw new Exception( 'Download failed: ' . $tmp_file->get_error_message() );

            $filename = sanitize_file_name( basename( parse_url( $url, PHP_URL_PATH ) ) ?: 'upload' );
            self::assert_safe_filename( $filename );

            $mime = wp_check_filetype( $filename )['type'] ?: 'image/jpeg';
            self::assert_safe_mime( $mime );

            $file_array = [
                'name'     => $filename,
                'tmp_name' => $tmp_file,
                'type'     => $mime,
                'error'    => 0,
                'size'     => filesize( $tmp_file ),
            ];

            $attach_id = media_handle_sideload( $file_array, $parent, $title );
            if ( is_wp_error( $attach_id ) ) {
                @unlink( $tmp_file );
                throw new Exception( $attach_id->get_error_message() );
            }

        } elseif ( ! empty( $p['base64'] ) ) {
            $filename = sanitize_file_name( $p['filename'] ?? 'upload.jpg' );
            self::assert_safe_filename( $filename );

            $data = base64_decode( $p['base64'] );
            if ( $data === false ) throw new Exception( 'Invalid base64 data.' );

            $finfo = new finfo( FILEINFO_MIME_TYPE );
            $detected_mime = $finfo->buffer( $data );
            self::assert_safe_mime( $detected_mime );

            $upload = wp_upload_bits( $filename, null, $data );
            if ( $upload['error'] ) throw new Exception( $upload['error'] );

            $attach_data = [
                'post_mime_type' => wp_check_filetype( $filename )['type'] ?: 'image/jpeg',
                'post_title'     => $title ?: pathinfo( $filename, PATHINFO_FILENAME ),
                'post_content'   => '',
                'post_status'    => 'inherit',
                'post_parent'    => $parent,
            ];

            $attach_id = wp_insert_attachment( $attach_data, $upload['file'], $parent );
            wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $upload['file'] ) );

        } else {
            throw new Exception( 'Provide url or base64.' );
        }

        if ( $alt )     update_post_meta( $attach_id, '_wp_attachment_image_alt', $alt );
        if ( $caption ) {
            AISEOC_Content::save_post( [ 'ID' => $attach_id, 'post_excerpt' => $caption ] );
        }

        AISEOC_Logger::log( 'info', "Uploaded media #{$attach_id}" );
        return [
            'media_id' => $attach_id,
            'url'      => wp_get_attachment_url( $attach_id ),
            'sizes'    => wp_get_attachment_image_src( $attach_id, 'full' ),
        ];
    }

    /* ── List media ──────────────────────────────────────── */
    public static function list_media( array $p ): array {
        $args = [
            'post_type'      => 'attachment',
            'post_status'    => 'inherit',
            'posts_per_page' => min( intval( $p['per_page'] ?? 20 ), self::MAX_PER_PAGE ),
            'paged'          => max( 1, intval( $p['page'] ?? 1 ) ),
            's'              => sanitize_text_field( $p['search'] ?? '' ),
            'post_mime_type' => sanitize_text_field( $p['mime_type'] ?? '' ),
        ];

        $query = new WP_Query( $args );
        $items = [];
        foreach ( $query->posts as $post ) {
            $items[] = [
                'id'    => $post->ID,
                'title' => $post->post_title,
                'url'   => wp_get_attachment_url( $post->ID ),
                'alt'   => get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
                'mime'  => $post->post_mime_type,
                'date'  => $post->post_date,
            ];
        }
        return [ 'media' => $items, 'total' => $query->found_posts ];
    }

    /* ── Get single media item ───────────────────────────── */
    public static function get_media( array $p ): array {
        $id   = intval( $p['media_id'] ?? 0 );
        $post = get_post( $id );
        if ( ! $post || $post->post_type !== 'attachment' ) throw new Exception( "Media #{$id} not found." );

        $meta = wp_get_attachment_metadata( $id );
        return [
            'id'          => $id,
            'title'       => $post->post_title,
            'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
            'caption'     => $post->post_excerpt,
            'description' => $post->post_content,
            'url'         => wp_get_attachment_url( $id ),
            'mime'        => $post->post_mime_type,
            'metadata'    => $meta,
            'sizes'       => self::get_sizes( $id ),
        ];
    }

    /* ── Delete media ────────────────────────────────────── */
    public static function delete_media( array $p ): array {
        $id = intval( $p['media_id'] ?? 0 );
        $result = wp_delete_attachment( $id, ! empty( $p['force'] ) );
        if ( ! $result ) throw new Exception( "Could not delete media #{$id}." );
        AISEOC_Logger::log( 'warn', "Deleted media #{$id}" );
        return [ 'deleted' => true, 'media_id' => $id ];
    }

    /* ── Update meta by media_id ──────────────────────────── */
    public static function update_meta( array $p ): array {
        $id = intval( $p['media_id'] ?? 0 );
        if ( ! $id ) throw new Exception( 'media_id required.' );

        $update = [ 'ID' => $id ];
        if ( isset( $p['title'] ) )       $update['post_title']   = sanitize_text_field( $p['title'] );
        if ( isset( $p['caption'] ) )     $update['post_excerpt'] = sanitize_text_field( $p['caption'] );
        if ( isset( $p['description'] ) ) $update['post_content'] = sanitize_textarea_field( $p['description'] );

        AISEOC_Content::save_post( $update );

        if ( isset( $p['alt'] ) ) {
            update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $p['alt'] ) );
        }

        AISEOC_Logger::log( 'info', "Updated media meta #{$id}" );
        return [ 'media_id' => $id, 'updated' => true ];
    }

    /* ── Update alt text by public URL ────────────────────
     * For images the caller only ever saw as a rendered <img src="...">
     * (e.g. a theme's logo or header/footer image set via the Customizer),
     * not as a media_id -- those have no wp-image-N class in the page
     * markup for a scraper to read, since they're not editor-inserted
     * attachments. This resolves the URL to a real attachment via
     * WordPress core's own attachment_url_to_postid(), the same lookup
     * WordPress itself uses internally, then writes alt text the normal
     * way (update_post_meta on _wp_attachment_image_alt).
     *
     * Fails loudly (not silently) when no attachment matches -- a
     * hotlinked/CDN image, or one whose URL no longer matches its stored
     * guid after a domain migration, genuinely cannot be resolved this
     * way and callers need to know that, not receive a fake success.
     */
    public static function update_alt_by_url( array $p ): array {
        $url = esc_url_raw( $p['url'] ?? '' );
        $alt = sanitize_text_field( $p['alt'] ?? '' );
        if ( ! $url ) throw new Exception( 'url required.' );

        $id = attachment_url_to_postid( $url );
        if ( ! $id ) {
            throw new Exception( "No media attachment found for URL: {$url}. It may be hosted off-site, hotlinked, or its stored path no longer matches this URL (e.g. after a domain change)." );
        }

        update_post_meta( $id, '_wp_attachment_image_alt', $alt );
        AISEOC_Logger::log( 'info', "Updated alt text for media #{$id} via URL lookup ({$url})" );
        return [ 'media_id' => $id, 'url' => $url, 'updated' => true ];
    }

    /* ── Security helpers ────────────────────────────────── */

    /**
     * Block SSRF: reject URLs that resolve to private/loopback ranges.
     */
    private static function assert_safe_url( string $url ): void {
        $parsed = parse_url( $url );
        $host   = $parsed['host'] ?? '';

        if ( empty( $host ) ) {
            throw new Exception( 'Invalid URL.' );
        }

        $blocked_hosts = [ 'localhost', '0.0.0.0', '::1', '[::]' ];
        if ( in_array( strtolower( $host ), $blocked_hosts, true ) ) {
            throw new Exception( 'URL host is not allowed.' );
        }

        $ip = filter_var( $host, FILTER_VALIDATE_IP )
            ? $host
            : gethostbyname( $host );

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            throw new Exception( 'Could not resolve URL host.' );
        }

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
            throw new Exception( 'URL resolves to a private or reserved address and is not allowed.' );
        }
    }

    private static function assert_safe_filename( string $filename ): void {
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
            throw new Exception( "File extension '.{$ext}' is not allowed." );
        }
    }

    private static function assert_safe_mime( string $mime ): void {
        $mime = strtolower( $mime );
        if ( in_array( $mime, self::BLOCKED_MIME_TYPES, true ) ) {
            throw new Exception( "MIME type '{$mime}' is not allowed." );
        }
        if ( strpos( $mime, 'php' ) !== false ) {
            throw new Exception( "MIME type '{$mime}' is not allowed." );
        }
    }

    /* ── Helper ──────────────────────────────────────────── */
    private static function get_sizes( int $id ): array {
        $sizes = get_intermediate_image_sizes();
        $out   = [];
        foreach ( $sizes as $size ) {
            $src = wp_get_attachment_image_src( $id, $size );
            if ( $src ) $out[ $size ] = [ 'url' => $src[0], 'width' => $src[1], 'height' => $src[2] ];
        }
        return $out;
    }
}
