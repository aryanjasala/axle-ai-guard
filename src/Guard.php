<?php
/**
 * The interception layer: every AI Client call goes through here.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * In the order of a call:
 *
 * 1. wp_supports_ai: the global and per-context kill switches.
 * 2. wp_ai_client_prevent_prompt: caller, context and user are known. Kill
 *    switches, caller approval, contexts, roles, used-up budgets and rate
 *    limits. Support checks (is_supported_*) only see the kill switches.
 * 3. wp_ai_client_before_generate_result: provider and model are known. A
 *    pending row is opened; provider and model rules, estimated budgets,
 *    file rules and data rules in block mode stop the call by throwing
 *    BlockedException, which the core builder turns into a WP_Error.
 * 4. The transport decorator redacts and measures the HTTP request.
 * 5. wp_ai_client_after_generate_result: tokens, cost, counters, row to ok.
 * 6. shutdown: any row still pending becomes error.
 *
 * Anything that goes wrong inside the guard lets the call through (fail
 * open), unless axle_ai_guard_fail_closed says otherwise, and shows an admin
 * notice that the guard is degraded.
 */
final class Guard implements Service {

	public const DEGRADED = 'axle_ai_guard_degraded';

	/**
	 * Tells safely() to throw a BlockedException when failing closed.
	 */
	private const THROW = "\0throw";

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
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Calls that have a pending row, innermost last.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $frames = array();

	/**
	 * Call details from the prevent filter, for the before event that follows.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $staged = null;

	/**
	 * Whether the transport decorator is sending a request right now.
	 *
	 * @var bool
	 */
	private bool $sending = false;

	/**
	 * Sets up the guard.
	 *
	 * @param Settings   $settings  Settings.
	 * @param Policy     $policy    Policy.
	 * @param KillSwitch $kill      Kill switches.
	 * @param Detectors  $detectors Data rules.
	 * @param Prices     $prices    Prices.
	 * @param Calls      $calls     The call log.
	 * @param Alerts     $alerts    Alerts.
	 */
	public function __construct( Settings $settings, Policy $policy, KillSwitch $kill, Detectors $detectors, Prices $prices, Calls $calls, Alerts $alerts ) {
		$this->settings  = $settings;
		$this->policy    = $policy;
		$this->kill      = $kill;
		$this->detectors = $detectors;
		$this->prices    = $prices;
		$this->calls     = $calls;
		$this->alerts    = $alerts;
	}

	/**
	 * Hooks into the AI Client.
	 */
	public function register(): void {
		add_filter( 'wp_supports_ai', array( $this, 'supports' ), 1000 );
		add_filter( 'wp_ai_client_prevent_prompt', array( $this, 'prevent' ), 1000 );
		add_action( 'wp_ai_client_before_generate_result', array( $this, 'before' ), 1000 );
		add_action( 'wp_ai_client_after_generate_result', array( $this, 'after' ), 1000 );
		// WordPress 7.2 adds embeddings with their own filter and events.
		add_filter( 'wp_ai_client_prevent_embedding', array( $this, 'prevent_embedding' ), 1000 );
		add_action( 'wp_ai_client_before_generate_embedding', array( $this, 'before_embedding' ), 1000 );
		add_action( 'wp_ai_client_after_generate_embedding', array( $this, 'after' ), 1000 );
		add_action( 'init', array( $this, 'install_transport' ), 1000 );
		add_action( 'shutdown', array( $this, 'close_open' ), 0 );
	}

	/**
	 * Filter wp_supports_ai: off while the global or the context kill switch is on.
	 *
	 * @param mixed $enabled Whether AI is supported.
	 * @return mixed
	 */
	public function supports( $enabled ) {
		if ( ! $enabled ) {
			return $enabled;
		}

		return $this->safely(
			function () {
				$context = Context::current();
				$global  = $this->kill->global_on();
				if ( ! $global && ! $this->kill->is_on( 'context', $context ) ) {
					return true;
				}

				// A real generation gets a blocked row; support checks and other callers do not.
				$method = Sdk::current_builder_method();
				if ( '' !== $method && ! Sdk::is_support_check( $method ) ) {
					$call = Context::call( 'client', Caller::detect() );
					$this->log_block( $call, $this->policy->block( $global ? 'kill:global' : 'kill:context', $call ) );
				}

				return false;
			},
			true,
			false
		);
	}

