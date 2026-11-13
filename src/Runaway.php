<?php
/**
 * Runaway guard: spots a caller that suddenly calls AI far more than usual.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Every finished call adds one to the caller's hour bucket (one upsert, no
 * extra read). Every few calls the bucket is compared with the caller's own
 * baseline, the average of its active hours in the last seven days. A caller
 * over a multiple of that baseline, and over an absolute floor, is a runaway:
 * in Pause mode its kill switch goes on, and in Pause and Alert only mode an
 * alert goes out and an event waits on the screen until someone resumes,
 * raises the limit or dismisses it.
 */
final class Runaway implements Service {

	public const OPTION = 'axle_ai_guard_runaway';
	public const MODES  = Settings::RUNAWAY_MODES;

	/**
	 * Most events kept.
	 */
	private const MAX_EVENTS = 20;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Kill switches.
	 *
	 * @var KillSwitch
	 */
	private KillSwitch $kill;

	/**
	 * Usage counters.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Sets up the runaway guard.
	 *
	 * @param Settings   $settings Settings.
	 * @param KillSwitch $kill     Kill switches.
	 * @param Usage      $usage    Usage counters.
	 * @param Alerts     $alerts   Alerts.
	 */
	public function __construct( Settings $settings, KillSwitch $kill, Usage $usage, Alerts $alerts ) {
		$this->settings = $settings;
		$this->kill     = $kill;
		$this->usage    = $usage;
		$this->alerts   = $alerts;
	}

	/**
	 * Counts finished calls, and follows kill switches that are turned off by hand.
	 */
	public function register(): void {
		add_action( 'axle_ai_guard_call_finished', array( $this, 'on_finished' ), 10, 3 );
		add_action( 'axle_ai_guard_kill_switch_off', array( $this, 'on_switch_off' ), 10, 3 );
	}

	/**
	 * The mode: off, alert or pause.
	 *
	 * @return string
	 */
	public function mode(): string {
		$mode = (string) $this->settings->get( 'runaway_mode' );

		/**
		 * Filters what the runaway guard does: off, alert (alert and show a notice) or pause
		 * (also switch the caller off).
		 *
		 * @param string $mode Default from the settings: alert, or pause on a new install.
		 */
		$filtered = apply_filters( 'axle_ai_guard_runaway_mode', $mode );

		return in_array( $filtered, self::MODES, true ) ? (string) $filtered : $mode;
	}

	/**
	 * How many times its baseline a caller may run per hour.
	 *
	 * @param string $slug Caller key.
	 * @return int
	 */
	public function multiple( string $slug = '' ): int {
		$multiple = (int) $this->settings->get( 'runaway_multiple' );

		/**
		 * Filters how many times its own hourly baseline a caller may reach before it counts as a runaway.
		 *
		 * @param int    $multiple Default 10.
		 * @param string $slug     Caller key.
		 */
		return max( 2, (int) apply_filters( 'axle_ai_guard_runaway_multiple', $multiple, $slug ) );
	}

	/**
	 * The fewest calls in an hour that can count as a runaway, so a new or quiet caller does not trip it.
	 *
	 * @param string $slug Caller key.
	 * @return int
	 */
	public function min_calls( string $slug ): int {
		$floor = (int) $this->settings->get( 'runaway_min_calls' );
		$raise = (int) ( $this->state()['limits'][ $slug ]['calls'] ?? 0 );

		/**
		 * Filters the fewest calls per hour that can count as a runaway.
		 *
		 * @param int    $calls Default from the settings (100), or what "Resume and raise the limit" set for the caller.
		 * @param string $slug  Caller key.
		 */
		return max( 5, (int) apply_filters( 'axle_ai_guard_runaway_min_calls', max( $floor, $raise ), $slug ) );
	}

	/**
	 * The least spend per hour that can count as a runaway, in micro-units.
	 *
	 * @param string $slug Caller key.
	 * @return int
	 */
	public function min_cost( string $slug ): int {
		$raise = (int) ( $this->state()['limits'][ $slug ]['cost'] ?? 0 );

		/**
		 * Filters the least spend per hour that can count as a runaway.
		 *
		 * @param int    $micros Micro-units of the site currency. Default 5, or what "Resume and raise the limit" set.
		 * @param string $slug   Caller key.
		 */
		return max( 1, (int) apply_filters( 'axle_ai_guard_runaway_min_cost', max( 5000000, $raise ), $slug ) );
	}

