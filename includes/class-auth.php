<?php
/**
 * Handles authentication for every AI SEO Connector request.
 * Supports two methods:
 *  1. Bearer token  — Authorization: Bearer <aiseoc_api_token>
 *  2. Basic Auth    — Authorization: Basic base64(wp_username:application_password)
 *                     WordPress validates the Application Password automatically for REST requests.
 *
 * Security hardening:
 *  - Per-IP rate limiting of FAILED attempts only: max 20 per 15 minutes
 *    (transient-based). Valid credentials are checked first and never limited.
 *  - Application Password raw value is never stored on disk; only username is kept.
 *  - Bearer token comparison uses hash_equals() -- constant-time, avoids
 *    leaking the correct token one byte at a time via response-time analysis.
 */
class AISEOC_Auth {

    const RATE_LIMIT_ATTEMPTS = 20;
    const RATE_LIMIT_WINDOW   = 900; // 15 minutes in seconds

    public static function init() {}

    public static function permission_callback( WP_REST_Request $request ) {
        if ( ! get_option( 'aiseoc_enabled', '1' ) ) {
            return new WP_Error( 'aiseoc_disabled', 'AI SEO Connector is disabled.', [ 'status' => 503 ] );
        }

        $ip     = sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0' );
        $header = $request->get_header( 'Authorization' );

        if ( empty( $header ) ) {
            return new WP_Error( 'aiseoc_no_auth', 'Authorization header missing. Use Bearer token or Basic Auth (Application Password).', [ 'status' => 401 ] );
        }

        // Credentials are checked BEFORE the rate limit: a valid token or
        // Application Password always gets through, so failed attempts from
        // the same IP (e.g. a shared proxy/CDN address) can't lock out the
        // real connection. Only failures are limited -- see fail().

        /* ── Bearer token (custom API token) ──────────────── */
        if ( strpos( $header, 'Bearer ' ) === 0 ) {
            $stored = get_option( 'aiseoc_api_token', '' );
            if ( empty( $stored ) ) {
                return new WP_Error( 'aiseoc_no_token', 'No API token configured.', [ 'status' => 500 ] );
            }
            if ( hash_equals( $stored, substr( $header, 7 ) ) ) {
                self::touch_last_contact( 'token', $request );
                return true;
            }
            return self::fail( $ip, 'Bearer token', new WP_Error( 'aiseoc_forbidden', 'Invalid token.', [ 'status' => 403 ] ) );
        }

        /* ── Basic Auth (WordPress Application Password) ───── */
        if ( strpos( $header, 'Basic ' ) === 0 ) {
            if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
                self::touch_last_contact( 'app_password', $request );
                return true;
            }
            return self::fail( $ip, 'Basic Auth', new WP_Error( 'aiseoc_forbidden', 'Invalid Application Password or insufficient permissions.', [ 'status' => 403 ] ) );
        }