	/**
	 * Filter wp_ai_client_prevent_prompt.
	 *
	 * @param mixed $prevent Whether to prevent the prompt.
	 * @return bool
	 */
	public function prevent( $prevent ): bool {
		return $this->prevent_for( (bool) $prevent, 'client' );
	}

	/**
	 * Filter wp_ai_client_prevent_embedding (WordPress 7.2).
	 *
	 * @param mixed $prevent Whether to prevent the request.
	 * @return bool
	 */
	public function prevent_embedding( $prevent ): bool {
		return $this->prevent_for( (bool) $prevent, 'embedding' );
	}

	/**
	 * The checks that need no model.
	 *
	 * @param bool   $prevent Earlier decision.
	 * @param string $path    client or embedding.
	 * @return bool
	 */
	private function prevent_for( bool $prevent, string $path ): bool {
		if ( $prevent ) {
			return true;
		}

		return (bool) $this->safely(
			function () use ( $path ) {
				$method = Sdk::current_builder_method();
				$call   = Context::call( $path, Caller::detect() );
				$this->see_caller( $call );

				if ( Sdk::is_support_check( $method ) ) {
					/**
					 * Filters whether support checks (is_supported_*) count as calls and face every rule.
					 * By default only kill switches and caller approval apply, so a hidden feature costs nothing.
					 *
					 * @param bool  $count Default false.
					 * @param array $call  Call details.
					 */
					if ( ! apply_filters( 'axle_ai_guard_count_support_checks', false, $call ) ) {
						return '' !== $this->kill->rule_for( $call ) || in_array( $this->settings->caller_status( (string) $call['caller']['slug'] ), array( 'pending', 'denied' ), true );
					}
				}

				$block = $this->policy->pre( $call );
				if ( $block ) {
					$this->log_block( $call, $block );
					return true;
				}

				$call['prechecked'] = true;
				$this->staged       = $call;

				return false;
			},
			false,
			true
		);
	}

	/**
	 * Action wp_ai_client_before_generate_result.
	 *
	 * @param mixed $event BeforeGenerateResultEvent.
	 */
	public function before( $event ): void {
		$this->before_for( $event, 'client' );
	}

	/**
	 * Action wp_ai_client_before_generate_embedding (WordPress 7.2).
	 *
	 * @param mixed $event The embedding event.
	 */
	public function before_embedding( $event ): void {
		$this->before_for( $event, 'embedding' );
	}

