<?php
/**
 * CronPulse_Debug_Log
 *
 * A small file-based log for diagnosing email delivery — separate from the
 * structured Email Log (which lives in the options table and only records
 * the final outcome). This captures the SMTP conversation itself, so a
 * connection/auth/TLS failure shows real detail instead of a generic error.
 *
 * Lives under wp-content/uploads/ rather than the plugin directory, since
 * the plugins directory can be read-only on some hosts and gets wiped on
 * plugin updates either way.
 */
defined( 'ABSPATH' ) || exit;

class CronPulse_Debug_Log {

	/**
	 * Once the file exceeds this size, it's trimmed to the most recent half —
	 * a debug log, not a permanent record, so unbounded growth isn't useful.
	 */
	private const MAX_BYTES = 524288; // 512 KB

	/**
	 * How many "CLIENT -> SERVER" lines after an AUTH command to redact —
	 * AUTH LOGIN sends base64 username then password as two separate lines.
	 */
	private static int $redact_next = 0;

	public static function get_dir(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'cronpulse-logs';
	}

	public static function get_path(): string {
		return self::get_dir() . '/email-debug.log';
	}

	/**
	 * @return bool False if the directory doesn't exist and couldn't be
	 *              created — write() bails early in that case rather than
	 *              silently doing nothing.
	 */
	private static function ensure_protected_dir(): bool {
		$dir = self::get_dir();

		// Cleared up front, not just after writes — a persistent PHP-FPM
		// worker that checked this exact path before the directory/file
		// ever existed can keep returning a stale "doesn't exist" from its
		// own process-local stat cache otherwise, regardless of what any
		// OTHER worker has since written to disk.
		clearstatcache( true, $dir );

		if ( ! file_exists( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		// Checked up front so a read-only directory bails out cleanly here
		// instead of every file_put_contents() below throwing a PHP warning —
		// which, if display_errors is on, can leak into and break a JSON
		// AJAX response the exact same way the original bug report did.
		if ( ! is_writable( $dir ) ) {
			return false;
		}

		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// Belt-and-suspenders: Apache honors this; nginx doesn't, which is
			// why the log never includes raw credentials regardless (see
			// log_smtp_line()) rather than relying on this alone.
			file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return true;
	}

	/**
	 * @return bool Whether the line was actually written — a permissions
	 *              issue on the uploads directory would otherwise fail
	 *              silently and every call site would assume it worked.
	 */
	public static function write( string $line ): bool {
		if ( ! self::ensure_protected_dir() ) {
			return false;
		}

		$path  = self::get_path();
		$entry = '[' . current_time( 'mysql' ) . '] ' . $line . "\n";

		if ( false === file_put_contents( $path, $entry, FILE_APPEND | LOCK_EX ) ) {
			return false;
		}

		clearstatcache( true, $path );
		if ( file_exists( $path ) && filesize( $path ) > self::MAX_BYTES ) {
			self::trim_to_recent();
		}

		return true;
	}

	/**
	 * Whether the log directory exists (or can be created) and is writable —
	 * lets callers warn the user instead of just showing a perpetually empty
	 * log with no explanation.
	 */
	public static function is_writable(): bool {
		return self::ensure_protected_dir();
	}

	private static function trim_to_recent(): void {
		$path     = self::get_path();
		$contents = file_get_contents( $path );

		if ( false === $contents ) {
			return;
		}

		$contents = substr( $contents, - (int) ( self::MAX_BYTES / 2 ) );

		// Avoid starting mid-line.
		$newline_pos = strpos( $contents, "\n" );
		if ( false !== $newline_pos ) {
			$contents = substr( $contents, $newline_pos + 1 );
		}

		file_put_contents( $path, $contents, LOCK_EX );
	}

	/**
	 * Logs a raw PHPMailer SMTPDebug line, redacting the base64
	 * username/password exchange that follows an AUTH LOGIN/PLAIN command
	 * so credentials never end up in the log file, regardless of whether
	 * the .htaccess above is actually honored by the host's web server.
	 */
	public static function log_smtp_line( string $str ): void {
		$lines = preg_split( '/\r\n|\r|\n/', trim( $str ) );
		$out   = [];

		foreach ( $lines as $line ) {
			if ( '' === $line ) {
				continue;
			}

			if ( self::$redact_next > 0 && stripos( $line, 'CLIENT -> SERVER' ) !== false ) {
				$out[]            = preg_replace( '/CLIENT -> SERVER:\s*.*/', 'CLIENT -> SERVER: [REDACTED]', $line );
				self::$redact_next--;
				continue;
			}

			if ( stripos( $line, 'AUTH LOGIN' ) !== false || stripos( $line, 'AUTH PLAIN' ) !== false ) {
				self::$redact_next = 2;
			}

			$out[] = $line;
		}

		if ( ! empty( $out ) ) {
			self::write( implode( "\n", $out ) );
		}
	}

	/**
	 * Return the most recent $max_lines lines for display in the admin UI.
	 */
	public static function get_contents( int $max_lines = 300 ): string {
		$path = self::get_path();

		clearstatcache( true, $path );

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return '';
		}

		$contents = file_get_contents( $path );

		if ( false === $contents || '' === trim( $contents ) ) {
			return '';
		}

		$lines = explode( "\n", rtrim( $contents, "\n" ) );

		return implode( "\n", array_slice( $lines, -$max_lines ) );
	}

	/**
	 * Distinguishes "no log yet" (null — nothing to diagnose) from "the file
	 * exists but this process can't read it" (false — usually a permissions
	 * mismatch, e.g. the file was created by a cron job running as a
	 * different system user than the one serving this web request).
	 */
	public static function file_is_readable(): ?bool {
		$path = self::get_path();

		clearstatcache( true, $path );

		if ( ! file_exists( $path ) ) {
			return null;
		}

		return is_readable( $path );
	}

	/**
	 * Raw ground truth for troubleshooting — what this exact PHP process
	 * sees for the log file right now, after forcing a fresh stat lookup.
	 * Shown directly in the admin UI so a mismatch against what's visible
	 * via FTP/file manager is immediately obvious rather than guessed at.
	 *
	 * @return array{path: string, exists: bool, readable: ?bool, size: ?int, modified: ?string}
	 */
	public static function get_diagnostics(): array {
		$path = self::get_path();

		clearstatcache( true, $path );

		$exists = file_exists( $path );

		return [
			'path'     => $path,
			'exists'   => $exists,
			'readable' => $exists ? is_readable( $path ) : null,
			'size'     => $exists ? filesize( $path ) : null,
			'modified' => $exists ? gmdate( 'Y-m-d H:i:s', filemtime( $path ) ) . ' UTC' : null,
		];
	}

	/**
	 * @return bool True once the file is confirmed empty, false if the write
	 *              failed (e.g. a permissions issue) — callers should not
	 *              report success without checking this.
	 */
	public static function clear(): bool {
		$path = self::get_path();

		clearstatcache( true, $path );

		if ( ! file_exists( $path ) ) {
			return true;
		}

		file_put_contents( $path, '' );

		clearstatcache( true, $path );
		return 0 === filesize( $path );
	}
}
