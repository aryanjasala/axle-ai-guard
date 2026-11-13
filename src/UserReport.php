<?php
/**
 * Usage per user and per role, for a period, with a CSV export.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the call log, not the counters, because a call keeps the currency it
 * was priced in and the user who triggered it. A person's erased calls have no
 * user any more, so they fall under "No user" and the totals stay right.
 */
final class UserReport {

	public const NONE    = '_none';
	public const DELETED = '_deleted';
	public const NOROLE  = '_norole';

	/**
	 * The call log.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * Prices, for the site currency.
	 *
	 * @var Prices
	 */
	private Prices $prices;

	/**
	 * Sets up the report.
	 *
	 * @param Calls  $calls  The call log.
	 * @param Prices $prices Prices.
	 */
	public function __construct( Calls $calls, Prices $prices ) {
		$this->calls  = $calls;
		$this->prices = $prices;
	}

	/**
	 * The periods a report can cover, in UTC like the rest of the plugin.
	 *
	 * @param int|null $now Unix time, or now.
	 * @return array<string, array{label: string, since: string, until: string}> Keyed by period; until is exclusive.
	 */
	public static function periods( ?int $now = null ): array {
		$now      = null === $now ? time() : $now;
		$tomorrow = gmdate( 'Y-m-d 00:00:00', $now + DAY_IN_SECONDS );
		$list     = array(
			'today'      => array(
				'label' => __( 'Today', 'axle-ai-guard' ),
				'since' => gmdate( 'Y-m-d 00:00:00', $now ),
				'until' => $tomorrow,
			),
			'7d'         => array(
				'label' => __( 'Last 7 days', 'axle-ai-guard' ),
				'since' => gmdate( 'Y-m-d 00:00:00', $now - 6 * DAY_IN_SECONDS ),
				'until' => $tomorrow,
			),
			'30d'        => array(
				'label' => __( 'Last 30 days', 'axle-ai-guard' ),
				'since' => gmdate( 'Y-m-d 00:00:00', $now - 29 * DAY_IN_SECONDS ),
				'until' => $tomorrow,
			),
			'month'      => array(
				'label' => __( 'This month', 'axle-ai-guard' ),
				'since' => gmdate( 'Y-m-01 00:00:00', $now ),
				'until' => $tomorrow,
			),
			'last_month' => array(
				'label' => __( 'Last month', 'axle-ai-guard' ),
				'since' => gmdate( 'Y-m-01 00:00:00', (int) strtotime( gmdate( 'Y-m-01', $now ) . ' -1 month UTC' ) ),
				'until' => gmdate( 'Y-m-01 00:00:00', $now ),
			),
		);

		/**
		 * Filters the periods of the usage report. Times are UTC; until is exclusive.
		 *
		 * @param array $list Keyed by a period key, each with label, since and until.
		 */
		$filtered = Hooks::arr( apply_filters( 'axle_ai_guard_user_report_periods', $list ), $list );
		$clean    = array();
		foreach ( $filtered as $key => $period ) {
			$key = sanitize_key( (string) $key );
			if ( '' === $key || ! is_array( $period ) ) {
				continue;
			}
			$since = (string) ( $period['since'] ?? '' );
			$until = (string) ( $period['until'] ?? '' );
			$ok    = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
			if ( ! preg_match( $ok, $since ) || ! preg_match( $ok, $until ) || $until <= $since ) {
				continue;
			}
			$clean[ $key ] = array(
				'label' => sanitize_text_field( (string) ( $period['label'] ?? $key ) ),
				'since' => $since,
				'until' => $until,
			);
		}

		return $clean ? $clean : $list;
	}

	/**
	 * A period key that exists, else the first one that does.
	 *
	 * @param string $period Requested key.
	 * @return string
	 */
	public static function period_key( string $period ): string {
		$periods = self::periods();
		if ( isset( $periods[ $period ] ) ) {
			return $period;
		}
		if ( isset( $periods['month'] ) ) {
			return 'month';
		}

		return (string) array_key_first( $periods );
	}

