<?php
/**
 * Usage, limits and calls as plain data, for the screens, REST, abilities and WP-CLI.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * One place that turns stored data into answers.
 */
final class Reports {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Kill switches.
	 *
	 * @var KillSwitch
	 */
	private KillSwitch $kill;

	/**
	 * Data rules.
	 *
	 * @var Detectors
	 */
	private Detectors $detectors;

	/**
	 * Prices.
	 *
	 * @var Prices
	 */
	private Prices $prices;

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
	 * Sets up reports.
	 *
	 * @param Settings   $settings  Settings.
	 * @param Policy     $policy    Policy.
	 * @param KillSwitch $kill      Kill switches.
	 * @param Detectors  $detectors Data rules.
	 * @param Prices     $prices    Prices.
	 * @param Calls      $calls     The call log.
	 * @param Usage      $usage     Usage counters.
	 */
	public function __construct( Settings $settings, Policy $policy, KillSwitch $kill, Detectors $detectors, Prices $prices, Calls $calls, Usage $usage ) {
		$this->settings  = $settings;
		$this->policy    = $policy;
		$this->kill      = $kill;
		$this->detectors = $detectors;
		$this->prices    = $prices;
		$this->calls     = $calls;
		$this->usage     = $usage;
	}

	/**
	 * Totals for today or this month.
	 *
	 * @param string $period day or month.
	 * @return array{calls: int, tokens: int, cost: int, blocked: int}
	 */
	public function totals( string $period ): array {
		$periods = Usage::periods();

		return $this->usage->get( 'month' === $period ? $periods['month'] : $periods['day'], 'global' );
	}

	/**
	 * Usage grouped by plugin, provider, model or user.
	 *
	 * @param string $period day or month.
	 * @param string $by     plugin, provider, model or user.
	 * @return array<int, array{name: string, calls: int, tokens: int, cost: int, blocked: int}>
	 */
	public function usage( string $period, string $by ): array {
		$periods = Usage::periods();
		$key     = 'month' === $period ? $periods['month'] : $periods['day'];
		$rows    = array();

		if ( 'model' === $by ) {
			$since = 'month' === $period ? gmdate( 'Y-m-01 00:00:00' ) : gmdate( 'Y-m-d 00:00:00' );
			foreach ( $this->calls->breakdown( 'model', $since ) as $row ) {
				$rows[] = array(
					'name'    => '' !== (string) $row['name'] ? (string) $row['name'] : '-',
					'calls'   => (int) $row['calls'] - (int) $row['blocked'],
					'tokens'  => (int) $row['tokens'],
					'cost'    => (int) $row['cost'],
					'blocked' => (int) $row['blocked'],
				);
			}

			return $rows;
		}

		$kind = in_array( $by, array( 'plugin', 'provider', 'user' ), true ) ? $by : 'plugin';
		foreach ( $this->usage->by_kind( $key, $kind ) as $name => $row ) {
			$rows[] = array( 'name' => (string) $name ) + $row;
		}

		return $rows;
	}

	/**
	 * Budgets, rate limits, rules and kill switches in force.
	 *
	 * @return array<string, mixed>
	 */
	public function limits(): array {
		$subjects = array_unique( array_merge( array( 'global' ), array_keys( $this->settings->policies() ) ) );
		$budgets  = array();
		foreach ( $subjects as $subject ) {
			if ( 0 === strpos( $subject, 'provider:' ) ) {
				continue;
			}
			$policy    = $this->settings->policy( $subject );
			$budgets[] = array(
				'subject'    => $subject,
				'budget'     => $this->policy->budget( $subject ),
				'used'       => $this->policy->used( $subject ),
				'per_minute' => $this->policy->rate_limit( $subject ),
				'contexts'   => $policy['contexts'],
				'providers'  => $policy['providers'],
				'models'     => $policy['models'],
				'roles'      => $policy['roles'],
			);
		}

		$rules = array();
		foreach ( $this->detectors->all() as $id => $detector ) {
			$rules[] = array(
				'id'    => $id,
				'label' => $detector['label'],
				'mode'  => $this->detectors->mode( $id ),
			);
		}

		return array(
			'currency'     => $this->prices->currency(),
			'budgets'      => $budgets,
			'rate_limits'  => array(
				'global' => $this->policy->rate_limit( 'global' ),
				'plugin' => (int) $this->settings->get( 'rate_plugin' ),
				'user'   => $this->policy->rate_limit( 'user:0' ),
				'ip'     => $this->policy->rate_limit( 'ip:0' ),
			),
			'rules'        => $rules,
			'kill'         => $this->kill->active(),
			'new_callers'  => (string) $this->settings->get( 'new_caller' ),
			'soft_percent' => (int) $this->settings->get( 'soft_ratio' ),
		);
	}

	/**
	 * A call as REST, abilities and WP-CLI show it.
	 *
	 * @param array $row          Row.
	 * @param bool  $with_preview Whether to include the stored preview.
	 * @return array<string, mixed>
	 */
	public static function present( array $row, bool $with_preview = false ): array {
		$out = array(
			'id'                => (int) $row['id'],
			'started_at'        => gmdate( 'c', (int) strtotime( (string) $row['started_at'] . ' UTC' ) ),
			'status'            => (string) $row['status'],
			'block_rule'        => (string) $row['block_rule'],
			'path'              => (string) $row['path'],
			'caller'            => (string) $row['caller_slug'],
			'caller_type'       => (string) $row['caller_type'],
			'caller_file'       => (string) $row['caller_file'],
			'user_id'           => (int) $row['user_id'],
			'context'           => (string) $row['context'],
			'provider'          => (string) $row['provider'],
			'model'             => (string) $row['model'],
			'capability'        => (string) $row['capability'],
			'prompt_tokens'     => (int) $row['prompt_tokens'],
			'completion_tokens' => (int) $row['completion_tokens'],
			'thought_tokens'    => (int) $row['thought_tokens'],
			'total_tokens'      => (int) $row['total_tokens'],
			'estimated'         => (bool) $row['estimated'],
			'cost_micros'       => (int) $row['cost_micros'],
			'currency'          => (string) $row['currency'],
			'duration_ms'       => (int) $row['duration_ms'],
			'request_bytes'     => (int) $row['request_bytes'],
			'response_bytes'    => (int) $row['response_bytes'],
			'http_status'       => (int) $row['http_status'],
			'error_code'        => (string) $row['error_code'],
			'profile'           => (object) (array) $row['profile'],
		);
		if ( $with_preview ) {
			$out['preview'] = null === $row['preview'] ? null : (string) $row['preview'];
		}

		return $out;
	}

	/**
	 * Runs the data rules on a text, with no AI call.
	 *
	 * @param string $text   Text.
	 * @param string $caller Caller key the modes are for.
	 * @return array{findings: array<int, array{id: string, label: string, count: int, mode: string}>, redacted: string, blocked: bool}
	 */
	public function test_rules( string $text, string $caller = '' ): array {
		$findings = $this->detectors->scan( $text, $caller );
		$list     = array();
		$redact   = array();
		$blocked  = false;

		foreach ( $findings as $id => $finding ) {
			$list[] = array(
				'id'    => $id,
				'label' => $finding['label'],
				'count' => $finding['count'],
				'mode'  => $finding['mode'],
			);
			if ( 'redact' === $finding['mode'] ) {
				$redact[] = $id;
			}
			$blocked = $blocked || 'block' === $finding['mode'];
		}

		return array(
			'findings' => $list,
			'redacted' => $this->detectors->redact( $text, $redact )[0],
			'blocked'  => $blocked,
		);
	}
}
