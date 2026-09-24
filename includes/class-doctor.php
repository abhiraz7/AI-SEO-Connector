<?php
/**
 * "Doctor" -- on-demand diagnostics for the connection path.
 *
 * Each check returns { id, label, status, detail, fix } where status is one of
 * pass | warn | fail | info. Checks never throw and never change site state;
 * the one network check is a real request to this site's own API, made with
 * the stored token, so it exercises the same path the platform uses
 * (DNS -> web server -> WordPress -> REST route -> auth). It carries an
 * X-AISEOC-Doctor header so AISEOC_Auth does not count it as platform contact.
 *
 * QA analogy: a smoke test for the integration -- cheap, ordered from
 * environment prerequisites to end-to-end, each with an actionable failure
 * message instead of a bare "failed".
 */
class AISEOC_Doctor {

    const MIN_PHP = '7.4';
    const MIN_WP  = '5.6';

    public static function run(): array {
        $checks = [
            self::check_php(),
            self::check_wordpress(),
            self::check_https(),
            self::check_permalinks(),
            self::check_token(),
            self::check_paused(),
            self::check_rest_roundtrip(),
            self::check_seo_plugin(),
            self::check_application_passwords(),
            self::check_memory(),
            self::check_updates(),
        ];

        $summary = [ 'pass' => 0, 'warn' => 0, 'fail' => 0, 'info' => 0 ];
        foreach ( $checks as $c ) {
            $summary[ $c['status'] ]++;
        }

        return [ 'checks' => $checks, 'summary' => $summary, 'ran_at' => time() ];
    }

