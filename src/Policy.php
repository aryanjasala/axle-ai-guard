<?php
/**
 * Budgets, rate limits and rules: whether a call may go ahead.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Checks a call in three phases. "pre" runs before the model is known (kill
 * switches, caller approval, contexts, roles, used-up budgets, rate limits).
 * "model" runs once the provider and model are known. "estimate" adds the
 * estimated size of the call to the budgets. Each phase goes through the
 * axle_ai_guard_policy filter, which has the final say.
 */
final class Policy {

	public const LIMITS = array( 'daily_tokens', 'monthly_tokens', 'daily_cost', 'monthly_cost' );

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
	 * Sets up the policy.
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
	 * The subjects whose usage a call counts towards.
	 *
	 * @param array $call Call details.
	 * @return string[]
	 */
	public static function subjects( array $call ): array {
		$subjects = array( 'global', 'plugin:' . (string) ( $call['caller']['slug'] ?? 'unknown' ) );
		if ( ! empty( $call['provider'] ) ) {
			$subjects[] = 'provider:' . (string) $call['provider'];
		}
		if ( ! empty( $call['user_id'] ) ) {
			$subjects[] = 'user:' . (int) $call['user_id'];
		}

		return $subjects;
	}

	/**
	 * The budget of a subject. 0 means no limit.
	 *
	 * @param string $subject global or plugin:<caller>.
	 * @return array{daily_tokens: int, monthly_tokens: int, daily_cost: int, monthly_cost: int}
	 */
	public function budget( string $subject ): array {
		$policy = $this->settings->policy( $subject );
		$budget = array();
		foreach ( self::LIMITS as $key ) {
			$budget[ $key ] = (int) $policy[ $key ];
		}

		/**
		 * Filters the budget of a subject. Costs are micro-units of the site currency; 0 is no limit.
		 *
		 * @param array  $budget  daily_tokens, monthly_tokens, daily_cost, monthly_cost.
		 * @param string $subject global or plugin:<caller>.
		 */
		$filtered = apply_filters( 'axle_ai_guard_budget', $budget, $subject );
		$filtered = Hooks::arr( $filtered, $budget );
		$clean    = array();
		foreach ( self::LIMITS as $key ) {
			$clean[ $key ] = isset( $filtered[ $key ] ) ? max( 0, (int) $filtered[ $key ] ) : $budget[ $key ];
		}

		return $clean;
	}

	/**
	 * Calls per minute allowed for a subject. 0 means no limit.
	 *
	 * @param string $subject global, plugin:<caller>, user:<id> or ip:<address>.
	 * @return int
	 */
	public function rate_limit( string $subject ): int {
		$kind  = (string) strtok( $subject, ':' );
		$map   = array(
			'global' => 'rate_global',
			'plugin' => 'rate_plugin',
			'user'   => 'rate_user',
			'ip'     => 'rate_ip',
		);
		$limit = isset( $map[ $kind ] ) ? (int) $this->settings->get( $map[ $kind ] ) : 0;

		if ( in_array( $kind, array( 'global', 'plugin' ), true ) ) {
			$own = (int) $this->settings->policy( $subject )['per_minute'];
			if ( $own >= 0 ) {
				$limit = $own;
			}
		}

		/**
		 * Filters the calls per minute allowed for a subject.
		 *
		 * @param int    $limit   Default 120 global, 60 per caller, 20 per user, 5 per anonymous IP. 0 is no limit.
		 * @param string $subject global, plugin:<caller>, user:<id> or ip:<address>.
		 */
		return max( 0, (int) apply_filters( 'axle_ai_guard_rate_limit', $limit, $subject ) );
	}

