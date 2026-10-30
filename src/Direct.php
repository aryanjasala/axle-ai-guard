<?php
/**
 * Optional monitoring of AI calls that skip the AI Client.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Off by default. When on, requests through the WordPress HTTP API to a
 * known provider host that did not come through the AI Client transport
 * are logged as direct calls, with tokens read from the response when it
 * has them, and are subject to the kill switches and caller approval.
 * Every other HTTP request is left alone. Calls made with cURL or Guzzle
 * directly cannot be seen.
 */
final class Direct implements Service {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The guard.
	 *
	 * @var Guard
	 */
	private Guard $guard;

	/**
	 * Kill switches.
	 *
	 * @var KillSwitch
	 */
	private KillSwitch $kill;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * The call log.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * Prices.
	 *
	 * @var Prices
	 */
	private Prices $prices;

	/**
	 * Requests in flight, keyed by URL and body hash.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $open = array();

	/**
	 * Sets up direct-call monitoring.
	 *
	 * @param Settings   $settings Settings.
	 * @param Guard      $guard    The guard.
	 * @param KillSwitch $kill     Kill switches.
	 * @param Policy     $policy   Policy.
	 * @param Calls      $calls    The call log.
	 * @param Prices     $prices   Prices.
	 */
	public function __construct( Settings $settings, Guard $guard, KillSwitch $kill, Policy $policy, Calls $calls, Prices $prices ) {
		$this->settings = $settings;
		$this->guard    = $guard;
		$this->kill     = $kill;
		$this->policy   = $policy;
		$this->calls    = $calls;
		$this->prices   = $prices;
	}

	/**
	 * Hooks into the HTTP API.
	 */
	public function register(): void {
		add_filter( 'pre_http_request', array( $this, 'pre' ), 1000, 3 );
		add_action( 'http_api_debug', array( $this, 'debug' ), 10, 5 );
	}

	/**
	 * Known provider hosts: host, or host and path prefix, mapped to a provider id.
	 *
	 * @return array<string, string>
	 */
	public static function hosts(): array {
		$hosts = array(
			'api.openai.com'                    => 'openai',
			'api.anthropic.com'                 => 'anthropic',
			'generativelanguage.googleapis.com' => 'google',
			'api.mistral.ai'                    => 'mistral',
			'api.groq.com'                      => 'groq',
			'api.deepseek.com'                  => 'deepseek',
			'api.x.ai'                          => 'xai',
			'openrouter.ai/api'                 => 'openrouter',
		);
		if ( function_exists( 'wp_get_connectors' ) ) {
			foreach ( array_keys( (array) wp_get_connectors() ) as $id ) {
				$id = sanitize_key( (string) $id );
				if ( '' !== $id && ! in_array( $id, $hosts, true ) ) {
					$hosts[ 'api.' . $id . '.com' ] = $id;
				}
			}
		}

		/**
		 * Filters the AI provider hosts direct-call monitoring watches.
		 *
		 * @param array $hosts Provider id keyed by host, or by host and path prefix (example.com/v1/ai).
		 */
		$filtered = apply_filters( 'axle_ai_guard_provider_hosts', $hosts );
		$clean    = array();
		foreach ( Hooks::arr( $filtered, $hosts ) as $host => $provider ) {
			$host = strtolower( trim( (string) $host, '/ ' ) );
			if ( '' !== $host ) {
				$clean[ $host ] = sanitize_key( (string) $provider );
			}
		}

		return $clean;
	}

