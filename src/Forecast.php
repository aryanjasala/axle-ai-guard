<?php
/**
 * Month-end forecast: when a monthly budget runs out at the pace of the last seven days.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the day counters of the last seven days, so it costs one small query
 * per budget and nothing on an AI call. A daily job alerts once per month and
 * subject when the forecast crosses a budget before the month ends.
 */
final class Forecast implements Service {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Policy, for budgets and usage.
	 *
	 * @var Policy
	 */
	private Policy $policy;

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
	 * Runaway guard, which keeps the "already alerted" marks.
	 *
	 * @var Runaway
	 */
	private Runaway $runaway;

	/**
	 * Sets up the forecast.
	 *
	 * @param Settings $settings Settings.
	 * @param Policy   $policy   Policy.
	 * @param Usage    $usage    Usage counters.
	 * @param Alerts   $alerts   Alerts.
	 * @param Runaway  $runaway  Runaway guard.
	 */
	public function __construct( Settings $settings, Policy $policy, Usage $usage, Alerts $alerts, Runaway $runaway ) {
		$this->settings = $settings;
		$this->policy   = $policy;
		$this->usage    = $usage;
		$this->alerts   = $alerts;
		$this->runaway  = $runaway;
	}

	/**
	 * Runs the alert check with the daily clean-up.
	 */
	public function register(): void {
		add_action( Schema::CRON, array( $this, 'run' ), 20 );
	}

	/**
	 * The daily job.
	 */
	public function run(): void {
		$this->check();
	}

	/**
	 * Days of history the pace is taken from.
	 *
	 * @return int
	 */
	public static function window_days(): int {
		/**
		 * Filters how many days of usage the forecast takes its pace from.
		 *
		 * @param int $days Default 7, from 2 to 31.
		 */
		return max( 2, min( 31, (int) apply_filters( 'axle_ai_guard_forecast_days', 7 ) ) );
	}

	/**
	 * The forecast of one subject's monthly budget.
	 *
	 * @param string   $subject global or plugin:<caller>.
	 * @param int|null $now     Unix time, or now.
	 * @return array{subject: string, unit: string, used: int, budget: int, per_day: float, state: string, runs_out: int, crosses: bool, projected: int}|null Null when the subject has no monthly budget.
	 */
	public function for_subject( string $subject, ?int $now = null ): ?array {
		$now    = null === $now ? time() : $now;
		$budget = $this->policy->budget( $subject );
		$unit   = $budget['monthly_cost'] > 0 ? 'cost' : ( $budget['monthly_tokens'] > 0 ? 'tokens' : '' );
		if ( '' === $unit ) {
			return null;
		}

		$limit = 'cost' === $unit ? $budget['monthly_cost'] : $budget['monthly_tokens'];
		$used  = $this->policy->used( $subject )[ 'cost' === $unit ? 'monthly_cost' : 'monthly_tokens' ];
		$days  = self::window_days();
		$total = 0;
		foreach ( $this->usage->days( $subject, $now - ( $days - 1 ) * DAY_IN_SECONDS, $now ) as $row ) {
			$total += $row[ $unit ];
		}
		$per_day = $total / $days;

		$month_end = (int) strtotime( gmdate( 'Y-m-01 00:00:00', $now ) . ' +1 month UTC' );
		$left_days = max( 0.0, ( $month_end - $now ) / DAY_IN_SECONDS );
		$out       = array(
			'subject'   => $subject,
			'unit'      => $unit,
			'used'      => $used,
			'budget'    => $limit,
			'per_day'   => $per_day,
			'state'     => 'ok',
			'runs_out'  => 0,
			'crosses'   => false,
			'projected' => (int) round( $used + $per_day * $left_days ),
		);

		if ( $used >= $limit ) {
			$out['state'] = 'reached';
		} elseif ( $per_day <= 0 ) {
			$out['state'] = 'quiet';
		} else {
			$out['runs_out'] = (int) ( $now + ( $limit - $used ) / $per_day * DAY_IN_SECONDS );
			$out['crosses']  = $out['runs_out'] < $month_end;
			$out['state']    = $out['crosses'] ? 'crosses' : 'ok';
		}

		/**
		 * Filters the forecast of a subject's monthly budget.
		 *
		 * @param array  $forecast subject, unit (cost or tokens), used, budget, per_day, state (ok, crosses, reached or quiet), runs_out (Unix time or 0), crosses and projected.
		 * @param string $subject  global or plugin:<caller>.
		 */
		return Hooks::arr( apply_filters( 'axle_ai_guard_forecast', $out, $subject ), $out ) + $out;
	}

	/**
	 * Forecasts of the site and of every plugin with a monthly budget.
	 *
	 * @param int|null $now Unix time, or now.
	 * @return array<int, array<string, mixed>>
	 */
	public function all( ?int $now = null ): array {
		$subjects = array( 'global' );
		foreach ( array_keys( $this->settings->policies() ) as $subject ) {
			if ( 0 === strpos( $subject, 'plugin:' ) ) {
				$subjects[] = $subject;
			}
		}

		$list = array();
		foreach ( array_unique( $subjects ) as $subject ) {
			$forecast = $this->for_subject( $subject, $now );
			if ( $forecast ) {
				$list[] = $forecast;
			}
		}

		return $list;
	}

	/**
	 * Alerts once per month and subject when a forecast crosses its budget.
	 *
	 * @param int|null $now Unix time, or now.
	 * @return int Alerts raised.
	 */
	public function check( ?int $now = null ): int {
		$now    = null === $now ? time() : $now;
		$month  = gmdate( 'Y-m', $now );
		$state  = $this->runaway->state();
		$sent   = array();
		$raised = 0;

		// Marks of earlier months are dropped.
		foreach ( $state['forecast'] as $key => $time ) {
			if ( 0 === strpos( (string) $key, $month . '|' ) ) {
				$sent[ $key ] = (int) $time;
			}
		}

		foreach ( $this->all( $now ) as $forecast ) {
			$key = $month . '|' . $forecast['subject'];
			if ( ! $forecast['crosses'] || isset( $sent[ $key ] ) ) {
				continue;
			}
			$sent[ $key ] = $now;
			++$raised;

			/**
			 * Fires when a forecast shows a monthly budget running out before the month ends. Once per month and subject.
			 *
			 * @param string $subject  global or plugin:<caller>.
			 * @param array  $forecast The forecast.
			 */
			do_action( 'axle_ai_guard_forecast_crossed', $forecast['subject'], $forecast );

			$this->alerts->send(
				'forecast',
				$forecast['subject'] . ':' . $month,
				sprintf(
					/* translators: 1: subject such as plugin:caller-a, 2: day of the month. */
					__( 'At this pace the monthly budget of %1$s runs out on the %2$s.', 'axle-ai-guard' ),
					$forecast['subject'],
					wp_date( 'jS', $forecast['runs_out'] )
				),
				$forecast
			);
		}

		if ( $sent !== $state['forecast'] ) {
			$state['forecast'] = $sent;
			$this->runaway->save_state( $state );
		}

		return $raised;
	}
}
