<?php
class AISEOC_Logger {

    const OPTION_KEY = 'aiseoc_activity_log';
    const MAX_ENTRIES = 200;

    public static function log( string $level, string $message ): void {
        $log   = self::read_log();
        $log[] = [
            'time'    => current_time( 'c' ),
            'level'   => $level,
            'message' => $message,
        ];
        if ( count( $log ) > self::MAX_ENTRIES ) {
            $log = array_slice( $log, -self::MAX_ENTRIES );
        }
        update_option( self::OPTION_KEY, $log, false );
    }

    public static function get_recent( int $n = 50 ): array {
        return array_slice( array_reverse( self::read_log() ), 0, $n );
    }

    /**
     * Always return the log as an array, regardless of how the option was
     * stored -- some imports/migrations save it as a JSON string, which
     * would make array_reverse()/count() fatal on PHP 8+.
     */
    private static function read_log(): array {
        $log = get_option( self::OPTION_KEY, [] );
        if ( is_string( $log ) ) {
            $decoded = json_decode( $log, true );
            $log     = is_array( $decoded ) ? $decoded : [];
        }
        return is_array( $log ) ? $log : [];
    }

    public static function clear(): void {
        update_option( self::OPTION_KEY, [], false );
    }
}
