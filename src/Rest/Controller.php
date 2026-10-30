<?php
/**
 * REST routes for the screens: calls, call detail, kill switches and the rule test.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Rest;

use AryanX\Axle\AiGuard\Calls;
use AryanX\Axle\AiGuard\KillSwitch;
use AryanX\Axle\AiGuard\Plugin;
use AryanX\Axle\AiGuard\Reports;
use AryanX\Axle\AiGuard\Service;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under axle-ai-guard/v1, all behind the AI Guard capability.
 */
final class Controller implements Service {

	public const NAMESPACE = 'axle-ai-guard/v1';

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
	 * Sets up the routes.
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
	 * Hooks the routes.
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}

	/**
	 * Whether the current user may use the routes.
	 *
	 * @return bool
	 */
	public function can(): bool {
		return current_user_can( Plugin::capability() );
	}

	/**
	 * Registers the routes.
	 */
	public function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/calls',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_calls' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'status'   => array(
						'type' => 'string',
						'enum' => array_merge( array( '' ), Calls::STATUSES ),
					),
					'plugin'   => array( 'type' => 'string' ),
					'provider' => array( 'type' => 'string' ),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/calls/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_call' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/kill',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'kill' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'scope'   => array(
						'type'     => 'string',
						'enum'     => KillSwitch::SCOPES,
						'required' => true,
					),
					'subject' => array(
						'type'    => 'string',
						'default' => '',
					),
					'on'      => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'reason'  => array(
						'type'    => 'string',
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/rules/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_rules' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'text' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * GET /calls.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function list_calls( $request ) {
		$result = $this->calls->query(
			array(
				'status'   => (string) $request->get_param( 'status' ),
				'caller'   => (string) $request->get_param( 'plugin' ),
				'provider' => (string) $request->get_param( 'provider' ),
				'page'     => (int) $request->get_param( 'page' ),
				'per_page' => 50,
			)
		);

		$response = rest_ensure_response(
			array_map(
				static function ( array $row ): array {
					return Reports::present( $row );
				},
				$result['rows']
			)
		);
		$response->header( 'X-WP-Total', (string) $result['total'] );

		return $response;
	}

	/**
	 * GET /calls/<id>, with the preview and stored prompt.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_call( $request ) {
		$row = $this->calls->get( (int) $request['id'] );
		if ( ! $row ) {
			return new \WP_Error( 'axle_ai_guard_not_found', __( 'No call with that ID.', 'axle-ai-guard' ), array( 'status' => 404 ) );
		}

		$data           = Reports::present( $row, true );
		$data['prompt'] = $this->calls->prompt( (int) $row['id'] );

		return rest_ensure_response( $data );
	}

	/**
	 * POST /kill: turns a switch on or off.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function kill( $request ) {
		$scope   = (string) $request->get_param( 'scope' );
		$subject = (string) $request->get_param( 'subject' );
		if ( 'global' !== $scope && '' === $subject ) {
			return new \WP_Error( 'axle_ai_guard_subject_required', __( 'Say which plugin, provider or context.', 'axle-ai-guard' ), array( 'status' => 400 ) );
		}

		$on      = (bool) $request->get_param( 'on' );
		$changed = $on
			? $this->kill->engage( $scope, $subject, (string) $request->get_param( 'reason' ), get_current_user_id() )
			: $this->kill->release( $scope, $subject, get_current_user_id() );

		return rest_ensure_response(
			array(
				'scope'   => $scope,
				'subject' => $subject,
				'on'      => $this->kill->is_on( $scope, strtolower( $subject ) ),
				'changed' => $changed,
			)
		);
	}

	/**
	 * POST /rules/test.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function test_rules( $request ) {
		return rest_ensure_response( $this->reports->test_rules( (string) $request->get_param( 'text' ) ) );
	}
}
