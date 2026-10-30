<?php
/**
 * The daily clean-up and the cost recalculation.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Deletes rows past their retention in batches of 1,000, and recalculates
 * costs after prices change.
 */
final class Cleanup implements Service {

	public const BATCH       = 1000;
	public const MAX_BATCHES = 20;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The call log.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * Usage counters.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Prices.
	 *
	 * @var Prices
	 */
	private Prices $prices;

	/**
	 * Sets up the clean-up.
	 *
	 * @param Settings $settings Settings.
	 * @param Calls    $calls    The call log.
	 * @param Usage    $usage    Usage counters.
	 * @param Prices   $prices   Prices.
	 */
	public function __construct( Settings $settings, Calls $calls, Usage $usage, Prices $prices ) {
		$this->settings = $settings;
		$this->calls    = $calls;
		$this->usage    = $usage;
		$this->prices   = $prices;
	}

	/**
	 * Hooks the cron event.
	 */
	public function register(): void {
		add_action( Schema::CRON, array( $this, 'run' ) );
	}

	/**
	 * Days to keep the rows of a table.
	 *
	 * @param string $table calls, usage or prompts.
	 * @return int
	 */
	public function retention( string $table ): int {
		$days = (int) $this->settings->get( 'retention_' . $table );

		/**
		 * Filters how many days rows are kept.
		 *
		 * @param int    $days  Default 90 for calls, 400 for usage, 7 for stored prompts.
		 * @param string $table calls, usage or prompts.
		 */
		return max( 1, (int) apply_filters( 'axle_ai_guard_retention_days', $days, $table ) );
	}

	/**
	 * The cron event.
	 */
	public function run(): void {
		$this->prune();
	}

	/**
	 * Deletes old rows.
	 *
	 * @param int|null $older_than Days, overriding the calls retention (WP-CLI).
	 * @return array{calls: int, usage: int, prompts: int}
	 */
	public function prune( $older_than = null ): array {
		$counts = array(
			'calls'   => 0,
			'usage'   => 0,
			'prompts' => 0,
		);
		if ( ! Schema::ready() ) {
			return $counts;
		}

		$calls_days = is_numeric( $older_than ) ? max( 0, (int) $older_than ) : $this->retention( 'calls' );
		$before     = gmdate( 'Y-m-d H:i:s', time() - $calls_days * DAY_IN_SECONDS );
		$more       = false;

		for ( $i = 0; $i < self::MAX_BATCHES; $i++ ) {
			$deleted          = $this->calls->prune( 'calls', $before, self::BATCH );
			$counts['calls'] += $deleted;
			if ( $deleted < self::BATCH ) {
				break;
			}
			$more = self::MAX_BATCHES - 1 === $i;
		}

		$counts['prompts'] = $this->calls->prune( 'prompts', gmdate( 'Y-m-d H:i:s' ), self::BATCH * self::MAX_BATCHES );
		$counts['usage']   = $this->usage->prune( $this->retention( 'usage' ), self::BATCH * self::MAX_BATCHES );

		if ( $more ) {
			wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, Schema::CRON );
		}

		/**
		 * Fires after old rows were deleted.
		 *
		 * @param array $counts Rows deleted from calls, usage and prompts.
		 */
		do_action( 'axle_ai_guard_logs_pruned', $counts );

		return $counts;
	}

	/**
	 * Recalculates the cost of successful calls with the current prices,
	 * and moves the difference into the usage counters.
	 *
	 * @param int $days How far back.
	 * @return array{rows: int, changed: int, delta: int}
	 */
	public function recalculate( int $days = 30 ): array {
		$since   = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$after   = 0;
		$summary = array(
			'rows'    => 0,
			'changed' => 0,
			'delta'   => 0,
		);

		do {
			$batch = $this->calls->priced_batch( $since, $after, 500 );
			foreach ( $batch as $row ) {
				$after = (int) $row['id'];
				++$summary['rows'];
				$cost  = $this->prices->cost( (string) $row['provider'], (string) $row['model'], (int) $row['prompt_tokens'], (int) $row['completion_tokens'], (int) $row['thought_tokens'] );
				$delta = $cost - (int) $row['cost_micros'];
				if ( 0 === $delta ) {
					continue;
				}

				$this->calls->update(
					$after,
					array(
						'cost_micros' => $cost,
						'currency'    => $this->prices->currency(),
					)
				);
				$time     = (int) strtotime( $row['started_at'] . ' UTC' );
				$subjects = Policy::subjects(
					array(
						'caller'   => array( 'slug' => (string) $row['caller_slug'] ),
						'provider' => (string) $row['provider'],
						'user_id'  => (int) $row['user_id'],
					)
				);
				foreach ( Usage::periods( $time ) as $period ) {
					foreach ( $subjects as $subject ) {
						$this->usage->bump( $period, $subject, 0, 0, $delta );
					}
				}
				++$summary['changed'];
				$summary['delta'] += $delta;
			}
			$full = 500 === count( $batch );
		} while ( $full );

		return $summary;
	}
}