	/**
	 * Callers that are never paused.
	 *
	 * @return string[]
	 */
	public function exempt(): array {
		/**
		 * Filters the callers the runaway guard never pauses or flags. WordPress itself is always on it.
		 *
		 * @param string[] $slugs Caller keys, such as seo-writer or theme:twentytwentysix.
		 */
		$list = apply_filters( 'axle_ai_guard_runaway_exempt', array() );
		$list = Settings::words( Hooks::arr( $list, array() ) );

		return array_values( array_unique( array_merge( array( 'core' ), $list ) ) );
	}

	/**
	 * Every how many calls the baseline is checked.
	 *
	 * @return int
	 */
	public function check_every(): int {
		/**
		 * Filters every how many calls in an hour a caller is checked against its baseline.
		 *
		 * @param int $every Default 10. 1 checks every call.
		 */
		return max( 1, (int) apply_filters( 'axle_ai_guard_runaway_check_every', 10 ) );
	}

	/**
	 * The stored state: events, raised limits and forecast alerts already sent.
	 *
	 * @return array{events: array<int, array<string, mixed>>, limits: array<string, array{calls: int, cost: int}>, forecast: array<string, int>}
	 */
	public function state(): array {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'events'   => isset( $saved['events'] ) && is_array( $saved['events'] ) ? array_values( $saved['events'] ) : array(),
			'limits'   => isset( $saved['limits'] ) && is_array( $saved['limits'] ) ? $saved['limits'] : array(),
			'forecast' => isset( $saved['forecast'] ) && is_array( $saved['forecast'] ) ? $saved['forecast'] : array(),
		);
	}

	/**
	 * Saves the state.
	 *
	 * @param array $state State from state().
	 */
	public function save_state( array $state ): void {
		$state['events'] = array_slice( array_values( (array) $state['events'] ), -self::MAX_EVENTS );
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Events nobody has dealt with yet, newest last.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function open_events(): array {
		return array_values(
			array_filter(
				$this->state()['events'],
				static function ( array $event ): bool {
					return 'open' === ( $event['status'] ?? '' );
				}
			)
		);
	}

	/**
	 * Action axle_ai_guard_call_finished: counts the call and checks the caller now and then.
	 *
	 * @param int   $id   Row id.
	 * @param array $row  Columns written.
	 * @param array $call Call details.
	 */
	public function on_finished( $id, $row, $call ): void {
		unset( $id );
		$call = (array) $call;
		$slug = (string) ( $call['caller']['slug'] ?? '' );
		if ( '' === $slug || 'off' === $this->mode() || in_array( $slug, $this->exempt(), true ) ) {
			return;
		}

		$cost  = (int) ( ( (array) $row )['cost_micros'] ?? 0 );
		$count = $this->usage->hit_hour( 'plugin:' . $slug, $cost );
		$every = $this->check_every();

		if ( 0 === $count % $every || $count === $this->min_calls( $slug ) ) {
			$this->evaluate( $slug );
		}
	}

	/**
	 * Compares a caller's current hour with its baseline.
	 *
	 * @param string   $slug Caller key.
	 * @param int|null $now  Unix time, or now.
	 * @return array<string, mixed>|null The event when the caller is a runaway, else null.
	 */
	public function evaluate( string $slug, ?int $now = null ): ?array {
		$now  = null === $now ? time() : $now;
		$mode = $this->mode();
		if ( 'off' === $mode || in_array( $slug, $this->exempt(), true ) ) {
			return null;
		}

		foreach ( $this->open_events() as $event ) {
			if ( $slug === $event['caller'] ) {
				return null;
			}
		}

		$subject = 'plugin:' . $slug;
		$hours   = $this->usage->hours( $subject, $now, $now );
		$current = $hours[ 'h:' . gmdate( 'YmdH', $now ) ] ?? array(
			'calls' => 0,
			'cost'  => 0,
		);
		$base    = $this->baseline( $slug, $now );

		$multiple    = $this->multiple( $slug );
		$calls_limit = max( $this->min_calls( $slug ), (int) ceil( $multiple * $base['calls'] ) );
		$cost_limit  = max( $this->min_cost( $slug ), (int) ceil( $multiple * $base['cost'] ) );

		$by_calls = $current['calls'] >= $calls_limit;
		$by_cost  = $current['cost'] > 0 && $current['cost'] >= $cost_limit;
		if ( ! $by_calls && ! $by_cost ) {
			return null;
		}

		return $this->trip(
			$slug,
			array(
				'calls'      => $current['calls'],
				'cost'       => $current['cost'],
				'base_calls' => $base['calls'],
				'base_cost'  => $base['cost'],
				'multiple'   => $multiple,
				'by'         => $by_calls ? 'calls' : 'cost',
			),
			$now
		);
	}

	/**
	 * A caller's normal hour: the average of its active hours in the last seven days, not counting this one.
	 *
	 * @param string   $slug Caller key.
	 * @param int|null $now  Unix time, or now.
	 * @return array{calls: int, cost: int, hours: int}
	 */
	public function baseline( string $slug, ?int $now = null ): array {
		$now = null === $now ? time() : $now;
		$key = 'axle_ai_guard_rbase_' . md5( $slug );

		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['calls'], $cached['cost'], $cached['hours'] ) ) {
			return array(
				'calls' => (int) $cached['calls'],
				'cost'  => (int) $cached['cost'],
				'hours' => (int) $cached['hours'],
			);
		}

		$rows  = $this->usage->hours( 'plugin:' . $slug, $now - Usage::HOUR_DAYS * DAY_IN_SECONDS, $now - HOUR_IN_SECONDS );
		$calls = 0;
		$cost  = 0;
		$hours = 0;
		foreach ( $rows as $row ) {
			if ( $row['calls'] > 0 ) {
				$calls += $row['calls'];
				$cost  += $row['cost'];
				++$hours;
			}
		}
		$out = array(
			'calls' => $hours ? (int) round( $calls / $hours ) : 0,
			'cost'  => $hours ? (int) round( $cost / $hours ) : 0,
			'hours' => $hours,
		);
		set_transient( $key, $out, 30 * MINUTE_IN_SECONDS );

		return $out;
	}

	/**
	 * Records a runaway, pauses the caller in Pause mode and alerts.
	 *
	 * @param string $slug    Caller key.
	 * @param array  $numbers calls, cost, base_calls, base_cost, multiple and by.
	 * @param int    $now     Unix time.
	 * @return array<string, mixed>|null The event, or null when the caller is already switched off.
	 */
	private function trip( string $slug, array $numbers, int $now ): ?array {
		$mode   = $this->mode();
		$paused = false;

		if ( 'pause' === $mode ) {
			if ( $this->kill->is_on( 'plugin', $slug ) ) {
				return null;
			}
			$paused = $this->kill->engage(
				'plugin',
				$slug,
				sprintf(
					/* translators: 1: calls this hour, 2: the caller's usual calls per hour. */
					__( 'Runaway: %1$d AI calls this hour, usually about %2$d.', 'axle-ai-guard' ),
					$numbers['calls'],
					$numbers['base_calls']
				),
				0
			);
		}

		$event = array(
			'id'     => substr( md5( $slug . '|' . $now ), 0, 10 ),
			'caller' => $slug,
			'at'     => $now,
			'mode'   => $mode,
			'paused' => $paused,
			'status' => 'open',
		) + $numbers;

		$state             = $this->state();
		$state['events'][] = $event;
		$this->save_state( $state );

		/**
		 * Fires when a caller is flagged as a runaway.
		 *
		 * @param array $event id, caller, at, mode, paused, calls, cost, base_calls, base_cost, multiple and by (calls or cost).
		 */
		do_action( 'axle_ai_guard_runaway_detected', $event );

		$this->alerts->send(
			'runaway',
			'plugin:' . $slug,
			$paused
				? sprintf(
					/* translators: 1: caller key, 2: calls this hour, 3: usual calls per hour. */
					__( '%1$s made %2$d AI calls this hour, usually about %3$d. Its AI calls are paused. Resume them on the AI Guard screen.', 'axle-ai-guard' ),
					$slug,
					$numbers['calls'],
					$numbers['base_calls']
				)
				: sprintf(
					/* translators: 1: caller key, 2: calls this hour, 3: usual calls per hour. */
					__( '%1$s made %2$d AI calls this hour, usually about %3$d. Its calls were not paused.', 'axle-ai-guard' ),
					$slug,
					$numbers['calls'],
					$numbers['base_calls']
				),
			$event
		);

		return $event;
	}

	/**
	 * Resumes a paused caller, and optionally raises its limit.
	 *
	 * @param string $event_id Event id.
	 * @param int    $user_id  Who.
	 * @param bool   $raise    Also raise the caller's floor to twice what ran away.
	 * @return bool Whether an open event was found.
	 */
	public function resume( string $event_id, int $user_id, bool $raise = false ): bool {
		$state = $this->state();
		foreach ( $state['events'] as $index => $event ) {
			if ( ( $event['id'] ?? '' ) !== $event_id || 'open' !== ( $event['status'] ?? '' ) ) {
				continue;
			}

			$slug = (string) $event['caller'];
			if ( $raise ) {
				$state['limits'][ $slug ] = array(
					'calls' => (int) ( $event['calls'] ?? 0 ) * 2,
					'cost'  => (int) ( $event['cost'] ?? 0 ) * 2,
				);
			}
			$state['events'][ $index ]['status']  = $raise ? 'raised' : 'resumed';
			$state['events'][ $index ]['by']      = $user_id;
			$state['events'][ $index ]['done_at'] = time();
			$this->save_state( $state );
			delete_transient( 'axle_ai_guard_rbase_' . md5( $slug ) );

			if ( ! empty( $event['paused'] ) ) {
				$this->kill->release( 'plugin', $slug, $user_id );
			}

			/**
			 * Fires when someone resumes a runaway caller.
			 *
			 * @param array $event   The event as it was when flagged.
			 * @param bool  $raise   Whether the limit was raised.
			 * @param int   $user_id Who.
			 */
			do_action( 'axle_ai_guard_runaway_resumed', $event, $raise, $user_id );

			return true;
		}

		return false;
	}

	/**
	 * Closes an event without pausing or resuming anything.
	 *
	 * @param string $event_id Event id.
	 * @param int    $user_id  Who.
	 * @return bool Whether an open event was found.
	 */
	public function dismiss( string $event_id, int $user_id ): bool {
		$state = $this->state();
		foreach ( $state['events'] as $index => $event ) {
			if ( ( $event['id'] ?? '' ) === $event_id && 'open' === ( $event['status'] ?? '' ) ) {
				$state['events'][ $index ]['status']  = 'dismissed';
				$state['events'][ $index ]['by']      = $user_id;
				$state['events'][ $index ]['done_at'] = time();
				$this->save_state( $state );

				return true;
			}
		}

		return false;
	}

	/**
	 * Action axle_ai_guard_kill_switch_off: a caller switched back on by hand closes its open event.
	 *
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @param int    $user_id Who.
	 */
	public function on_switch_off( $scope, $subject, $user_id = 0 ): void {
		if ( 'plugin' !== $scope ) {
			return;
		}
		$state   = $this->state();
		$changed = false;
		foreach ( $state['events'] as $index => $event ) {
			if ( ( $event['caller'] ?? '' ) === $subject && 'open' === ( $event['status'] ?? '' ) && ! empty( $event['paused'] ) ) {
				$state['events'][ $index ]['status']  = 'resumed';
				$state['events'][ $index ]['by']      = (int) $user_id;
				$state['events'][ $index ]['done_at'] = time();
				$changed                              = true;
			}
		}
		if ( $changed ) {
			$this->save_state( $state );
		}
	}

	/**
	 * Callers' hourly numbers for the status screen, command and ability.
	 *
	 * @param int|null $now Unix time, or now.
	 * @return array<int, array<string, mixed>> One entry per caller seen.
	 */
	public function callers( ?int $now = null ): array {
		$now  = null === $now ? time() : $now;
		$out  = array();
		$open = array();
		foreach ( $this->open_events() as $event ) {
			$open[ (string) $event['caller'] ] = $event;
		}

		foreach ( array_keys( $this->settings->callers() ) as $slug ) {
			$slug    = (string) $slug;
			$hours   = $this->usage->hours( 'plugin:' . $slug, $now, $now );
			$current = $hours[ 'h:' . gmdate( 'YmdH', $now ) ] ?? array(
				'calls' => 0,
				'cost'  => 0,
			);
			$base    = $this->baseline( $slug, $now );
			$out[]   = array(
				'caller'      => $slug,
				'calls_hour'  => $current['calls'],
				'cost_hour'   => $current['cost'],
				'base_calls'  => $base['calls'],
				'base_cost'   => $base['cost'],
				'calls_limit' => max( $this->min_calls( $slug ), (int) ceil( $this->multiple( $slug ) * $base['calls'] ) ),
				'exempt'      => in_array( $slug, $this->exempt(), true ),
				'paused'      => $this->kill->is_on( 'plugin', $slug ),
				'event'       => isset( $open[ $slug ] ) ? (string) $open[ $slug ]['id'] : '',
			);
		}

		return $out;
	}
}
