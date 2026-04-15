<?php
/**
 * Lightweight ring-buffer logger for Load Balanced Sync.
 *
 * Stores up to 200 entries in the lbs_log option.
 */

defined( 'ABSPATH' ) || exit;

class LBS_Logger {

	private const OPTION  = 'lbs_log';
	private const MAX     = 200;

	public static function info( string $message ): void {
		self::append( 'info', $message );
	}

	public static function error( string $message ): void {
		self::append( 'error', $message );
	}

	public static function warning( string $message ): void {
		self::append( 'warning', $message );
	}

	private static function append( string $level, string $message ): void {
		$log   = get_option( self::OPTION, array() );
		$log[] = array(
			'ts'      => time(),
			'level'   => $level,
			'message' => $message,
		);

		// Trim to the most recent MAX entries.
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}

		update_option( self::OPTION, $log, false );
	}

	/**
	 * Returns the most recent $count log entries in reverse-chronological order.
	 */
	public static function get( int $count = 50 ): array {
		$log = get_option( self::OPTION, array() );
		return array_reverse( array_slice( $log, -$count ) );
	}

	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