	/**
	 * The share of a budget that triggers the soft alert.
	 *
	 * @param string $subject Subject.
	 * @return float Between 0.01 and 1.
	 */
	public function soft_ratio( string $subject ): float {
		$ratio = (int) $this->settings->get( 'soft_ratio' ) / 100;

		/**
		 * Filters the share of a budget that sends the soft limit alert.
		 *
		 * @param float  $ratio   Default 0.8.
		 * @param string $subject Subject.
		 */
		$filtered = (float) apply_filters( 'axle_ai_guard_soft_limit_ratio', $ratio, $subject );

		return $filtered > 0 && $filtered <= 1 ? $filtered : ( $ratio > 0 ? $ratio : 0.8 );
	}

	/**
	 * Checks that need no model: kill switches, caller approval, contexts,
	 * roles, used-up budgets and rate limits. Rate limits count the call.
	 *
	 * @param array $call Call details.
	 * @return array{rule: string, message: string}|null A block, or null.
	 */
	public function pre( array $call ): ?array {
		return $this->decide( $call, 'pre', $this->pre_block( $call ) );
	}

	/**
	 * Checks once the provider and model are known.
	 *
	 * @param array $call Call details with provider and model.
	 * @return array{rule: string, message: string}|null
	 */
	public function model( array $call ): ?array {
		$block = null;
		$rule  = $this->kill->rule_for( $call );
		if ( '' !== $rule ) {
			$block = $this->block( $rule, $call );
		}

		foreach ( $this->policy_subjects( $call ) as $subject ) {
			if ( $block ) {
				break;
			}
			$policy = $this->settings->policy( $subject );
			if ( $policy['providers'] && ! in_array( strtolower( (string) $call['provider'] ), $policy['providers'], true ) ) {
				$block = $this->block( 'provider', $call );
			} elseif ( $policy['models'] && ! self::model_allowed( (string) $call['provider'], (string) $call['model'], $policy['models'] ) ) {
				$block = $this->block( 'model', $call );
			}
		}

		return $this->decide( $call, 'model', $block );
	}

	/**
	 * Adds the estimated size of a call to the budgets.
	 *
	 * @param array $call   Call details.
	 * @param int   $tokens Estimated tokens.
	 * @param int   $cost   Estimated cost in micro-units.
	 * @return array{rule: string, message: string}|null
	 */
	public function estimate( array $call, int $tokens, int $cost ): ?array {
		$block = null;
		foreach ( $this->policy_subjects( $call ) as $subject ) {
			if ( '' !== $this->over_budget( $subject, $tokens, $cost, true ) ) {
				$block = $this->block( 'budget', $call );
				break;
			}
		}

		return $this->decide( $call, 'estimate', $block );
	}

	/**
	 * Lets the policy filter change a decision.
	 *
	 * @param array      $call  Call details.
	 * @param string     $phase pre, model, estimate or data.
	 * @param array|null $block The block, or null to allow.
	 * @return array{rule: string, message: string}|null
	 */
	public function decide( array $call, string $phase, ?array $block ): ?array {
		$decision = array(
			'allow'   => null === $block,
			'rule'    => (string) ( $block['rule'] ?? '' ),
			'message' => (string) ( $block['message'] ?? '' ),
		);

		/**
		 * Filters the decision for an AI call. Return allow false with a rule to block it.
		 *
		 * @param array  $decision allow, rule and message.
		 * @param array  $call     Call details: path, caller, context, user_id, roles, ip, provider, model, capability.
		 * @param string $phase    pre (no model yet), model, estimate or data.
		 */
		$filtered = apply_filters( 'axle_ai_guard_policy', $decision, $call, $phase );
		$filtered = Hooks::arr( $filtered, array() );
		if ( ! array_key_exists( 'allow', $filtered ) ) {
			return $block;
		}
		if ( $filtered['allow'] ) {
			return null;
		}

		$rule = strtolower( (string) preg_replace( '/[^A-Za-z0-9_:\-]/', '', (string) ( $filtered['rule'] ?? '' ) ) );
		$rule = '' !== $rule ? $rule : 'policy';

		return array(
			'rule'    => substr( $rule, 0, 64 ),
			'message' => '' !== (string) ( $filtered['message'] ?? '' ) ? (string) $filtered['message'] : self::message( $rule, $call ),
		);
	}

