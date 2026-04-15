<?php
/**
 * Lightweight ring-buffer logger for Load Balanced Sync.
 *
 * Stores entries as JSONL files in the uploads directory.
 *
 * @package LoadBalancedSync
 */

defined( 'ABSPATH' ) || exit;

/**
 * Writes and reads plugin log entries from JSONL files.
 */
class LBS_Logger {

	private const SERVER_UUID_KEY = 'lbs_own_initiator_uuid';
	private const LOG_DIR_SEGMENT = 'load-balanced-sync/logs';

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
	 * Append an entry to the current server/week JSONL log file.
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function append( string $level, string $message ): void {
		$entry = array(
			'ts'      => time(),
			'level'   => $level,
			'message' => $message,
			'uuid'    => self::get_server_uuid(),
		);

		$path = self::get_current_log_file_path();
		if ( ! $path ) {
			return;
		}

		$line = wp_json_encode( $entry );
		if ( false === $line ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Atomic append with lock for JSONL logging.
		file_put_contents( $path, $line . PHP_EOL, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Returns the most recent $count log entries in reverse-chronological order.
	 *
	 * @param int $count Maximum entries to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get( int $count = 50 ): array {
		$count   = max( 1, $count );
		$entries = array();

		foreach ( self::get_server_log_files_desc() as $path ) {
			foreach ( self::read_entries_from_file_desc( $path ) as $entry ) {
				$entries[] = $entry;
				if ( count( $entries ) >= $count ) {
					return $entries;
				}
			}
		}

		return array();
	}

	/**
	 * Clear all stored log entries for this server UUID.
	 */
	public static function clear(): void {
		foreach ( self::get_server_log_files_desc() as $path ) {
			if ( ! is_file( $path ) ) {
				continue;
			}

			wp_delete_file( $path );
		}
	}

	/**
	 * Get log storage metadata for admin diagnostics.
	 *
	 * @return array<string,string>
	 */
	public static function get_storage_meta(): array {
		$uuid         = self::get_server_uuid();
		$dir          = self::get_log_dir() ?? '';
		$year         = gmdate( 'o' );
		$week         = gmdate( 'W' );
		$file_pattern = $uuid . '-YYYY-Www.jsonl';
		$current_file = $uuid . '-' . $year . '-W' . $week . '.jsonl';
		$file_count   = (string) count( self::get_server_log_files_desc() );

		return array(
			'uuid'         => $uuid,
			'directory'    => $dir,
			'file_pattern' => $file_pattern,
			'current_file' => $current_file,
			'file_count'   => $file_count,
		);
	}

	/**
	 * Get JSONL log files for this server UUID, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_log_files(): array {
		$files = array();

		foreach ( self::get_server_log_files_desc() as $path ) {
			$filename = wp_basename( $path );
			if ( '' === $filename ) {
				continue;
			}

			$size = 0;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Reading local file size for admin metadata.
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Reading local file size for admin metadata.
				$size = (int) filesize( $path );
			}

			$mtime = 0;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime -- Reading local file mtime for admin metadata.
			if ( is_file( $path ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime -- Reading local file mtime for admin metadata.
				$mtime = (int) filemtime( $path );
			}

			$files[] = array(
				'filename' => $filename,
				'size'     => $size,
				'mtime'    => $mtime,
			);
		}

		return $files;
	}

	/**
	 * Resolve a server log file path from a filename.
	 *
	 * @param string $filename Basename of log file.
	 * @return string|null
	 */
	public static function get_log_file_path_by_name( string $filename ): ?string {
		$filename = wp_basename( $filename );
		if ( '' === $filename ) {
			return null;
		}

		$map = self::get_server_log_file_map();
		return $map[ $filename ] ?? null;
	}

	/**
	 * Get parsed entries for one log file by filename.
	 *
	 * @param string $filename Log filename.
	 * @param int    $count    Maximum entries to return.
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_entries_by_filename( string $filename, int $count = 500 ): array {
		$path = self::get_log_file_path_by_name( $filename );
		if ( ! $path ) {
			return array();
		}

		$count   = max( 1, $count );
		$entries = self::read_entries_from_file_desc( $path );

		if ( count( $entries ) > $count ) {
			$entries = array_slice( $entries, 0, $count );
		}

		return $entries;
	}

	/**
	 * Get the current server UUID used in log file names.
	 *
	 * @return string
	 */
	private static function get_server_uuid(): string {
		$uuid = get_option( self::SERVER_UUID_KEY );

		if ( ! is_string( $uuid ) || '' === $uuid ) {
			$uuid = wp_generate_uuid4();
			update_option( self::SERVER_UUID_KEY, $uuid, false );
		}

		return preg_replace( '/[^a-zA-Z0-9-]/', '', $uuid );
	}

	/**
	 * Get the absolute log directory path.
	 *
	 * @return string|null
	 */
	private static function get_log_dir(): ?string {
		$uploads = wp_get_upload_dir();
		$basedir = $uploads['basedir'] ?? '';

		if ( ! is_string( $basedir ) || '' === $basedir ) {
			$basedir = WP_CONTENT_DIR . '/uploads';
		}

		$dir = trailingslashit( $basedir ) . self::LOG_DIR_SEGMENT;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir -- Fast writable directory check before mkdir.
		if ( ! is_dir( $dir ) ) {
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Ensure append target is writable.
		if ( ! is_writable( $dir ) ) {
			return null;
		}

		return $dir;
	}

	/**
	 * Build the current weekly log file path for this server UUID.
	 *
	 * @return string|null
	 */
	private static function get_current_log_file_path(): ?string {
		$dir = self::get_log_dir();
		if ( ! $dir ) {
			return null;
		}

		$year = gmdate( 'o' );
		$week = gmdate( 'W' );

		return trailingslashit( $dir ) . self::get_server_uuid() . '-' . $year . '-W' . $week . '.jsonl';
	}

	/**
	 * Get all JSONL log files for this server in newest-first order.
	 *
	 * @return array<int,string>
	 */
	private static function get_server_log_files_desc(): array {
		$dir = self::get_log_dir();
		if ( ! $dir ) {
			return array();
		}

		$pattern = trailingslashit( $dir ) . self::get_server_uuid() . '-*.jsonl';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_glob -- Efficient file discovery for UUID-scoped JSONL logs.
		$files = glob( $pattern );
		if ( ! is_array( $files ) || empty( $files ) ) {
			return array();
		}

		rsort( $files, SORT_STRING );
		return $files;
	}

	/**
	 * Build filename to absolute path map for this server's log files.
	 *
	 * @return array<string,string>
	 */
	private static function get_server_log_file_map(): array {
		$map = array();

		foreach ( self::get_server_log_files_desc() as $path ) {
			$filename = wp_basename( $path );
			if ( '' === $filename ) {
				continue;
			}

			$map[ $filename ] = $path;
		}

		return $map;
	}

	/**
	 * Read one JSONL file and return entries in reverse order.
	 *
	 * @param string $path File path.
	 * @return array<int,array<string,mixed>>
	 */
	private static function read_entries_from_file_desc( string $path ): array {
		if ( '' === $path ) {
			return array();
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read local JSONL log file for admin display.
		$contents = file_get_contents( $path );
		if ( ! is_string( $contents ) || '' === $contents ) {
			return array();
		}

		$lines   = preg_split( '/\r\n|\r|\n/', $contents );
		$entries = array();

		if ( ! is_array( $lines ) ) {
			return array();
		}

		for ( $i = count( $lines ) - 1; $i >= 0; $i-- ) {
			$line = trim( (string) $lines[ $i ] );
			if ( '' === $line ) {
				continue;
			}

			$data = json_decode( $line, true );
			if ( ! is_array( $data ) ) {
				continue;
			}

			$entries[] = array(
				'ts'      => isset( $data['ts'] ) ? absint( $data['ts'] ) : 0,
				'level'   => isset( $data['level'] ) ? sanitize_key( (string) $data['level'] ) : 'info',
				'message' => isset( $data['message'] ) ? (string) $data['message'] : '',
			);
		}

		return $entries;
	}
}