	/**
	 * Opens the row and applies the rules that need the model and the messages.
	 *
	 * @param mixed  $event Event.
	 * @param string $path  client or embedding.
	 * @throws BlockedException When a rule blocks the call.
	 */
	private function before_for( $event, string $path ): void {
		if ( ! is_object( $event ) ) {
			return;
		}

		$this->safely(
			function () use ( $event, $path ) {
				$caller = Caller::detect();
				$staged = $this->staged;
				$call   = $staged && $staged['caller']['slug'] === $caller['slug'] ? $staged : Context::call( $path, $caller );

				$this->staged = null;

				$info               = Sdk::model( $event );
				$call['provider']   = $info['provider'];
				$call['model']      = $info['model'];
				$call['capability'] = $info['capability'];

				if ( empty( $call['prechecked'] ) ) {
					// Direct SDK use skips the prevent filter.
					$this->see_caller( $call );
					$this->stop_if( $call, $this->policy->pre( $call ), array() );
				}
				unset( $call['prechecked'] );

				$this->stop_if( $call, $this->policy->model( $call ), array() );

				$messages = Sdk::messages( $event );
				$profile  = $this->profile( $messages, $info, $call );

				$this->stop_if( $call, $this->policy->estimate( $call, (int) $profile['estimate']['tokens'], (int) $profile['estimate']['cost'] ), $profile );

				$block = null;
				if ( $profile['files'] && ( $this->settings->policy( 'global' )['block_files'] || $this->settings->policy( 'plugin:' . $call['caller']['slug'] )['block_files'] ) ) {
					$block = $this->policy->block( 'files', $call );
				}
				foreach ( $profile['actions'] as $id => $mode ) {
					if ( ! $block && 'block' === $mode ) {
						$block = $this->policy->block( 'data:' . $id, $call );
					}
				}
				if ( ! $block && $profile['too_large'] && $this->has_block_rules( (string) $call['caller']['slug'] ) ) {
					$block = $this->policy->block( 'scan', $call );
				}

				$block = $this->policy->decide( $call, 'data', $block );
				if ( $block && ! self::block_in_before( $call ) ) {
					// The exception path is off: the call goes ahead, block-mode data is redacted instead.
					$profile['not_blocked'] = $block['rule'];
					$block                  = null;
				}
				$this->stop_if( $call, $block, $profile );

				$redact = array();
				foreach ( $profile['actions'] as $id => $mode ) {
					if ( 'redact' === $mode || ( 'block' === $mode && isset( $profile['not_blocked'] ) ) ) {
						$redact[] = (string) $id;
					}
				}

				$row            = Calls::row_for( $call, 'pending' );
				$row['profile'] = $profile;
				$row['preview'] = $this->preview( $messages, $call, array_keys( $profile['findings'] ) );
				$id             = $this->calls->insert( $row );

				if ( $id && $this->settings->get( 'store_prompts' ) ) {
					$this->calls->store_prompt( $id, $this->redacted_messages( $messages, $info['system'], array_keys( $profile['findings'] ) ), (int) $this->settings->get( 'retention_prompts' ) );
				}

				$this->frames[] = array(
					'id'       => $id,
					'call'     => $call,
					'started'  => microtime( true ),
					'redact'   => $redact,
					'redacted' => array(),
					'http'     => array(),
				);

				/**
				 * Fires when an AI call starts: it passed every rule and its row is open.
				 *
				 * @param int   $id   Row id.
				 * @param array $call Call details.
				 */
				do_action( 'axle_ai_guard_call_started', $id, $call );
			},
			null,
			self::THROW
		);
	}

	/**
	 * Action wp_ai_client_after_generate_result (and the embedding one).
	 *
	 * @param mixed $event AfterGenerateResultEvent.
	 */
	public function after( $event ): void {
		if ( ! is_object( $event ) || ! $this->frames ) {
			return;
		}

		$this->safely(
			function () use ( $event ) {
				$frame = array_pop( $this->frames );
				if ( ! is_array( $frame ) ) {
					return;
				}
				$result = Sdk::result( $event );
				$call   = $frame['call'];
				if ( '' !== $result['provider'] ) {
					$call['provider'] = $result['provider'];
				}
				if ( '' !== $result['model'] ) {
					$call['model'] = $result['model'];
				}
				$cost = $this->prices->cost( (string) $call['provider'], (string) $call['model'], $result['prompt'], $result['completion'], $result['thought'] );

				$row = array_merge(
					$frame['http'],
					array(
						'status'            => 'ok',
						'duration_ms'       => (int) round( ( microtime( true ) - $frame['started'] ) * 1000 ),
						'provider'          => (string) $call['provider'],
						'model'             => (string) $call['model'],
						'prompt_tokens'     => $result['prompt'],
						'completion_tokens' => $result['completion'],
						'thought_tokens'    => $result['thought'],
						'total_tokens'      => $result['total'],
						'cost_micros'       => $cost,
						'currency'          => $this->prices->currency(),
					)
				);
				$row = $this->with_redactions( $frame, $row );
				$this->calls->update( (int) $frame['id'], $row );
				$this->policy->record( $call, $result['total'], $cost );

				/**
				 * Fires when an AI call finished and was counted.
				 *
				 * @param int   $id   Row id.
				 * @param array $row  The columns written: tokens, cost, duration, HTTP details.
				 * @param array $call Call details.
				 */
				do_action( 'axle_ai_guard_call_finished', (int) $frame['id'], $row, $call );
			},
			null,
			null
		);
	}

	/**
	 * Shutdown: rows still pending become errors.
	 */
	public function close_open(): void {
		while ( $this->frames ) {
			$this->fail_top( 'incomplete', 0 );
		}
	}