    private static function result( string $id, string $label, string $status, string $detail, string $fix = '' ): array {
        return [ 'id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail, 'fix' => $fix ];
    }

    /* ── Environment ─────────────────────────────────────── */

    private static function check_php(): array {
        if ( version_compare( PHP_VERSION, self::MIN_PHP, '>=' ) ) {
            return self::result( 'php', 'PHP version', 'pass', 'PHP ' . PHP_VERSION . ' (minimum ' . self::MIN_PHP . ').' );
        }
        return self::result( 'php', 'PHP version', 'fail',
            'PHP ' . PHP_VERSION . ' is older than the required ' . self::MIN_PHP . '.',
            'Ask your host to switch this site to PHP ' . self::MIN_PHP . ' or newer.' );
    }

    private static function check_wordpress(): array {
        $v = get_bloginfo( 'version' );
        if ( version_compare( $v, self::MIN_WP, '>=' ) ) {
            return self::result( 'wp', 'WordPress version', 'pass', "WordPress {$v} (minimum " . self::MIN_WP . ').' );
        }
        return self::result( 'wp', 'WordPress version', 'fail',
            "WordPress {$v} is older than the required " . self::MIN_WP . '.',
            'Update WordPress from Dashboard > Updates.' );
    }

    private static function check_https(): array {
        $scheme = wp_parse_url( home_url(), PHP_URL_SCHEME );
        if ( $scheme === 'https' ) {
            return self::result( 'https', 'HTTPS', 'pass', 'Site is served over HTTPS, so the token is encrypted in transit.' );
        }
        return self::result( 'https', 'HTTPS', 'warn',
            'This site is served over plain HTTP, so the token and any application password travel unencrypted.',
            'Install an SSL certificate and set the Site Address to https:// under Settings > General.' );
    }

    private static function check_permalinks(): array {
        if ( get_option( 'permalink_structure' ) ) {
            return self::result( 'permalinks', 'Permalinks', 'pass', 'Pretty permalinks are on, so /wp-json/ URLs resolve.' );
        }
        return self::result( 'permalinks', 'Permalinks', 'warn',
            'Plain permalinks are in use; the /wp-json/ API base URL shown above may return 404.',
            'Open Settings > Permalinks, choose any option other than Plain, and save.' );
    }

    private static function check_memory(): array {
        $bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( WP_MEMORY_LIMIT ) : 0;
        if ( $bytes >= 64 * MB_IN_BYTES ) {
            return self::result( 'memory', 'PHP memory', 'pass', 'WordPress memory limit is ' . WP_MEMORY_LIMIT . '.' );
        }
        return self::result( 'memory', 'PHP memory', 'warn',
            'WordPress memory limit is ' . WP_MEMORY_LIMIT . '; media uploads and large posts may fail.',
            "Raise it with define( 'WP_MEMORY_LIMIT', '128M' ); in wp-config.php, or ask your host." );
    }

    /* ── Connection state ────────────────────────────────── */

    private static function check_token(): array {
        $token = (string) get_option( 'aiseoc_api_token', '' );
        if ( strlen( $token ) >= 32 ) {
            return self::result( 'token', 'API token', 'pass', 'A token is configured (' . strlen( $token ) . ' characters).' );
        }
        return self::result( 'token', 'API token', 'fail',
            'No valid API token is stored, so no request can authenticate.',
            'Click Regenerate in the Connection panel to create one.' );
    }

    private static function check_paused(): array {
        if ( ! empty( get_option( 'aiseoc_enabled', '1' ) ) ) {
            return self::result( 'paused', 'Connection state', 'pass', 'The connection is active and accepting requests.' );
        }
        return self::result( 'paused', 'Connection state', 'warn',
            'The connection is paused, so every request is rejected.',
            'Click Resume connection at the top of this screen.' );
    }

    /** Real HTTP request to this site's own API, exactly as the platform would make it. */
    private static function check_rest_roundtrip(): array {
        $token = (string) get_option( 'aiseoc_api_token', '' );
        if ( empty( get_option( 'aiseoc_enabled', '1' ) ) || $token === '' ) {
            return self::result( 'rest', 'API round-trip', 'info', 'Skipped: the connection is paused or has no token.' );
        }

        $url   = AISEOC_Router::api_base() . '/ping';
        $start = microtime( true );
        $res   = wp_remote_get( $url, [
            'timeout'     => 8,
            'redirection' => 3,
            'headers'     => [
                'Authorization'   => 'Bearer ' . $token,
                'X-AISEOC-Doctor' => '1',
                'Accept'          => 'application/json',
            ],
        ] );

        if ( is_wp_error( $res ) ) {
            return self::result( 'rest', 'API round-trip', 'warn',
                'This server could not reach its own API (' . $res->get_error_message() . '). Some hosts block loopback requests; that does not always mean the platform is blocked.',
                'Ask your host whether loopback HTTP requests are allowed, then verify with your platform\'s connection test.' );
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $ms   = (int) round( ( microtime( true ) - $start ) * 1000 );
        $body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
        $err  = is_array( $body ) ? (string) ( $body['code'] ?? '' ) : '';

        if ( $code === 200 && is_array( $body ) && ( $body['status'] ?? '' ) === 'ok' ) {
            return self::result( 'rest', 'API round-trip', 'pass', "Authenticated request to {$url} succeeded in {$ms} ms." );
        }
        if ( $code === 200 ) {
            return self::result( 'rest', 'API round-trip', 'warn',
                'The API URL returned HTTP 200 but not the expected JSON; a cache or maintenance plugin may be intercepting it.',
                'Exclude /wp-json/ from page caching and maintenance modes.' );
        }
        if ( $code === 401 && $err === 'aiseoc_no_auth' ) {
            return self::result( 'rest', 'API round-trip', 'fail',
                'The Authorization header did not reach WordPress: your server is stripping it, so token authentication cannot work.',
                'On Apache/CGI add: SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1 to .htaccess, or ask your host to pass Authorization headers through.' );
        }
        if ( $code === 404 ) {
            return self::result( 'rest', 'API round-trip', 'fail',
                'The API route returned 404 (not found).',
                'Save Settings > Permalinks once (choose anything but Plain), and check no plugin disables the REST API.' );
        }
        if ( $code === 403 || $code === 401 ) {
            return self::result( 'rest', 'API round-trip', 'fail',
                "The request was rejected (HTTP {$code}" . ( $err ? ", {$err}" : '' ) . '), likely by a security plugin or firewall in front of WordPress.',
                'Allow /wp-json/aiseoc/ in your security plugin or WAF, or regenerate the token if it was rejected as invalid.' );
        }
        if ( $code === 429 ) {
            return self::result( 'rest', 'API round-trip', 'warn', 'The rate limiter is currently blocking this server\'s address.', 'Wait 15 minutes and run the Doctor again.' );
        }
        return self::result( 'rest', 'API round-trip', 'warn', "Unexpected response: HTTP {$code}." );
    }

    /* ── Integrations ────────────────────────────────────── */

    private static function check_seo_plugin(): array {
        if ( defined( 'WPSEO_VERSION' ) ) {
            return self::result( 'seo', 'SEO plugin', 'pass', 'Yoast SEO detected; SEO meta tools are supported.' );
        }
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            return self::result( 'seo', 'SEO plugin', 'pass', 'RankMath detected; SEO meta tools are supported.' );
        }
        return self::result( 'seo', 'SEO plugin', 'warn',
            'Neither Yoast SEO nor RankMath is active. SEO meta writes would land in fields no plugin reads.',
            'Install and activate Yoast SEO or RankMath to use the SEO tool group.' );
    }

    private static function check_application_passwords(): array {
        $ok = function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available();
        if ( $ok ) {
            return self::result( 'apppw', 'Application Passwords', 'pass', 'WordPress Application Passwords are available as an alternative sign-in.' );
        }
        return self::result( 'apppw', 'Application Passwords', 'info',
            'WordPress has Application Passwords disabled here (common on HTTP sites or with some security plugins). Token sign-in is unaffected.' );
    }

    private static function check_updates(): array {
        $checker = $GLOBALS['aiseoc_update_checker'] ?? null;
        if ( ! $checker ) {
            return self::result( 'updates', 'Auto-updates', 'warn',
                'The update checker is not loaded, so this site will not be offered new versions.',
                'Reinstall the plugin from the latest release zip.' );
        }
        try {
            $update = method_exists( $checker, 'getUpdate' ) ? $checker->getUpdate() : null;
            if ( $update && ! empty( $update->version ) && version_compare( $update->version, AISEOC_VERSION, '>' ) ) {
                return self::result( 'updates', 'Auto-updates', 'warn',
                    "Version {$update->version} is available (running " . AISEOC_VERSION . ').',
                    'Open Plugins and click Update now.' );
            }
            return self::result( 'updates', 'Auto-updates', 'pass', 'Running ' . AISEOC_VERSION . '; no newer release found at the last check.' );
        } catch ( \Throwable $e ) {
            return self::result( 'updates', 'Auto-updates', 'warn', 'Could not read update status: ' . $e->getMessage() );
        }
    }
}