	/**
	 * The provider a URL belongs to, or ''.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function provider_for( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$target = strtolower( $parts['host'] . ( $parts['path'] ?? '' ) );

		foreach ( self::hosts() as $host => $provider ) {
			$match = false === strpos( $host, '/' ) ? strtolower( (string) $parts['host'] ) === $host : 0 === strpos( $target, $host );
			if ( $match ) {
				return $provider;
			}
		}

		return '';
	}

	/**
	 * Filter pre_http_request: blocks a direct call while a kill switch or caller approval says so.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param mixed  $args Request arguments.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function pre( $pre, $args, $url ) {
		if ( false !== $pre || ! $this->settings->get( 'direct_monitoring' ) || $this->guard->is_sending() ) {
			return $pre;
		}

		$provider = self::provider_for( (string) $url );
		if ( '' === $provider ) {
			return $pre;
		}

		try {
			$call             = Context::call( 'direct', Caller::detect() );
			$call['provider'] = $provider;
			$body             = is_array( $args ) && isset( $args['body'] ) ? $args['body'] : '';
			$json             = is_string( $body ) ? json_decode( $body, true ) : $body;
			$call['model']    = is_array( $json ) && isset( $json['model'] ) && is_string( $json['model'] ) ? $json['model'] : '';

			$rule = $this->kill->rule_for( $call );
			if ( '' === $rule && in_array( $this->settings->caller_status( (string) $call['caller']['slug'] ), array( 'pending', 'denied' ), true ) ) {
				$rule = 'caller';
			}
			if ( '' !== $rule ) {
				$block = $this->policy->decide( $call, 'pre', $this->policy->block( $rule, $call ) );
				if ( $block ) {
					$this->guard->log_block( $call, $block );

					return new \WP_Error( 'axle_ai_guard_blocked', $block['message'], array( 'status' => 503 ) );
				}
			}

			$this->open[ self::key( (string) $url, $body ) ] = array(
				'call'    => $call,
				'started' => microtime( true ),
				'bytes'   => is_string( $body ) ? strlen( $body ) : strlen( (string) wp_json_encode( $body ) ),
			);
		} catch ( \Throwable $e ) {
			set_transient( Guard::DEGRADED, substr( $e->getMessage(), 0, 300 ), DAY_IN_SECONDS );
		}

		return $pre;
	}

	/**
	 * Action http_api_debug: logs a direct call that went out.
	 *
	 * @param mixed  $response Response or WP_Error.
	 * @param string $context  Always response.
	 * @param string $transport Transport class.
	 * @param mixed  $args     Request arguments.
	 * @param string $url      URL.
	 */
	public function debug( $response, $context, $transport, $args, $url ): void {
		unset( $transport );
		$body = is_array( $args ) && isset( $args['body'] ) ? $args['body'] : '';
		$key  = self::key( (string) $url, $body );
		if ( 'response' !== $context || ! isset( $this->open[ $key ] ) ) {
			return;
		}
		$open = $this->open[ $key ];
		unset( $this->open[ $key ] );

		try {
			$call   = $open['call'];
			$failed = is_wp_error( $response );
			$status = $failed ? 0 : (int) wp_remote_retrieve_response_code( $response );
			$text   = $failed ? '' : (string) wp_remote_retrieve_body( $response );
			$data   = json_decode( $text, true );
			$usage  = is_array( $data ) ? Sdk::usage_from_body( $data ) : null;

			if ( is_array( $data ) && '' === $call['model'] && isset( $data['model'] ) && is_string( $data['model'] ) ) {
				$call['model'] = $data['model'];
			}

			$estimated = null === $usage;
			$prompt    = $estimated ? (int) ceil( $open['bytes'] / 4 ) : $usage['prompt'];
			$output    = $estimated ? (int) ceil( strlen( $text ) / 4 ) : $usage['completion'];
			$ok        = ! $failed && $status >= 200 && $status < 300;
			$cost      = $ok ? $this->prices->cost( (string) $call['provider'], (string) $call['model'], $prompt, $output ) : 0;

			$row = array_merge(
				Calls::row_for( $call, $ok ? 'ok' : 'error' ),
				array(
					'duration_ms'       => (int) round( ( microtime( true ) - $open['started'] ) * 1000 ),
					'prompt_tokens'     => $ok ? $prompt : 0,
					'completion_tokens' => $ok ? $output : 0,
					'total_tokens'      => $ok ? $prompt + $output : 0,
					'estimated'         => $ok && $estimated ? 1 : 0,
					'cost_micros'       => $cost,
					'currency'          => $this->prices->currency(),
					'request_bytes'     => (int) $open['bytes'],
					'response_bytes'    => strlen( $text ),
					'http_status'       => $status,
					'error_code'        => $failed ? substr( (string) $response->get_error_code(), 0, 100 ) : ( $ok ? '' : 'http_' . $status ),
				)
			);
			$id  = $this->calls->insert( $row );
			$this->policy->record( $call, $ok ? $prompt + $output : 0, $cost );

			if ( $ok ) {
				/** This action is documented in src/Guard.php */
				do_action( 'axle_ai_guard_call_finished', $id, $row, $call );
			} else {
				/** This action is documented in src/Guard.php */
				do_action( 'axle_ai_guard_call_failed', $id, (string) $row['error_code'], $call );
			}
		} catch ( \Throwable $e ) {
			set_transient( Guard::DEGRADED, substr( $e->getMessage(), 0, 300 ), DAY_IN_SECONDS );
		}
	}

	/**
	 * A key for a request in flight.
	 *
	 * @param string $url  URL.
	 * @param mixed  $body Body.
	 * @return string
	 */
	private static function key( string $url, $body ): string {
		return md5( $url . '|' . ( is_string( $body ) ? $body : (string) wp_json_encode( $body ) ) );
	}
}