	/**
	 * Installs the transport decorator around whatever transport the SDK has.
	 */
	public function install_transport(): void {
		if ( ! class_exists( '\WordPress\AiClient\AiClient' ) || ! interface_exists( '\WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface' ) ) {
			return;
		}

		$this->safely(
			function () {
				$registry = \WordPress\AiClient\AiClient::defaultRegistry();
				try {
					$inner = $registry->getHttpTransporter();
				} catch ( \Throwable $e ) {
					$inner = \WordPress\AiClient\Providers\Http\HttpTransporterFactory::createTransporter();
				}
				if ( ! $inner instanceof Transport ) {
					$registry->setHttpTransporter( new Transport( $inner, $this, $this->detectors ) );
				}
			},
			null,
			null
		);
	}

	/**
	 * The innermost open call, for the transport.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current(): ?array {
		return $this->frames ? $this->frames[ count( $this->frames ) - 1 ] : null;
	}

	/**
	 * Whether the transport decorator is sending, so direct-call monitoring leaves it alone.
	 *
	 * @return bool
	 */
	public function is_sending(): bool {
		return $this->sending;
	}

	/**
	 * Set by the transport around its send.
	 *
	 * @param bool $sending Whether it is sending.
	 */
	public function set_sending( bool $sending ): void {
		$this->sending = $sending;
	}

	/**
	 * Records what the transport redacted in the innermost call.
	 *
	 * @param array<string, int> $counts Count per detector.
	 */
	public function note_redacted( array $counts ): void {
		if ( ! $this->frames ) {
			return;
		}
		$index = count( $this->frames ) - 1;
		foreach ( $counts as $id => $count ) {
			$this->frames[ $index ]['redacted'][ $id ] = (int) ( $this->frames[ $index ]['redacted'][ $id ] ?? 0 ) + (int) $count;
		}
	}

	/**
	 * Records the HTTP side of the innermost call. A failed response closes it as an error.
	 *
	 * @param int    $request_bytes  Request body size.
	 * @param int    $status         HTTP status, 0 when no response came.
	 * @param int    $response_bytes Response body size.
	 * @param string $error       Error code when the request failed.
	 */
	public function note_http( int $request_bytes, int $status, int $response_bytes, string $error = '' ): void {
		if ( ! $this->frames ) {
			return;
		}
		$index = count( $this->frames ) - 1;

		$this->frames[ $index ]['http'] = array(
			'request_bytes'  => $request_bytes,
			'response_bytes' => $response_bytes,
			'http_status'    => $status,
		);

		if ( '' !== $error || $status < 200 || $status >= 300 ) {
			$this->fail_top( '' !== $error ? $error : 'http_' . $status, $status );
		}
	}

	/**
	 * Closes the innermost call as an error.
	 *
	 * @param string $code   Error code.
	 * @param int    $status HTTP status.
	 */
	private function fail_top( string $code, int $status ): void {
		$frame = array_pop( $this->frames );
		if ( ! is_array( $frame ) ) {
			return;
		}

		$this->safely(
			function () use ( $frame, $code, $status ) {
				$row = array_merge(
					$frame['http'],
					array(
						'status'      => 'error',
						'duration_ms' => (int) round( ( microtime( true ) - $frame['started'] ) * 1000 ),
						'http_status' => $status,
						'error_code'  => substr( $code, 0, 100 ),
						'currency'    => $this->prices->currency(),
					)
				);
				$row = $this->with_redactions( $frame, $row );
				$this->calls->update( (int) $frame['id'], $row );
				$this->policy->record( $frame['call'], 0, 0 );

				/**
				 * Fires when an AI call failed: the provider answered with an error, the request did not
				 * go through, or the call never finished.
				 *
				 * @param int    $id   Row id.
				 * @param string $code Error code, such as http_500 or incomplete.
				 * @param array  $call Call details.
				 */
				do_action( 'axle_ai_guard_call_failed', (int) $frame['id'], $code, $frame['call'] );

				$this->check_error_spike();
			},
			null,
			null
		);
	}

