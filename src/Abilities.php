<?php
/**
 * Abilities: read AI usage and pull the kill switch.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * An agent can read usage, calls and limits, test the data rules and stop AI
 * spending with the kill switch. No ability turns a switch back off, raises a
 * budget or loosens a rule: only a person in wp-admin or on WP-CLI can.
 */
final class Abilities implements Service {

	public const CATEGORY = 'axle-ai-guard';

	/**
	 * Reports.
	 *
	 * @var Reports
	 */
	private Reports $reports;

	/**
	 * Kill switches.
	 *
	 * @var KillSwitch
	 */
	private KillSwitch $kill;

	/**
	 * The call log.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * Sets up the abilities.
	 *
	 * @param Reports    $reports Reports.
	 * @param KillSwitch $kill    Kill switches.
	 * @param Calls      $calls   The call log.
	 */
	public function __construct( Reports $reports, KillSwitch $kill, Calls $calls ) {
		$this->reports = $reports;
		$this->kill    = $kill;
		$this->calls   = $calls;
	}

	/**
	 * Hooks into the Abilities API.
	 */
	public function register(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	/**
	 * Registers the category.
	 */
	public function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Axle AI Guard', 'axle-ai-guard' ),
				'description' => __( 'Read AI usage, calls and limits, test data rules, and switch AI calls off.', 'axle-ai-guard' ),
			)
		);
	}

	/**
	 * Whether the current user may use the abilities.
	 *
	 * @return bool
	 */
	public function can(): bool {
		return current_user_can( Plugin::capability() );
	}

	/**
	 * Registers the abilities.
	 */
	public function register_abilities(): void {
		$readonly = array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
		$usage    = array(
			'type'       => 'object',
			'properties' => array(
				'name'    => array( 'type' => 'string' ),
				'calls'   => array( 'type' => 'integer' ),
				'tokens'  => array( 'type' => 'integer' ),
				'cost'    => array(
					'type'        => 'integer',
					'description' => 'Micro-units of the currency.',
				),
				'blocked' => array( 'type' => 'integer' ),
			),
		);

		$this->ability(
			'get-usage',
			__( 'Get AI usage', 'axle-ai-guard' ),
			__( 'AI calls, tokens, estimated cost and blocked calls for today or this month, in total and grouped by plugin, provider, model or user. Costs are micro-units of the site currency (1,000,000 = 1 unit).', 'axle-ai-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'period' => array(
						'type'    => 'string',
						'enum'    => array( 'day', 'month' ),
						'default' => 'month',
					),
					'by'     => array(
						'type'    => 'string',
						'enum'    => array( 'plugin', 'provider', 'model', 'user' ),
						'default' => 'plugin',
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'period'   => array( 'type' => 'string' ),
					'currency' => array( 'type' => 'string' ),
					'total'    => $usage,
					'groups'   => array(
						'type'  => 'array',
						'items' => $usage,
					),
				),
			),
			array( $this, 'get_usage' ),
			$readonly
		);

		$this->ability(
			'list-calls',
			__( 'List AI calls', 'axle-ai-guard' ),
			__( 'Logged AI calls, newest first, with caller, provider, model, tokens, cost, status and what data each call carried (counts, never the text). Previews are only included when asked for and stored.', 'axle-ai-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'status'          => array(
						'type' => 'string',
						'enum' => Calls::STATUSES,
					),
					'plugin'          => array( 'type' => 'string' ),
					'provider'        => array( 'type' => 'string' ),
					'model'           => array( 'type' => 'string' ),
					'context'         => array(
						'type' => 'string',
						'enum' => Settings::CONTEXTS,
					),
					'since'           => array(
						'type'        => 'string',
						'description' => 'UTC date or datetime, such as 2026-10-30.',
					),
					'limit'           => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
					'include_preview' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array(
				'type'  => 'array',
				'items' => array( 'type' => 'object' ),
			),
			array( $this, 'list_calls' ),
			$readonly
		);

		$this->ability(
			'get-limits',
			__( 'Get AI limits', 'axle-ai-guard' ),
			__( 'Budgets with what is used, rate limits, data rule modes and the kill switches that are on.', 'axle-ai-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
				'default'              => array(),
			),
			array( 'type' => 'object' ),
			array( $this, 'get_limits' ),
			$readonly
		);

		$this->ability(
			'engage-kill-switch',
			__( 'Switch AI calls off', 'axle-ai-guard' ),
			__( 'Turns a kill switch on: for the whole site, one plugin (caller key such as a plugin folder), one provider or one context (admin, front, cron, rest, ajax, cli). Calls in that scope are blocked until a person turns it off in wp-admin or with WP-CLI. There is no ability to turn it off.', 'axle-ai-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'scope'   => array(
						'type'    => 'string',
						'enum'    => KillSwitch::SCOPES,
						'default' => 'global',
					),
					'subject' => array(
						'type'        => 'string',
						'description' => 'Caller key, provider id or context. Not used for global.',
					),
					'reason'  => array(
						'type'      => 'string',
						'maxLength' => 500,
					),
				),
				'required'             => array( 'reason' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'scope'   => array( 'type' => 'string' ),
					'subject' => array( 'type' => 'string' ),
					'changed' => array( 'type' => 'boolean' ),
					'on'      => array( 'type' => 'boolean' ),
				),
			),
			array( $this, 'engage_kill_switch' ),
			array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => true,
			)
		);

		$this->ability(
			'test-data-rules',
			__( 'Test the data rules', 'axle-ai-guard' ),
			__( 'Runs the data rules on a text with no AI call: what each detector found, the text with redact-mode findings replaced, and whether a call with this text would be blocked.', 'axle-ai-guard' ),
			array(
				'type'                 => 'object',
				'properties'           => array(
					'text'   => array(
						'type'      => 'string',
						'maxLength' => 200000,
					),
					'plugin' => array(
						'type'        => 'string',
						'description' => 'Caller key, for per-plugin modes.',
					),
				),
				'required'             => array( 'text' ),
				'additionalProperties' => false,
			),
			array(
				'type'       => 'object',
				'properties' => array(
					'findings' => array( 'type' => 'array' ),
					'redacted' => array( 'type' => 'string' ),
					'blocked'  => array( 'type' => 'boolean' ),
				),
			),
			array( $this, 'test_data_rules' ),
			$readonly
		);
	}

	/**
	 * Ability: usage.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public function get_usage( $input = null ): array {
		$input  = (array) $input;
		$period = 'day' === ( $input['period'] ?? '' ) ? 'day' : 'month';

		return array(
			'period'   => $period,
			'currency' => $this->reports->limits()['currency'],
			'total'    => array( 'name' => 'global' ) + $this->reports->totals( $period ),
			'groups'   => $this->reports->usage( $period, (string) ( $input['by'] ?? 'plugin' ) ),
		);
	}

	/**
	 * Ability: calls.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public function list_calls( $input = null ): array {
		$input = (array) $input;
		$since = (string) ( $input['since'] ?? '' );
		$time  = '' !== $since ? strtotime( $since . ( strlen( $since ) <= 10 ? ' 00:00:00' : '' ) . ' UTC' ) : false;
		$rows  = $this->calls->query(
			array(
				'status'   => (string) ( $input['status'] ?? '' ),
				'caller'   => (string) ( $input['plugin'] ?? '' ),
				'provider' => (string) ( $input['provider'] ?? '' ),
				'model'    => (string) ( $input['model'] ?? '' ),
				'context'  => (string) ( $input['context'] ?? '' ),
				'since'    => $time ? gmdate( 'Y-m-d H:i:s', $time ) : '',
				'per_page' => (int) ( $input['limit'] ?? 20 ),
			)
		)['rows'];
		$with  = ! empty( $input['include_preview'] );

		return array_map(
			static function ( array $row ) use ( $with ): array {
				return Reports::present( $row, $with );
			},
			$rows
		);
	}

	/**
	 * Ability: limits.
	 *
	 * @return array
	 */
	public function get_limits(): array {
		return $this->reports->limits();
	}

	/**
	 * Ability: turns a kill switch on.
	 *
	 * @param array|null $input Input.
	 * @return array|\WP_Error
	 */
	public function engage_kill_switch( $input = null ) {
		$input   = (array) $input;
		$scope   = (string) ( $input['scope'] ?? 'global' );
		$subject = 'global' === $scope ? '' : (string) ( $input['subject'] ?? '' );
		if ( 'global' !== $scope && '' === $subject ) {
			return new \WP_Error( 'axle_ai_guard_subject_required', __( 'Say which plugin, provider or context to switch off.', 'axle-ai-guard' ), array( 'status' => 400 ) );
		}

		$changed = $this->kill->engage( $scope, $subject, (string) ( $input['reason'] ?? '' ), get_current_user_id() );

		return array(
			'scope'   => $scope,
			'subject' => $subject,
			'changed' => $changed,
			'on'      => $this->kill->is_on( $scope, strtolower( $subject ) ),
		);
	}

	/**
	 * Ability: test the data rules.
	 *
	 * @param array|null $input Input.
	 * @return array
	 */
	public function test_data_rules( $input = null ): array {
		$input = (array) $input;

		return $this->reports->test_rules( (string) ( $input['text'] ?? '' ), (string) ( $input['plugin'] ?? '' ) );
	}

	/**
	 * Registers one ability in the category.
	 *
	 * @param string   $name        Name without the namespace.
	 * @param string   $label       Label.
	 * @param string   $description What it does.
	 * @param array    $input       Input schema.
	 * @param array    $output      Output schema.
	 * @param callable $execute     Execute callback.
	 * @param array    $annotations readonly, destructive, idempotent.
	 */
	private function ability( string $name, string $label, string $description, array $input, array $output, callable $execute, array $annotations ): void {
		wp_register_ability(
			self::CATEGORY . '/' . $name,
			array(
				'label'               => $label,
				'description'         => $description,
				'category'            => self::CATEGORY,
				'input_schema'        => $input,
				'output_schema'       => $output,
				'execute_callback'    => $execute,
				'permission_callback' => array( $this, 'can' ),
				'meta'                => array(
					'annotations'  => $annotations,
					'show_in_rest' => true,
					'public'       => true,
					'mcp'          => array( 'public' => true ),
				),
			)
		);
	}
}