        return new WP_Error( 'aiseoc_bad_auth', 'Expected Bearer token or Basic Auth (Application Password).', [ 'status' => 401 ] );
    }

    /**
     * Handle a failed attempt. Under the limit: count it, log it, return the
     * original error. Over the limit: return 429 and log only once per
     * window, so a flood of bad requests can't turn into a flood of
     * database writes (each log call rewrites the activity-log option).
     */
    private static function fail( string $ip, string $method, WP_Error $error ): WP_Error {
        if ( self::is_rate_limited( $ip ) ) {
            $flag = self::rate_key( $ip ) . '_logged';
            if ( ! get_transient( $flag ) ) {
                set_transient( $flag, 1, self::RATE_LIMIT_WINDOW );
                AISEOC_Logger::log( 'warn', "Rate limit hit from {$ip}" );
            }
            return new WP_Error( 'aiseoc_rate_limited', 'Too many failed attempts. Try again later.', [ 'status' => 429 ] );
        }
        self::record_failed_attempt( $ip );
        AISEOC_Logger::log( 'warn', "Failed {$method} attempt from {$ip}" );
        return $error;
    }

    /** Regenerate Bearer token and return new value. */
    public static function regenerate_token(): string {
        $token = bin2hex( random_bytes( 32 ) );
        update_option( 'aiseoc_api_token', $token );

        // The new token has never been used, so a "connected" state earned
        // with the old token no longer holds -- back to awaiting.
        $prev = get_option( AISEOC_Status::CONTACT_OPTION );
        if ( is_array( $prev ) && ( $prev['method'] ?? '' ) === 'token' ) {
            delete_option( AISEOC_Status::CONTACT_OPTION );
        }

        AISEOC_Logger::log( 'info', 'API token regenerated.' );
        return $token;
    }

    /**
     * Remember when an authenticated request last succeeded, so the admin UI
     * can show a real connection state. Writes are throttled to one per
     * minute per method so a busy platform doesn't cause a DB write per
     * request. The Doctor's self-test is excluded -- it is this site talking
     * to itself, not the platform connecting.
     */
    private static function touch_last_contact( string $method, WP_REST_Request $request ): void {
        if ( $request->get_header( 'X-AISEOC-Doctor' ) ) {
            return;
        }
        $now  = time();
        $prev = get_option( AISEOC_Status::CONTACT_OPTION );
        if ( is_array( $prev ) && ( $prev['method'] ?? '' ) === $method && ( $now - (int) ( $prev['ts'] ?? 0 ) ) < 60 ) {
            return;
        }
        update_option( AISEOC_Status::CONTACT_OPTION, [ 'ts' => $now, 'method' => $method ], false );
    }

    /**
     * Create (or recreate) a WordPress Application Password for the current admin.
     * The raw password is returned once and NEVER persisted to the database.
     * Only the username is stored for display purposes.
     */
    public static function create_application_password(): array {
        if ( ! class_exists( 'WP_Application_Passwords' ) ) {
            throw new Exception( 'WordPress Application Passwords require WordPress 5.6 or newer.' );
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            throw new Exception( 'No logged-in user found.' );
        }

        $existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
        foreach ( $existing as $app ) {
            // Matches the current name and the name used by earlier releases,
            // so an Application Password created before the rename is still
            // replaced cleanly instead of being left behind, still valid.
            if ( in_array( $app['name'], [ 'AI SEO Connector', 'VtechSEO Agent' ], true ) ) {
                WP_Application_Passwords::delete_application_password( $user_id, $app['uuid'] );
            }
        }

        // WordPress disables Application Passwords on non-SSL sites by default.
        // Force-enable for this tool, then restore immediately after.
        add_filter( 'wp_is_application_passwords_available',          '__return_true' );
        add_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );

        $result = WP_Application_Passwords::create_new_application_password(
            $user_id,
            [ 'name' => 'AI SEO Connector' ]
        );

        remove_filter( 'wp_is_application_passwords_available',          '__return_true' );
        remove_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );

        if ( is_wp_error( $result ) ) {
            throw new Exception( $result->get_error_message() );
        }

        [ $raw_password ] = $result;

        update_option( 'aiseoc_app_username', $user->user_login );
        delete_option( 'aiseoc_app_password' ); // Remove any legacy plaintext value.

        AISEOC_Logger::log( 'info', "Application Password created for: {$user->user_login}" );

        return [
            'username' => $user->user_login,
            'password' => $raw_password,
            'encoded'  => base64_encode( $user->user_login . ':' . str_replace( ' ', '', $raw_password ) ),
        ];
    }

    /* ── Rate limiting helpers ──────────────────────────── */

    private static function rate_key( string $ip ): string {
        return 'aiseoc_rl_' . md5( $ip );
    }

    private static function is_rate_limited( string $ip ): bool {
        $attempts = (int) get_transient( self::rate_key( $ip ) );
        return $attempts >= self::RATE_LIMIT_ATTEMPTS;
    }

    private static function record_failed_attempt( string $ip ): void {
        $key      = self::rate_key( $ip );
        $attempts = (int) get_transient( $key );
        set_transient( $key, $attempts + 1, self::RATE_LIMIT_WINDOW );
    }
}
