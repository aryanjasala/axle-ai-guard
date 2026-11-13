<?php
/**
 * Usage counters per day and month, and the minute buckets of the rate limits.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The plugin's own counters, changed on every AI call.

/**
 * One row per period and subject. Periods are d:2026-10-30, m:2026-10 and,
 * for rate limits without a persistent object cache, n:202610301405 (a
 * minute) and h:2026103014 (an hour, for the runaway guard). Subjects are global, plugin:<caller>, provider:<id>, user:<id>
 * and ip:<hash>. Counters only grow through INSERT ... ON DUPLICATE KEY
 * UPDATE x = x + n, so parallel requests never lose a count.
 */
final class Usage {

	/**
	 * Days the hour buckets are kept: the runaway baseline looks back seven.
	 */
	public const HOUR_DAYS = 7;

	/**
	 * The day and month periods of a time.
	 *
	 * @param int|null $time Unix time, or now.
	 * @return array{day: string, month: string}
	 */
	public static function periods( ?int $time = null ): array {
		$time = null === $time ? time() : $time;

		return array(
			'day'   => 'd:' . gmdate( 'Y-m-d', $time ),
			'month' => 'm:' . gmdate( 'Y-m', $time ),
		);
	}

	/**
	 * Adds to the counters of each subject, for the day and the month.
	 *
	 * @param string[] $subjects Subjects.
	 * @param int      $calls    Calls.
	 * @param int      $tokens   Tokens.
	 * @param int      $cost     Cost in micro-units.
	 * @param int      $blocked  Blocked calls.
	 * @param int|null $time     When, or now.
	 */
	public function add( array $subjects, int $calls, int $tokens, int $cost, int $blocked = 0, ?int $time = null ): void {
		foreach ( self::periods( $time ) as $period ) {
			foreach ( array_unique( $subjects ) as $subject ) {
				$this->bump( $period, (string) $subject, $calls, $tokens, $cost, $blocked );
			}
		}
	}