	/**
	 * Logs a blocked call and tells whoever listens.
	 *
	 * @param array $call    Call details.
	 * @param array $block   rule and message.
	 * @param array $profile Data profile, when known.
	 * @return int Row id.
	 */
	public function log_block( array $call, array $block, array $profile = array() ): int {
		$row               = Calls::row_for( $call, 'blocked' );
		$row['block_rule'] = substr( (string) $block['rule'], 0, 64 );
		$row['currency']   = $this->prices->currency();
		$row['profile']    = $profile;
		$row['id']         = $this->calls->insert( $row );
		$this->policy->record_block( $call );

		/**
		 * Fires when an AI call is blocked.
		 *
		 * @param string $rule   The rule, such as kill:plugin, budget, rate or data:password_hash.
		 * @param array  $caller type, slug, file and line.
		 * @param array  $row    The logged row, with its id.
		 */
		do_action( 'axle_ai_guard_call_blocked', (string) $block['rule'], (array) $call['caller'], $row );

		if ( 0 === strpos( (string) $block['rule'], 'data:' ) || 'scan' === $block['rule'] ) {
			$this->alerts->send(
				'data_block',
				'plugin:' . $call['caller']['slug'],
				sprintf(
					/* translators: 1: caller key, 2: rule. */
					__( 'An AI call from %1$s was blocked by the data rule %2$s.', 'axle-ai-guard' ),
					(string) $call['caller']['slug'],
					(string) $block['rule']
				),
				array( 'rule' => $block['rule'] )
			);
		}

		return (int) $row['id'];
	}

	/**
	 * Whether a block in the before event throws.
	 *
	 * @param array $call Call details.
	 * @return bool
	 */
	public static function block_in_before( array $call ): bool {
		/**
		 * Filters whether rules that need the model or the messages block by throwing an exception
		 * from the before event, which core turns into a WP_Error. When false, such calls go ahead:
		 * data rules in block mode redact instead, and the row records what would have been blocked.
		 *
		 * @param bool  $block Default true.
		 * @param array $call  Call details.
		 */
		return (bool) apply_filters( 'axle_ai_guard_block_in_before_event', true, $call );
	}

	/**
	 * Throws when there is a block.
	 *
	 * @param array      $call    Call details.
	 * @param array|null $block   The block.
	 * @param array      $profile Data profile.
	 * @throws BlockedException When blocked.
	 */
	private function stop_if( array $call, ?array $block, array $profile ): void {
		if ( ! $block ) {
			return;
		}
		$this->log_block( $call, $block, $profile );

		throw new BlockedException( esc_html( (string) $block['rule'] ), esc_html( (string) $block['message'] ) );
	}

	/**
	 * Records a caller seen for the first time, and alerts when the default says so.
	 *
	 * @param array $call Call details.
	 */
	private function see_caller( array $call ): void {
		$caller = (array) $call['caller'];
		if ( '' !== $this->settings->caller_status( (string) $caller['slug'] ) ) {
			return;
		}

		$mode = (string) $this->settings->get( 'new_caller' );

		/**
		 * Filters what happens to a caller seen for the first time: allow, alert (allow and send
		 * an alert) or deny (block until approved).
		 *
		 * @param string $mode   Default from the settings, alert.
		 * @param array  $caller type, slug, file and line.
		 */
		$filtered = apply_filters( 'axle_ai_guard_new_caller_default', $mode, $caller );
		$mode     = in_array( $filtered, Settings::NEW_CALLER_MODES, true ) ? (string) $filtered : $mode;

		$this->settings->remember_caller( $caller, 'deny' === $mode ? 'pending' : 'approved' );

		/**
		 * Fires when a caller makes its first AI call.
		 *
		 * @param array  $caller type, slug, file and line.
		 * @param string $mode   allow, alert or deny.
		 */
		do_action( 'axle_ai_guard_new_caller_seen', $caller, $mode );

		if ( 'allow' !== $mode ) {
			$this->alerts->send(
				'new_caller',
				'plugin:' . $caller['slug'],
				'deny' === $mode
					/* translators: %s: caller key. */
					? sprintf( __( '%s tried to make its first AI call. It is blocked until you approve it.', 'axle-ai-guard' ), (string) $caller['slug'] )
					/* translators: %s: caller key. */
					: sprintf( __( '%s made its first AI call.', 'axle-ai-guard' ), (string) $caller['slug'] ),
				array( 'caller' => $caller )
			);
		}
	}

