<?php
/**
 * "Allow for today": a person's extra calls for the rest of the day.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own counters, changed on every AI call.

/**
 * One row in the usage table per person and day: period g:2026-11-20, subject
 * allow:<user id>. The `tokens` column holds how many calls were granted and
 * `calls` how many were used. A call takes one with a single conditional
 * UPDATE ... WHERE calls < tokens, so a burst of parallel calls can never use
 * more than was granted: the database decides, not a read followed by a write.
 * The row belongs to one UTC day, the same day the daily budgets use, so the
 * grant ends on its own at midnight. Only the rules in overridable_rules()
 * can be passed this way. Kill switches, caller approval and data rules never can.
 */
final class Allowance {

	/**
	 * Rules a grant may pass.
	 */
	public const RULES = array( 'budget', 'rate', 'role', 'context', 'provider', 'model' );

	/**
	 * The day period of a time.
	 *
	 * @param int|null $time Unix time, or now.
	 * @return string
	 */
	public static function period( ?int $time = null ): string {
		return 'g:' . gmdate( 'Y-m-d', null === $time ? time() : $time );
	}

	/**
	 * The subject of a person.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function subject( int $user_id ): string {
		return 'allow:' . $user_id;
	}

	/**
	 * The rules a grant may pass.
	 *
	 * @return string[]
	 */
	public static function rules(): array {
		/**
		 * Filters the rules "Allow for today" can pass for a person: the base of a block rule such
		 * as budget, rate, role, context, provider or model. Kill switches, caller approval,
		 * file rules and data rules are never passed, whatever this returns.
		 *
		 * @param string[] $rules Default budget, rate, role, context, provider, model.
		 */
		$rules = Hooks::arr( apply_filters( 'axle_ai_guard_allowance_rules', self::RULES ), self::RULES );

		return array_values( array_intersect( array_map( 'strval', $rules ), self::RULES ) );
	}

	/**
	 * Whether a block rule can be passed by a grant.
	 *
	 * @param string $rule Rule such as budget or data:email.
	 * @return bool
	 */
	public static function passes( string $rule ): bool {
		return in_array( (string) strtok( $rule, ':' ), self::rules(), true );
	}

	/**
	 * The calls one grant gives.
	 *
	 * @param int $asked Calls asked for, 0 for the default.
	 * @return int
	 */
	public static function calls( int $asked = 0 ): int {
		/**
		 * Filters how many calls "Allow for today" gives a person.
		 *
		 * @param int $calls Default 20. Clamped between 1 and 1000.
		 * @param int $asked Calls asked for by the admin, 0 when not given.
		 */
		$calls = (int) apply_filters( 'axle_ai_guard_allowance_calls', $asked > 0 ? $asked : 20, $asked );

		return max( 1, min( 1000, $calls ) );
	}

	/**
	 * Gives a person more calls for the rest of today. A second grant starts a new set of calls
	 * on top of what was already used.
	 *
	 * @param int      $user_id User.
	 * @param int      $calls   Calls to give, 0 for the default.
	 * @param int|null $time    When, or now.
	 * @return int Calls granted.
	 */
	public function grant( int $user_id, int $calls = 0, ?int $time = null ): int {
		global $wpdb;

		$calls = self::calls( $calls );

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (period, subject, calls, tokens, cost_micros, blocked) VALUES (%s, %s, 0, %d, 0, 0) ON DUPLICATE KEY UPDATE tokens = calls + %d',
				Schema::table( 'usage' ),
				self::period( $time ),
				self::subject( $user_id ),
				$calls,
				$calls
			)
		);

		return $calls;
	}

	/**
	 * Takes one call from a person's grant. One statement: it changes a row only while calls
	 * used is below calls granted, so parallel requests cannot take more than was granted.
	 *
	 * @param int      $user_id User.
	 * @param int|null $time    When, or now.
	 * @return bool Whether a call was taken.
	 */
	public function consume( int $user_id, ?int $time = null ): bool {
		global $wpdb;

		$changed = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET calls = calls + 1 WHERE period = %s AND subject = %s AND calls < tokens',
				Schema::table( 'usage' ),
				self::period( $time ),
				self::subject( $user_id )
			)
		);

		return 1 === (int) $changed;
	}

	/**
	 * Ends a person's grant for today. What was used stays counted.
	 *
	 * @param int      $user_id User.
	 * @param int|null $time    When, or now.
	 * @return int Calls that were still left.
	 */
	public function revoke( int $user_id, ?int $time = null ): int {
		global $wpdb;

		$left = $this->left( $user_id, $time );
		if ( $left > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					'UPDATE %i SET tokens = calls WHERE period = %s AND subject = %s',
					Schema::table( 'usage' ),
					self::period( $time ),
					self::subject( $user_id )
				)
			);
		}

		return $left;
	}

	/**
	 * Calls a person still has from today's grant.
	 *
	 * @param int      $user_id User.
	 * @param int|null $time    When, or now.
	 * @return int
	 */
	public function left( int $user_id, ?int $time = null ): int {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT calls, tokens, cost_micros, blocked FROM %i WHERE period = %s AND subject = %s', Schema::table( 'usage' ), self::period( $time ), self::subject( $user_id ) ), ARRAY_A );

		return max( 0, (int) ( $row['tokens'] ?? 0 ) - (int) ( $row['calls'] ?? 0 ) );
	}

	/**
	 * The people with calls left from today's grants.
	 *
	 * @return array<int, array{user_id: int, left: int, used: int}> Keyed by user ID.
	 */
	public function active(): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT subject, calls, tokens FROM %i WHERE period = %s AND subject LIKE %s AND tokens > calls LIMIT 200', Schema::table( 'usage' ), self::period(), $wpdb->esc_like( 'allow:' ) . '%' ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$id = (int) substr( (string) $row['subject'], 6 );
			if ( $id > 0 ) {
				$out[ $id ] = array(
					'user_id' => $id,
					'left'    => (int) $row['tokens'] - (int) $row['calls'],
					'used'    => (int) $row['calls'],
				);
			}
		}

		return $out;
	}

	/**
	 * Removes every grant of a person, for the privacy eraser.
	 *
	 * @param int $user_id User.
	 */
	public function erase( int $user_id ): void {
		global $wpdb;

		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE subject = %s', Schema::table( 'usage' ), self::subject( $user_id ) ) );
	}
}