	/**
	 * A block with its message.
	 *
	 * @param string $rule Rule.
	 * @param array  $call Call details.
	 * @return array{rule: string, message: string}
	 */
	public function block( string $rule, array $call ): array {
		return array(
			'rule'    => $rule,
			'message' => self::message( $rule, $call ),
		);
	}

	/**
	 * The message a blocked caller sees.
	 *
	 * @param string $rule Rule.
	 * @param array  $call Call details.
	 * @return string
	 */
	public static function message( string $rule, array $call ): string {
		$base    = (string) strtok( $rule, ':' );
		$map     = array(
			'kill'     => __( 'AI calls are switched off on this site (Axle AI Guard kill switch).', 'axle-ai-guard' ),
			'caller'   => __( 'This plugin is not approved to make AI calls on this site (Axle AI Guard).', 'axle-ai-guard' ),
			'context'  => __( 'AI calls are not allowed in this context on this site (Axle AI Guard).', 'axle-ai-guard' ),
			'role'     => __( 'Your role may not make AI calls on this site (Axle AI Guard).', 'axle-ai-guard' ),
			'budget'   => __( 'The AI budget is used up (Axle AI Guard).', 'axle-ai-guard' ),
			'rate'     => __( 'Too many AI calls, try again in a minute (Axle AI Guard rate limit).', 'axle-ai-guard' ),
			'provider' => __( 'This AI provider is not allowed on this site (Axle AI Guard).', 'axle-ai-guard' ),
			'model'    => __( 'This AI model is not allowed on this site (Axle AI Guard).', 'axle-ai-guard' ),
			'files'    => __( 'Files may not be sent to AI providers from here (Axle AI Guard).', 'axle-ai-guard' ),
			'data'     => __( 'The prompt contains data that may not be sent to AI providers (Axle AI Guard data rule).', 'axle-ai-guard' ),
			'scan'     => __( 'The prompt is too large to check for personal data, so it was not sent (Axle AI Guard).', 'axle-ai-guard' ),
		);
		$message = $map[ $base ] ?? __( 'This AI call was blocked by Axle AI Guard.', 'axle-ai-guard' );
		if ( 'data' === $base ) {
			$message .= ' ' . sprintf(
				/* translators: %s: detector id such as password_hash. */
				__( 'Rule: %s.', 'axle-ai-guard' ),
				substr( $rule, 5 )
			);
		}

		/**
		 * Filters the message a caller gets when its AI call is blocked.
		 *
		 * @param string $message Message.
		 * @param string $rule    Rule, such as budget, rate or data:password_hash.
		 * @param array  $call    Call details.
		 */
		$filtered = apply_filters( 'axle_ai_guard_block_message', $message, $rule, $call );

		return Hooks::str( $filtered, $message );
	}

	/**
	 * Counts a finished call and sends budget alerts when it crosses a limit.
	 *
	 * @param array $call   Call details.
	 * @param int   $tokens Tokens used.
	 * @param int   $cost   Cost in micro-units.
	 */
	public function record( array $call, int $tokens, int $cost ): void {
		$watched = $this->policy_subjects( $call );
		$before  = array();
		foreach ( $watched as $subject ) {
			$before[ $subject ] = $this->used( $subject );
		}

		$this->usage->add( self::subjects( $call ), 1, $tokens, $cost );

		foreach ( $watched as $subject ) {
			$this->crossings( $subject, $before[ $subject ], $this->used( $subject ) );
		}
	}

	/**
	 * Counts a blocked call.
	 *
	 * @param array $call Call details.
	 */
	public function record_block( array $call ): void {
		$this->usage->add( self::subjects( $call ), 0, 0, 0, 1 );
	}