	/**
	 * The data profile of a call: what it carried, never the text itself.
	 *
	 * @param array $messages From Sdk::messages().
	 * @param array $info     From Sdk::model().
	 * @param array $call     Call details.
	 * @return array<string, mixed>
	 */
	public function profile( array $messages, array $info, array $call ): array {
		$chars = array();
		$files = array();
		$text  = '';

		foreach ( $messages as $message ) {
			$role = (string) $message['role'];
			foreach ( $message['texts'] as $part ) {
				$chars[ $role ] = ( $chars[ $role ] ?? 0 ) + strlen( $part );
				$text          .= $part . "\n";
			}
			foreach ( $message['files'] as $file ) {
				$files[] = $file;
			}
		}
		$text .= (string) $info['system'];

		$max = self::max_scan_bytes( $call );
		$big = strlen( $text ) > $max;

		$findings = $big ? array() : $this->detectors->scan( $text, (string) $call['caller']['slug'] );
		$counts   = array();
		$actions  = array();
		foreach ( $findings as $id => $finding ) {
			$counts[ $id ]  = $finding['count'];
			$actions[ $id ] = $finding['mode'];
		}

		$tokens = self::estimate_tokens( strlen( $text ), (int) $info['max_tokens'], $call );
		$input  = self::estimate_tokens( strlen( $text ), 0, $call );

		return array(
			'messages'  => count( $messages ),
			'chars'     => $chars,
			'system'    => strlen( (string) $info['system'] ),
			'files'     => $files,
			'findings'  => $counts,
			'actions'   => $actions,
			'scanned'   => $big ? 0 : strlen( $text ),
			'too_large' => $big,
			'estimate'  => array(
				'tokens' => $tokens,
				'cost'   => $this->prices->cost( (string) $call['provider'], (string) $call['model'], $input, $tokens - $input ),
			),
		);
	}

	/**
	 * Estimated tokens of a call: characters divided by four, plus the max output tokens when set.
	 *
	 * @param int   $chars      Characters of the prompt.
	 * @param int   $max_tokens Max output tokens, 0 when not set.
	 * @param array $call       Call details.
	 * @return int
	 */
	public static function estimate_tokens( int $chars, int $max_tokens, array $call ): int {
		$estimate = (int) ceil( $chars / 4 ) + max( 0, $max_tokens );

		/**
		 * Filters the estimated tokens of a call, used for budget checks before it runs.
		 *
		 * @param int   $estimate   Characters / 4, plus the max output tokens when the caller set them.
		 * @param int   $chars      Prompt characters.
		 * @param int   $max_tokens Max output tokens, 0 when not set.
		 * @param array $call       Call details.
		 */
		return max( 0, (int) apply_filters( 'axle_ai_guard_token_estimator', $estimate, $chars, $max_tokens, $call ) );
	}

	/**
	 * The most prompt text the data rules scan.
	 *
	 * @param array $call Call details.
	 * @return int Bytes.
	 */
	public static function max_scan_bytes( array $call ): int {
		/**
		 * Filters how much prompt text the data rules scan. Above it, rules in block mode block the call.
		 *
		 * @param int   $bytes Default 200 KB.
		 * @param array $call  Call details.
		 */
		return max( 1024, (int) apply_filters( 'axle_ai_guard_max_scan_bytes', 200 * KB_IN_BYTES, $call ) );
	}