	/**
	 * Adds to one counter row.
	 *
	 * @param string $period  Period.
	 * @param string $subject Subject.
	 * @param int    $calls   Calls.
	 * @param int    $tokens  Tokens.
	 * @param int    $cost    Cost in micro-units; may be negative when prices are recalculated.
	 * @param int    $blocked Blocked calls.
	 */
	public function bump( string $period, string $subject, int $calls, int $tokens, int $cost, int $blocked = 0 ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (period, subject, calls, tokens, cost_micros, blocked) VALUES (%s, %s, %d, %d, %d, %d) ON DUPLICATE KEY UPDATE calls = calls + %d, tokens = tokens + %d, cost_micros = GREATEST(0, CAST(cost_micros AS SIGNED) + %d), blocked = blocked + %d',
				Schema::table( 'usage' ),
				substr( $period, 0, 20 ),
				substr( $subject, 0, 120 ),
				$calls,
				$tokens,
				max( 0, $cost ),
				$blocked,
				$calls,
				$tokens,
				$cost,
				$blocked
			)
		);
	}

	/**
	 * The counters of one subject in one period.
	 *
	 * @param string $period  Period.
	 * @param string $subject Subject.
	 * @return array{calls: int, tokens: int, cost: int, blocked: int}
	 */
	public function get( string $period, string $subject ): array {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT calls, tokens, cost_micros, blocked FROM %i WHERE period = %s AND subject = %s', Schema::table( 'usage' ), $period, $subject ), ARRAY_A );

		return array(
			'calls'   => (int) ( $row['calls'] ?? 0 ),
			'tokens'  => (int) ( $row['tokens'] ?? 0 ),
			'cost'    => (int) ( $row['cost_micros'] ?? 0 ),
			'blocked' => (int) ( $row['blocked'] ?? 0 ),
		);
	}

	/**
	 * Every subject of a kind in one period.
	 *
	 * @param string $period Period.
	 * @param string $kind   plugin, provider, user or global.
	 * @return array<string, array{calls: int, tokens: int, cost: int, blocked: int}> Keyed by the subject without its kind.
	 */
	public function by_kind( string $period, string $kind ): array {
		global $wpdb;

		$like = 'global' === $kind ? 'global' : $wpdb->esc_like( $kind . ':' ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT subject, calls, tokens, cost_micros, blocked FROM %i WHERE period = %s AND subject LIKE %s ORDER BY calls DESC LIMIT 500', Schema::table( 'usage' ), $period, $like ), ARRAY_A );
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$name         = 'global' === $kind ? 'global' : substr( (string) $row['subject'], strlen( $kind ) + 1 );
			$out[ $name ] = array(
				'calls'   => (int) $row['calls'],
				'tokens'  => (int) $row['tokens'],
				'cost'    => (int) $row['cost_micros'],
				'blocked' => (int) $row['blocked'],
			);
		}

		return $out;
	}

	/**
	 * Counts a call in this minute's bucket and returns the new count.
	 *
	 * @param string $subject Subject.
	 * @return int
	 */
	public function hit_minute( string $subject ): int {
		$minute = gmdate( 'YmdHi' );

		if ( wp_using_ext_object_cache() ) {
			$key = 'rate:' . md5( $subject ) . ':' . $minute;
			wp_cache_add( $key, 0, 'axle_ai_guard', 2 * MINUTE_IN_SECONDS );
			$count = wp_cache_incr( $key, 1, 'axle_ai_guard' );
			if ( false !== $count ) {
				return (int) $count;
			}
		}

		$this->bump( 'n:' . $minute, $subject, 1, 0, 0 );

		return $this->get( 'n:' . $minute, $subject )['calls'];
	}

	/**
	 * Counts a finished call in this hour's bucket of a subject and returns the
	 * calls so far this hour. One upsert; LAST_INSERT_ID( expr ) hands the new
	 * count back with no second query. A new row (or a database that does not
	 * return it) reads the row once.
	 *
	 * @param string   $subject Subject, such as plugin:caller-a.
	 * @param int      $cost    Cost in micro-units.
	 * @param int|null $time    When, or now.
	 * @return int Calls this hour, including this one.
	 */
	public function hit_hour( string $subject, int $cost, ?int $time = null ): int {
		global $wpdb;

		$period = 'h:' . gmdate( 'YmdH', null === $time ? time() : $time );

		$wpdb->insert_id = 0;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (period, subject, calls, tokens, cost_micros) VALUES (%s, %s, 1, 0, %d) ON DUPLICATE KEY UPDATE calls = LAST_INSERT_ID(calls + 1), cost_micros = cost_micros + %d',
				Schema::table( 'usage' ),
				$period,
				substr( $subject, 0, 120 ),
				max( 0, $cost ),
				max( 0, $cost )
			)
		);

		$count = (int) $wpdb->insert_id;

		// @phpstan-ignore-next-line -- insert_id is set by the query above.
		return $count > 0 ? $count : max( 1, $this->get( $period, $subject )['calls'] );
	}

	/**
	 * The hour buckets of a subject between two times, oldest first.
	 *
	 * @param string $subject Subject.
	 * @param int    $from    Unix time of the first hour.
	 * @param int    $to      Unix time of the last hour.
	 * @return array<string, array{calls: int, cost: int}> Keyed by period.
	 */
	public function hours( string $subject, int $from, int $to ): array {
		return $this->range( 'h:', gmdate( 'YmdH', $from ), gmdate( 'YmdH', $to ), $subject );
	}

	/**
	 * The day counters of a subject between two times, oldest first.
	 *
	 * @param string $subject Subject.
	 * @param int    $from    Unix time of the first day.
	 * @param int    $to      Unix time of the last day.
	 * @return array<string, array{calls: int, cost: int, tokens: int}> Keyed by period.
	 */
	public function days( string $subject, int $from, int $to ): array {
		return $this->range( 'd:', gmdate( 'Y-m-d', $from ), gmdate( 'Y-m-d', $to ), $subject );
	}

	/**
	 * Rows of one subject for periods of one kind between two stamps.
	 *
	 * @param string $kind    h: or d:.
	 * @param string $first   First stamp.
	 * @param string $last    Last stamp.
	 * @param string $subject Subject.
	 * @return array<string, array{calls: int, cost: int, tokens: int}>
	 */
	private function range( string $kind, string $first, string $last, string $subject ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT period, calls, tokens, cost_micros FROM %i WHERE subject = %s AND period >= %s AND period <= %s ORDER BY period ASC LIMIT 400', Schema::table( 'usage' ), $subject, $kind . $first, $kind . $last ), ARRAY_A );
		$out  = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$out[ (string) $row['period'] ] = array(
				'calls'  => (int) $row['calls'],
				'tokens' => (int) $row['tokens'],
				'cost'   => (int) $row['cost_micros'],
			);
		}

		return $out;
	}

	/**
	 * Deletes counter rows older than the retention, and minute buckets older than an hour.
	 *
	 * @param int $days  Days to keep day and month rows.
	 * @param int $limit Batch size.
	 * @return int Rows deleted.
	 */
	public function prune( int $days, int $limit ): int {
		global $wpdb;

		$table   = Schema::table( 'usage' );
		$cutoff  = time() - $days * DAY_IN_SECONDS;
		$deleted = 0;

		$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE period LIKE %s AND period < %s LIMIT %d', $table, 'n:%', 'n:' . gmdate( 'YmdHi', time() - HOUR_IN_SECONDS ), $limit ) );
		$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE period LIKE %s AND period < %s LIMIT %d', $table, 'h:%', 'h:' . gmdate( 'YmdH', time() - ( self::HOUR_DAYS + 1 ) * DAY_IN_SECONDS ), $limit ) );
		$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE period LIKE %s AND period < %s LIMIT %d', $table, 'd:%', 'd:' . gmdate( 'Y-m-d', $cutoff ), $limit ) );
		$deleted += (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE period LIKE %s AND period < %s LIMIT %d', $table, 'm:%', 'm:' . gmdate( 'Y-m', $cutoff ), $limit ) );

		return $deleted;
	}
}