	/**
	 * Calls, tokens and cost per user and per role.
	 *
	 * @param string $period Period key.
	 * @return array<string, mixed> period, label, since, until, currency, truncated, totals, users, roles. A cost list is keyed by currency, in micro-units.
	 */
	public function build( string $period = 'month' ): array {
		$period  = self::period_key( $period );
		$range   = self::periods()[ $period ];
		$site    = $this->prices->currency();
		$default = 1000;

		/**
		 * Filters how many user and currency groups the usage report reads.
		 *
		 * @param int $limit Default 1000, between 1 and 5000.
		 */
		$limit = max( 1, min( 5000, Hooks::int( apply_filters( 'axle_ai_guard_user_report_limit', $default ), $default ) ) );
		$rows  = $this->calls->user_usage( $range['since'], $range['until'], $limit );

		$by_user = array();
		foreach ( $rows as $row ) {
			$id       = (int) $row['user_id'];
			$currency = '' !== (string) $row['currency'] ? strtoupper( (string) $row['currency'] ) : $site;
			if ( ! isset( $by_user[ $id ] ) ) {
				$by_user[ $id ] = array(
					'calls'     => 0,
					'blocked'   => 0,
					'tokens'    => 0,
					'costs'     => array(),
					'last_call' => '',
				);
			}
			$blocked                              = (int) $row['blocked'];
			$by_user[ $id ]['calls']             += max( 0, (int) $row['calls'] - $blocked );
			$by_user[ $id ]['blocked']           += $blocked;
			$by_user[ $id ]['tokens']            += (int) $row['tokens'];
			$by_user[ $id ]['costs'][ $currency ] = ( $by_user[ $id ]['costs'][ $currency ] ?? 0 ) + (int) $row['cost'];
			if ( (string) $row['last_call'] > $by_user[ $id ]['last_call'] ) {
				$by_user[ $id ]['last_call'] = (string) $row['last_call'];
			}
		}

		$people = $this->people( array_keys( $by_user ) );
		$users  = array();
		$roles  = array();
		$total  = array(
			'calls'   => 0,
			'blocked' => 0,
			'tokens'  => 0,
			'costs'   => array(),
		);

		foreach ( $by_user as $id => $sum ) {
			$person = $people[ $id ] ?? null;
			if ( 0 === $id ) {
				$role  = self::NONE;
				$name  = __( 'No user', 'axle-ai-guard' );
				$login = '';
			} elseif ( null === $person ) {
				$role = self::DELETED;
				/* translators: %d: user id. */
				$name  = sprintf( __( 'Deleted user %d', 'axle-ai-guard' ), $id );
				$login = '';
			} else {
				$role  = $person['role'];
				$name  = $person['name'];
				$login = $person['login'];
			}

			$users[] = array(
				'user_id'   => $id,
				'login'     => $login,
				'name'      => $name,
				'role'      => $role,
				'calls'     => $sum['calls'],
				'blocked'   => $sum['blocked'],
				'tokens'    => $sum['tokens'],
				'costs'     => $sum['costs'],
				'last_call' => $sum['last_call'],
			);

			if ( ! isset( $roles[ $role ] ) ) {
				$roles[ $role ] = array(
					'role'    => $role,
					'label'   => self::role_label( $role ),
					'users'   => 0,
					'calls'   => 0,
					'blocked' => 0,
					'tokens'  => 0,
					'costs'   => array(),
				);
			}
			++$roles[ $role ]['users'];
			foreach ( array( 'calls', 'blocked', 'tokens' ) as $key ) {
				$roles[ $role ][ $key ] += $sum[ $key ];
				$total[ $key ]          += $sum[ $key ];
			}
			foreach ( $sum['costs'] as $currency => $micros ) {
				$roles[ $role ]['costs'][ $currency ] = ( $roles[ $role ]['costs'][ $currency ] ?? 0 ) + $micros;
				$total['costs'][ $currency ]          = ( $total['costs'][ $currency ] ?? 0 ) + $micros;
			}
		}

		$by_calls = static function ( array $a, array $b ): int {
			return array( $b['calls'], $b['blocked'] ) <=> array( $a['calls'], $a['blocked'] );
		};
		usort( $users, $by_calls );
		$roles = array_values( $roles );
		usort( $roles, $by_calls );

		$report = array(
			'period'    => $period,
			'label'     => $range['label'],
			'since'     => $range['since'],
			'until'     => $range['until'],
			'currency'  => $site,
			'truncated' => count( $rows ) >= $limit,
			'totals'    => $total,
			'users'     => $users,
			'roles'     => $roles,
		);

		/**
		 * Filters the usage report before it is shown, exported or returned.
		 *
		 * @param array<string, mixed> $report The report.
		 * @param string               $period Period key.
		 */
		$filtered = apply_filters( 'axle_ai_guard_user_report', $report, $period );

		return Hooks::arr( $filtered, $report );
	}

	/**
	 * Login, name and role of users, read in one query.
	 *
	 * @param int[] $ids User ids; 0 is skipped.
	 * @return array<int, array{login: string, name: string, role: string}>
	 */
	private function people( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}

		$out   = array();
		$users = get_users(
			array(
				'include' => $ids,
				'number'  => count( $ids ),
				'fields'  => 'all',
			)
		);
		foreach ( $users as $user ) {
			$roles = array_values( (array) $user->roles );
			$role  = $roles ? (string) $roles[0] : self::NOROLE;

			/**
			 * Filters the role a user counts under in the usage report. Each user counts under one role, so the role totals add up.
			 *
			 * @param string   $role Default: the first role of the user, or "_norole".
			 * @param \WP_User $user The user.
			 */
			$role = Hooks::str( apply_filters( 'axle_ai_guard_user_report_role', $role, $user ), $role );

			$out[ (int) $user->ID ] = array(
				'login' => (string) $user->user_login,
				'name'  => '' !== (string) $user->display_name ? (string) $user->display_name : (string) $user->user_login,
				'role'  => $role,
			);
		}

