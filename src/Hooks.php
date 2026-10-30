<?php
/**
 * Type guards for values that come back from filters.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * A filter callback can return anything, whatever the hook's docblock says.
 * These fall back to the unfiltered value when the type is wrong.
 */
final class Hooks {

	/**
	 * The value when it is an array, else the fallback.
	 *
	 * @param mixed $value    Filtered value.
	 * @param array $fallback Unfiltered value.
	 * @return array
	 */
	public static function arr( $value, array $fallback ): array {
		return is_array( $value ) ? $value : $fallback;
	}

	/**
	 * The value when it is a non-empty string, else the fallback.
	 *
	 * @param mixed  $value    Filtered value.
	 * @param string $fallback Unfiltered value.
	 * @return string
	 */
	public static function str( $value, string $fallback ): string {
		return is_string( $value ) && '' !== $value ? $value : $fallback;
	}

	/**
	 * The value as an integer when it is numeric, else the fallback.
	 *
	 * @param mixed $value    Filtered value.
	 * @param int   $fallback Unfiltered value.
	 * @return int
	 */
	public static function int( $value, int $fallback ): int {
		return is_numeric( $value ) ? (int) $value : $fallback;
	}
}
