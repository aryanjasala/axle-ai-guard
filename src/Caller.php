<?php
/**
 * Works out which plugin, theme or mu-plugin made an AI call.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Core passes no caller with an AI call, so this walks the backtrace and takes
 * the first frame that is not WordPress itself or this plugin. It is a best
 * guess: a shared helper library or a callback set up by another plugin shows
 * up as the plugin the file lives in. The file and line it used are kept, so
 * an admin can see why.
 *
 * A caller key is the plugin folder (caller-a), mu:<name>, theme:<slug>, core
 * or unknown.
 */
final class Caller {

	/**
	 * The caller of the current call.
	 *
	 * @return array{type: string, slug: string, file: string, line: int}
	 */
	public static function detect(): array {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Attribution needs the call stack; nothing is printed.
		$frames = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 60 );

		return self::from_frames( $frames );
	}

	/**
	 * The caller from a backtrace.
	 *
	 * @param array $frames Backtrace frames, innermost first.
	 * @return array{type: string, slug: string, file: string, line: int}
	 */
	public static function from_frames( array $frames ): array {
		$guess = array(
			'type' => 'core',
			'slug' => 'core',
			'file' => '',
			'line' => 0,
		);

		foreach ( $frames as $frame ) {
			$file = isset( $frame['file'] ) ? self::normalize( (string) $frame['file'] ) : '';
			if ( '' === $file || self::is_ignored( $file ) ) {
				continue;
			}

			$guess         = self::classify( $file );
			$guess['line'] = (int) ( $frame['line'] ?? 0 );
			break;
		}

		/**
		 * Filters who made an AI call.
		 *
		 * @param array $guess  type (plugin, mu-plugin, theme, core, unknown), slug (the caller key), file and line.
		 * @param array $frames The backtrace it came from.
		 */
		$filtered = apply_filters( 'axle_ai_guard_caller', $guess, $frames );

		return self::clean( Hooks::arr( $filtered, $guess ), $guess );
	}

	/**
	 * Caller type, key and relative file for a file path.
	 *
	 * @param string $file Absolute, normalised path.
	 * @return array{type: string, slug: string, file: string, line: int}
	 */
	public static function classify( string $file ): array {
		$roots = array(
			'plugin'    => self::normalize( WP_PLUGIN_DIR ) . '/',
			'mu-plugin' => self::normalize( WPMU_PLUGIN_DIR ) . '/',
			'theme'     => self::normalize( WP_CONTENT_DIR ) . '/themes/',
		);

		foreach ( $roots as $type => $root ) {
			if ( 0 !== strpos( $file, $root ) ) {
				continue;
			}

			$rest  = substr( $file, strlen( $root ) );
			$first = (string) strtok( $rest, '/' );
			$name  = strtolower( (string) preg_replace( '/\.php$/', '', $first ) );
			$slug  = 'plugin' === $type ? $name : ( 'theme' === $type ? 'theme:' . $name : 'mu:' . $name );

			return array(
				'type' => $type,
				'slug' => $slug,
				'file' => self::relative( $file ),
				'line' => 0,
			);
		}

		return array(
			'type' => 'unknown',
			'slug' => 'unknown',
			'file' => self::relative( $file ),
			'line' => 0,
		);
	}

	/**
	 * Whether a frame belongs to WordPress itself or to this plugin.
	 *
	 * @param string $file Absolute, normalised path.
	 * @return bool
	 */
	public static function is_ignored( string $file ): bool {
		$abspath = self::normalize( ABSPATH ) . '/';
		$own     = self::normalize( dirname( PLUGIN_FILE ) ) . '/';

		if ( 0 === strpos( $file, $own ) || 0 === strpos( $file, $abspath . 'wp-includes/' ) || 0 === strpos( $file, $abspath . 'wp-admin/' ) ) {
			return true;
		}

		// wp-settings.php, wp-load.php, index.php, wp-cron.php and the other files at the root.
		return dirname( $file ) . '/' === $abspath;
	}

	/**
	 * A path relative to wp-content, or to the WordPress root.
	 *
	 * @param string $file Absolute, normalised path.
	 * @return string
	 */
	public static function relative( string $file ): string {
		foreach ( array( self::normalize( WP_CONTENT_DIR ) . '/', self::normalize( ABSPATH ) . '/' ) as $root ) {
			if ( 0 === strpos( $file, $root ) ) {
				return substr( $file, strlen( $root ) );
			}
		}

		return basename( $file );
	}

	/**
	 * Forward slashes, no trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function normalize( string $path ): string {
		return rtrim( str_replace( '\\', '/', $path ), '/' );
	}

	/**
	 * Keeps a filtered caller well formed.
	 *
	 * @param array $caller   Filtered caller.
	 * @param array $fallback The unfiltered guess.
	 * @return array{type: string, slug: string, file: string, line: int}
	 */
	private static function clean( array $caller, array $fallback ): array {
		$slug = strtolower( (string) preg_replace( '/[^A-Za-z0-9_.:\-]/', '', (string) ( $caller['slug'] ?? '' ) ) );
		$type = (string) ( $caller['type'] ?? '' );

		return array(
			'type' => in_array( $type, array( 'plugin', 'mu-plugin', 'theme', 'core', 'unknown' ), true ) ? $type : (string) $fallback['type'],
			'slug' => '' !== $slug ? substr( $slug, 0, 100 ) : (string) $fallback['slug'],
			'file' => substr( (string) ( $caller['file'] ?? '' ), 0, 255 ),
			'line' => max( 0, (int) ( $caller['line'] ?? 0 ) ),
		);
	}
}