		return $out;
	}

	/**
	 * A readable name for a role key.
	 *
	 * @param string $role Role key or one of the pseudo roles.
	 * @return string
	 */
	public static function role_label( string $role ): string {
		if ( self::NONE === $role ) {
			return __( 'No user (visitors, cron, WP-CLI)', 'axle-ai-guard' );
		}
		if ( self::DELETED === $role ) {
			return __( 'Deleted users', 'axle-ai-guard' );
		}
		if ( self::NOROLE === $role ) {
			return __( 'No role on this site', 'axle-ai-guard' );
		}

		$names = function_exists( 'wp_roles' ) ? (array) wp_roles()->get_names() : array();

		return isset( $names[ $role ] ) ? translate_user_role( (string) $names[ $role ] ) : $role;
	}

	/**
	 * A cost list as text, one currency per entry.
	 *
	 * @param array<string, int> $costs Micro-units by currency.
	 * @param string             $fallback Currency to show when the list is empty.
	 * @return string[]
	 */
	public static function money_list( array $costs, string $fallback ): array {
		if ( ! $costs ) {
			return array( Prices::format( 0, $fallback ) );
		}
		ksort( $costs );
		$out = array();
		foreach ( $costs as $currency => $micros ) {
			$out[] = Prices::format( (int) $micros, (string) $currency );
		}

		return $out;
	}

	/**
	 * The report as CSV rows, header first. One row per user (or role) and currency.
	 *
	 * @param array  $report A report from build().
	 * @param string $by     user or role.
	 * @return array<int, array<int, string>>
	 */
	public function csv_rows( array $report, string $by = 'user' ): array {
		$by       = 'role' === $by ? 'role' : 'user';
		$currency = (string) $report['currency'];
		$header   = 'user' === $by
			? array( 'user_id', 'login', 'name', 'role', 'calls', 'blocked', 'tokens', 'currency', 'cost' )
			: array( 'role', 'label', 'users', 'calls', 'blocked', 'tokens', 'currency', 'cost' );
		$rows     = array( $header );

		foreach ( $report[ 'user' === $by ? 'users' : 'roles' ] as $item ) {
			$costs = $item['costs'] ? $item['costs'] : array( $currency => 0 );
			ksort( $costs );
			foreach ( $costs as $code => $micros ) {
				$row = 'user' === $by
					? array( (string) $item['user_id'], (string) $item['login'], (string) $item['name'], (string) $item['role'], (string) $item['calls'], (string) $item['blocked'], (string) $item['tokens'], (string) $code, self::decimal( (int) $micros ) )
					: array( (string) $item['role'], (string) $item['label'], (string) $item['users'], (string) $item['calls'], (string) $item['blocked'], (string) $item['tokens'], (string) $code, self::decimal( (int) $micros ) );

				/**
				 * Filters one row of the usage CSV.
				 *
				 * @param string[] $row  The cells, in the order of the header.
				 * @param array    $item The user or role the row is for.
				 * @param string   $by   user or role.
				 */
				$row    = Hooks::arr( apply_filters( 'axle_ai_guard_user_report_csv_row', $row, $item, $by ), $row );
				$rows[] = array_map( array( self::class, 'safe_cell' ), array_map( 'strval', array_values( $row ) ) );
			}
		}

		return $rows;
	}

	/**
	 * Rows as CSV text, with a byte order mark so spreadsheets read UTF-8.
	 *
	 * @param array<int, array<int, string>> $rows Rows, header first.
	 * @return string
	 */
	public static function to_csv( array $rows ): string {
		$lines = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $cell ) {
				$cell    = (string) $cell;
				$cells[] = preg_match( '/[",\r\n]/', $cell ) ? '"' . str_replace( '"', '""', $cell ) . '"' : $cell;
			}
			$lines[] = implode( ',', $cells );
		}

		return "\xEF\xBB\xBF" . implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Micro-units as a plain decimal, such as 0.0018.
	 *
	 * @param int $micros Micro-units.
	 * @return string
	 */
	public static function decimal( int $micros ): string {
		$text = rtrim( rtrim( sprintf( '%.6F', $micros / 1000000 ), '0' ), '.' );

		return '' === $text ? '0' : $text;
	}

	/**
	 * Stops a spreadsheet from running a cell as a formula.
	 *
	 * @param string $cell Cell text.
	 * @return string
	 */
	public static function safe_cell( string $cell ): string {
		return '' !== $cell && false !== strpos( "=+-@\t\r", $cell[0] ) ? "'" . $cell : $cell;
	}
}
