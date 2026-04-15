<?php
/**
 * Lightweight ring-buffer logger for Load Balanced Sync.
 *
 * Stores up to 200 entries in the lbs_log option.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads plugin log entries from wp_options.
 */
class LBS_Logger {

	private const OPTION = 'lbs_log';
	private const MAX    = 200;

	/**
	 * Log an informational message.
	 *
	 * @param string $message Log message.
	 */
	public static function info( string $message ): void {
		self::append( 'info', $message );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $message Log message.
	 */
	public static function error( string $message ): void {
		self::append( 'error', $message );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Log message.
	 */
	public static function warning( string $message ): void {
		self::append( 'warning', $message );
	}

	/**
	 * Append an entry to the bounded log store.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
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
	 *
	 * @param int $count Maximum entries to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get( int $count = 50 ): array {
		$log = get_option( self::OPTION, array() );
		return array_reverse( array_slice( $log, -$count ) );
	}

	/**
	 * Clear all stored log entries.
	 */
	public static function clear(): void {
		update_option( self::OPTION, array(), false );
	}
}
