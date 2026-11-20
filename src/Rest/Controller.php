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
use AryanX\Axle\AiGuard\Prices;
use AryanX\Axle\AiGuard\Reports;
use AryanX\Axle\AiGuard\Requests;
use AryanX\Axle\AiGuard\Runaway;
use AryanX\Axle\AiGuard\Service;
use AryanX\Axle\AiGuard\Settings;

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
	 * Runaway guard.
	 *
	 * @var Runaway
	 */
	private Runaway $runaway;

	/**
	 * Prices.
	 *
	 * @var Prices
	 */
	private Prices $prices;

	/**
	 * Requests for more AI.
	 *
	 * @var Requests
	 */
	private Requests $requests;

	/**
	 * Sets up the routes.
	 *
	 * @param Reports    $reports   Reports.
	 * @param KillSwitch $kill      Kill switches.
	 * @param Calls      $calls     The call log.
	 * @param Runaway    $runaway   Runaway guard.
	 * @param Prices     $prices    Prices.
	 * @param Requests   $requests  Requests for more AI.
	 */
	public function __construct( Reports $reports, KillSwitch $kill, Calls $calls, Runaway $runaway, Prices $prices, Requests $requests ) {
		$this->reports  = $reports;
		$this->kill     = $kill;
		$this->calls    = $calls;
		$this->runaway  = $runaway;
		$this->prices   = $prices;
		$this->requests = $requests;
	}

	/**
	 * Whether the current user is signed in, for asking for more AI. What they ask about comes
	 * from what AI Guard recorded about their own blocked call, so a person can only ask for themselves.
	 *
	 * @return bool
	 */
	public function signed_in(): bool {
		return is_user_logged_in();
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
					'text'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'plugin'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'context' => array(
						'type'    => 'string',
						'enum'    => array_merge( array( '' ), Settings::CONTEXTS ),
						'default' => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/runaway',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_runaway' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/access-requests',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'ask' ),
					'permission_callback' => array( $this, 'signed_in' ),
				),
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'list_requests' ),
					'permission_callback' => array( $this, 'can' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/access-requests/(?P<id>[a-f0-9]{12})/(?P<do>allow|undo|dismiss)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'can' ),
				'args'                => array(
					'calls' => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/allowances/(?P<user>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/prices',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_prices' ),
				'permission_callback' => array( $this, 'can' ),
			)
		);
	}

	/**
	 * GET /runaway: the mode, each caller's hour against its baseline, and open events.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_runaway() {
		return rest_ensure_response(
			array(
				'mode'    => $this->runaway->mode(),
				'callers' => $this->runaway->callers(),
				'events'  => $this->runaway->open_events(),
			)
		);
	}

	/**
	 * GET /prices: the price table in use, as the export file has it.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_prices() {
		return rest_ensure_response( json_decode( $this->prices->export_json(), true ) );
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
		return rest_ensure_response( $this->reports->test_rules( (string) $request->get_param( 'text' ), (string) $request->get_param( 'plugin' ), (string) $request->get_param( 'context' ) ) );
	}

	/**
	 * POST /access-requests: the signed-in person asks for more AI after a block.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function ask() {
		$result = $this->requests->ask( get_current_user_id() );
		if ( null === $result ) {
			return new \WP_Error( 'axle_ai_guard_nothing_blocked', __( 'Nothing was blocked for you in the last hour, so there is nothing to ask for.', 'axle-ai-guard' ), array( 'status' => 409 ) );
		}

		return rest_ensure_response(
			array(
				'message' => $result['created']
					? __( 'Request sent. An administrator can allow more AI for you today.', 'axle-ai-guard' )
					: __( 'Your request is already waiting for an administrator.', 'axle-ai-guard' ),
			)
		);
	}

	/**
	 * GET /access-requests: every request kept, newest last, with the person's name.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_requests() {
		return rest_ensure_response( self::present_requests( $this->requests->all() ) );
	}

	/**
	 * POST /access-requests/<id>/allow, /undo or /dismiss.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handle_request( $request ) {
		$id = (string) $request['id'];
		$by = get_current_user_id();
		$do = (string) $request['do'];
		if ( 'allow' === $do ) {
			$done = $this->requests->allow( $id, $by, (int) $request->get_param( 'calls' ) );
		} elseif ( 'undo' === $do ) {
			$done = $this->requests->undo( $id, $by );
		} else {
			$done = $this->requests->dismiss( $id, $by );
		}
		if ( null === $done ) {
			return new \WP_Error( 'axle_ai_guard_request_gone', __( 'That request is gone or was already dealt with.', 'axle-ai-guard' ), array( 'status' => 409 ) );
		}

		return rest_ensure_response( $this->requests->get( $id ) );
	}

	/**
	 * DELETE /allowances/<user>: ends a person's allowance for today.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function revoke( $request ) {
		$left = $this->requests->revoke_user( (int) $request['user'], get_current_user_id() );

		return rest_ensure_response(
			array(
				'user_id' => (int) $request['user'],
				'left'    => $left,
			)
		);
	}

	/**
	 * Requests with the person's name, for admins.
	 *
	 * @param array<int, array<string, mixed>> $requests Requests.
	 * @return array<int, array<string, mixed>>
	 */
	public static function present_requests( array $requests ): array {
		return array_map(
			static function ( array $request ): array {
				$user = get_userdata( (int) $request['user_id'] );

				return array(
					'id'      => (string) $request['id'],
					'user_id' => (int) $request['user_id'],
					'user'    => $user ? (string) $user->display_name : '',
					'rule'    => (string) $request['rule'],
					'caller'  => (string) $request['caller'],
					'at'      => gmdate( 'c', (int) $request['at'] ),
					'status'  => (string) $request['status'],
					'calls'   => (int) $request['calls'],
				);
			},
			$requests
		);
	}
}