	/**
	 * Usage of a subject today and this month.
	 *
	 * @param string $subject Subject.
	 * @return array{daily_tokens: int, monthly_tokens: int, daily_cost: int, monthly_cost: int}
	 */
	public function used( string $subject ): array {
		$periods = Usage::periods();
		$day     = $this->usage->get( $periods['day'], $subject );
		$month   = $this->usage->get( $periods['month'], $subject );

		return array(
			'daily_tokens'   => $day['tokens'],
			'monthly_tokens' => $month['tokens'],
			'daily_cost'     => $day['cost'],
			'monthly_cost'   => $month['cost'],
		);
	}

	/**
	 * The first limit a subject is over, or ''.
	 *
	 * @param string $subject   Subject.
	 * @param int    $tokens    Tokens to add.
	 * @param int    $cost      Cost to add.
	 * @param bool   $exceeding True: over when used plus the addition goes past the limit. False: over when used reached it.
	 * @return string The limit key, or ''.
	 */
	public function over_budget( string $subject, int $tokens = 0, int $cost = 0, bool $exceeding = false ): string {
		$budget = $this->budget( $subject );
		if ( ! array_filter( $budget ) ) {
			return '';
		}

		$used = $this->used( $subject );
		foreach ( self::LIMITS as $key ) {
			if ( $budget[ $key ] <= 0 ) {
				continue;
			}
			$add   = false !== strpos( $key, 'tokens' ) ? $tokens : $cost;
			$value = $used[ $key ] + $add;
			if ( $exceeding ? $value > $budget[ $key ] : $value >= $budget[ $key ] ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Whether a model is in an allow list (provider/model, model, or a * wildcard).
	 *
	 * @param string   $provider Provider id.
	 * @param string   $model    Model id.
	 * @param string[] $allowed  Allowed entries.
	 * @return bool
	 */
	public static function model_allowed( string $provider, string $model, array $allowed ): bool {
		$candidates = array( strtolower( $model ), strtolower( $provider . '/' . $model ) );
		foreach ( $allowed as $entry ) {
			foreach ( $candidates as $candidate ) {
				if ( $entry === $candidate || ( '*' === substr( $entry, -1 ) && 0 === strpos( $candidate, substr( $entry, 0, -1 ) ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * The policy subjects of a call: global and its caller.
	 *
	 * @param array $call Call details.
	 * @return string[]
	 */
	private function policy_subjects( array $call ): array {
		return array( 'global', 'plugin:' . (string) ( $call['caller']['slug'] ?? 'unknown' ) );
	}

	/**
	 * The block from the pre checks, before the filter.
	 *
	 * @param array $call Call details.
	 * @return array{rule: string, message: string}|null
	 */
	private function pre_block( array $call ): ?array {
		$rule = $this->kill->rule_for( $call );
		if ( '' !== $rule ) {
			return $this->block( $rule, $call );
		}

		$status = $this->settings->caller_status( (string) ( $call['caller']['slug'] ?? '' ) );
		if ( in_array( $status, array( 'pending', 'denied' ), true ) ) {
			return $this->block( 'caller', $call );
		}

		foreach ( $this->policy_subjects( $call ) as $subject ) {
			$policy = $this->settings->policy( $subject );
			if ( $policy['contexts'] && ! in_array( (string) $call['context'], $policy['contexts'], true ) ) {
				return $this->block( 'context', $call );
			}
			if ( $policy['roles'] && ! array_intersect( (array) $call['roles'], $policy['roles'] ) ) {
				return $this->block( 'role', $call );
			}
		}

		foreach ( $this->policy_subjects( $call ) as $subject ) {
			if ( '' !== $this->over_budget( $subject ) ) {
				return $this->block( 'budget', $call );
			}
		}

		$rate_subjects = $this->policy_subjects( $call );
		if ( ! empty( $call['user_id'] ) ) {
			$rate_subjects[] = 'user:' . (int) $call['user_id'];
		} elseif ( ! empty( $call['ip'] ) ) {
			$rate_subjects[] = 'ip:' . (string) $call['ip'];
		}
		foreach ( $rate_subjects as $subject ) {
			$limit = $this->rate_limit( $subject );
			if ( $limit <= 0 ) {
				continue;
			}
			$count = $this->usage->hit_minute( $subject );
			if ( $count > $limit ) {
				/**
				 * Fires when a call goes over a rate limit.
				 *
				 * @param string $subject global, plugin:<caller>, user:<id> or ip:<address>.
				 * @param int    $limit   Calls per minute allowed.
				 * @param array  $call    Call details.
				 */
				do_action( 'axle_ai_guard_rate_limited', $subject, $limit, $call );

				return $this->block( 'rate', $call );
			}
		}

		return null;
	}

	/**
	 * Fires soft and hard budget events when usage crosses them.
	 *
	 * @param string $subject Subject.
	 * @param array  $before  Usage before the call.
	 * @param array  $after   Usage after the call.
	 */
	private function crossings( string $subject, array $before, array $after ): void {
		$budget = $this->budget( $subject );
		$ratio  = $this->soft_ratio( $subject );

		foreach ( self::LIMITS as $key ) {
			$limit = $budget[ $key ];
			if ( $limit <= 0 ) {
				continue;
			}
			$money = false !== strpos( $key, 'cost' );

			if ( $before[ $key ] < $limit * $ratio && $after[ $key ] >= $limit * $ratio ) {
				/**
				 * Fires when a subject passes the soft share of a budget.
				 *
				 * @param string $subject global or plugin:<caller>.
				 * @param string $limit   daily_tokens, monthly_tokens, daily_cost or monthly_cost.
				 * @param int    $used    Usage now.
				 * @param int    $budget  The limit.
				 */
				do_action( 'axle_ai_guard_budget_soft_reached', $subject, $key, $after[ $key ], $limit );
				$this->alerts->send(
					'budget_soft',
					$subject . ':' . $key,
					sprintf(
						/* translators: 1: subject, 2: percentage, 3: limit name. */
						__( '%1$s used %2$d%% of its %3$s budget.', 'axle-ai-guard' ),
						$subject,
						(int) round( $after[ $key ] / $limit * 100 ),
						str_replace( '_', ' ', $key )
					),
					array(
						'limit'  => $key,
						'used'   => $after[ $key ],
						'budget' => $limit,
						'money'  => $money,
					)
				);
			}
			if ( $before[ $key ] < $limit && $after[ $key ] >= $limit ) {
				/**
				 * Fires when a subject reaches a hard budget limit. Calls are blocked from now on.
				 *
				 * @param string $subject global or plugin:<caller>.
				 * @param string $limit   daily_tokens, monthly_tokens, daily_cost or monthly_cost.
				 * @param int    $used    Usage now.
				 * @param int    $budget  The limit.
				 */
				do_action( 'axle_ai_guard_budget_hard_reached', $subject, $key, $after[ $key ], $limit );
				$this->alerts->send(
					'budget_hard',
					$subject . ':' . $key,
					sprintf(
						/* translators: 1: subject such as plugin:caller-a, 2: limit name. */
						__( '%1$s reached its %2$s budget. Its AI calls are blocked until the period ends or the budget is raised.', 'axle-ai-guard' ),
						$subject,
						str_replace( '_', ' ', $key )
					),
					array(
						'limit'  => $key,
						'used'   => $after[ $key ],
						'budget' => $limit,
					)
				);

				if ( 'global' === $subject && 'monthly_cost' === $key && $this->settings->get( 'autotrip_budget' ) ) {
					$this->kill->engage( 'global', '', __( 'The monthly money budget was reached.', 'axle-ai-guard' ), 0 );
				}
			}
		}
	}
}
