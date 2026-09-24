<?php
/**
 * Derives the connection state shown in the admin UI.
 *
 * The state is OBSERVED, not configured: it comes from when an authenticated
 * request last reached this site (recorded by AISEOC_Auth), plus whether the
 * connection is paused. compute() is a pure function of its inputs so every
 * state can be asserted without a live site.
 *
 *   green  Connected            authenticated contact within the last 7 days
 *   white  Awaiting connection  no authenticated contact with the current credentials yet
 *   yellow Idle                 connected before, but no contact for over 7 days
 *   red    Paused               the connection was paused here; all requests are rejected
 */
class AISEOC_Status {

    const CONTACT_OPTION = 'aiseoc_last_contact';
    const STALE_AFTER    = 604800; // 7 days, in seconds.

    public static function current(): array {
        $enabled = ! empty( get_option( 'aiseoc_enabled', '1' ) );
        $contact = get_option( self::CONTACT_OPTION, null );
        return self::compute( $enabled, is_array( $contact ) ? $contact : null, time() );
    }

    /**
     * @param bool       $enabled Whether requests are currently accepted.
     * @param array|null $contact [ 'ts' => int, 'method' => 'token'|'app_password' ] or null.
     */
    public static function compute( bool $enabled, ?array $contact, int $now ): array {
        if ( ! $enabled ) {
            return self::state( 'paused', 'red', 'Paused',
                'Every request is rejected until you resume the connection.', null );
        }

        $ts = is_array( $contact ) ? (int) ( $contact['ts'] ?? 0 ) : 0;
        if ( $ts <= 0 ) {
            return self::state( 'awaiting', 'white', 'Awaiting connection',
                'Paste the API base URL and token into your SEO platform to connect this site.', null );
        }

        $method = ( $contact['method'] ?? '' ) === 'app_password' ? ' via application password' : ' via token';
        $ago    = human_time_diff( $ts, $now ) . ' ago';

        if ( ( $now - $ts ) <= self::STALE_AFTER ) {
            return self::state( 'connected', 'green', 'Connected',
                "Last contact {$ago}{$method}.", $ts );
        }

        return self::state( 'idle', 'yellow', 'Idle',
            "Last contact {$ago}. Nothing is wrong: the credentials are still valid, your platform just hasn't needed this site recently.", $ts );
    }

    private static function state( string $state, string $color, string $label, string $detail, ?int $ts ): array {
        return [ 'state' => $state, 'color' => $color, 'label' => $label, 'detail' => $detail, 'ts' => $ts ];
    }
}