	/**
	 * Whether any detector is in block mode for a caller.
	 *
	 * @param string $caller Caller key.
	 * @return bool
	 */
	private function has_block_rules( string $caller ): bool {
		foreach ( array_keys( $this->detectors->all() ) as $id ) {
			if ( 'block' === $this->detectors->mode( $id, $caller ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The stored preview: the start of the last user message, redacted. Null when previews are off.
	 *
	 * @param array    $messages From Sdk::messages().
	 * @param array    $call     Call details.
	 * @param string[] $ids      Detectors to redact.
	 * @return string|null
	 */
	private function preview( array $messages, array $call, array $ids ): ?string {
		/**
		 * Filters whether a preview of the prompt is stored with the call.
		 *
		 * @param bool  $store Default from the settings, off.
		 * @param array $call  Call details.
		 */
		if ( ! apply_filters( 'axle_ai_guard_store_preview', (bool) $this->settings->get( 'store_preview' ), $call ) ) {
			return null;
		}

		$text = '';
		foreach ( $messages as $message ) {
			if ( 'user' === $message['role'] ) {
				$text = implode( "\n", $message['texts'] );
			}
		}

		/**
		 * Filters how many characters of the prompt the preview keeps.
		 *
		 * @param int   $length Default 300.
		 * @param array $call   Call details.
		 */
		$length = max( 20, min( 5000, (int) apply_filters( 'axle_ai_guard_preview_length', 300, $call ) ) );

		return mb_substr( $this->detectors->redact( $text, $ids )[0], 0, $length );
	}

	/**
	 * Messages with every finding redacted, for the prompt store.
	 *
	 * @param array    $messages From Sdk::messages().
	 * @param string   $system   System instruction.
	 * @param string[] $ids      Detectors to redact.
	 * @return array
	 */
	private function redacted_messages( array $messages, string $system, array $ids ): array {
		$out = array();
		if ( '' !== $system ) {
			$out[] = array(
				'role' => 'system',
				'text' => $this->detectors->redact( $system, $ids )[0],
			);
		}
		foreach ( $messages as $message ) {
			$out[] = array(
				'role'  => $message['role'],
				'text'  => $this->detectors->redact( implode( "\n", $message['texts'] ), $ids )[0],
				'files' => $message['files'],
			);
		}

		return $out;
	}

	/**
	 * Adds what the transport redacted to the row's profile.
	 *
	 * @param array $frame Frame.
	 * @param array $row   Row columns.
	 * @return array
	 */
	private function with_redactions( array $frame, array $row ): array {
		if ( $frame['redacted'] && $frame['id'] ) {
			$current = $this->calls->get( (int) $frame['id'] );
			$profile = $current ? (array) $current['profile'] : array();

			$profile['redacted'] = $frame['redacted'];
			$row['profile']      = $profile;
		}

		return $row;
	}

	/**
	 * Trips the global switch when the error rate of the last 5 minutes passes the threshold.
	 */
	private function check_error_spike(): void {
		/**
		 * Filters the error rate, in percent over 5 minutes, that switches AI off. 0 turns it off.
		 *
		 * @param int $threshold Default from the settings, 0.
		 */
		$threshold = min( 100, max( 0, (int) apply_filters( 'axle_ai_guard_autotrip_error_threshold', (int) $this->settings->get( 'autotrip_errors' ) ) ) );
		if ( 0 === $threshold || $this->kill->global_on() ) {
			return;
		}

		$rate = $this->calls->error_rate( gmdate( 'Y-m-d H:i:s', time() - 5 * MINUTE_IN_SECONDS ) );
		if ( $rate['calls'] < 5 || $rate['errors'] * 100 < $threshold * $rate['calls'] ) {
			return;
		}

		/* translators: 1: failed calls, 2: all calls. */
		$reason = sprintf( __( '%1$d of %2$d AI calls failed in 5 minutes.', 'axle-ai-guard' ), $rate['errors'], $rate['calls'] );
		$this->alerts->send( 'error_spike', 'global', $reason, $rate );
		$this->kill->engage( 'global', '', $reason, 0 );
	}

	/**
	 * Runs guard code; if it breaks, lets the call through and says the guard is degraded.
	 *
	 * @param callable $work       The code.
	 * @param mixed    $open       What to return when failing open.
	 * @param mixed    $closed     What to return when failing closed; self::THROW throws a BlockedException.
	 * @return mixed
	 * @throws \RuntimeException When the tables are missing; caught here.
	 * @throws BlockedException When a rule blocks, or when failing closed in the before event.
	 */
	private function safely( callable $work, $open, $closed ) {
		try {
			if ( ! Schema::ready() ) {
				throw new \RuntimeException( esc_html__( 'The Axle AI Guard tables are missing. Deactivate and activate the plugin to create them.', 'axle-ai-guard' ) );
			}

			return $work();
		} catch ( BlockedException $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			set_transient( self::DEGRADED, substr( $e->getMessage(), 0, 300 ), DAY_IN_SECONDS );

			/**
			 * Filters whether AI calls are blocked while the guard itself is failing. Default false:
			 * a broken guard lets calls through.
			 *
			 * @param bool       $closed Default false.
			 * @param \Throwable $error  What went wrong.
			 */
			if ( ! apply_filters( 'axle_ai_guard_fail_closed', false, $e ) ) {
				return $open;
			}
			if ( self::THROW === $closed ) {
				throw new BlockedException( 'guard', esc_html__( 'Axle AI Guard is not working, and this site blocks AI calls until it is fixed.', 'axle-ai-guard' ) );
			}

			return $closed;
		}
	}
}
