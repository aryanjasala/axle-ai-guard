<?php
/**
 * Tells a person why their AI call was blocked, and lets them ask for more.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

use AryanX\Axle\AiGuard\Admin\AxleUi;
use AryanX\Axle\AiGuard\Admin\Page;

defined( 'ABSPATH' ) || exit;

/**
 * A blocked call returns a WP_Error to the plugin that made it, and that
 * plugin decides what its user sees, which is often nothing. When a signed-in
 * person's call is blocked, this service remembers it for the rest of the
 * request. If the request then ends in a REST error, it adds the shared wall
 * data (AxleUi::wall()) to the error, and the shared script shows it in the
 * editor and in wp-admin as a notice with one next step.
 *
 * What the person is told is deliberately small: the reason in plain words.
 * No budget amounts, no limits, no usage of anyone else and no names. A person
 * who can manage AI Guard gets a link to its screen instead of a request button.
 */
final class Wall implements Service {

	/**
	 * Where a person asks for more.
	 */
	public const ROUTE = Rest\Controller::NAMESPACE . '/access-requests';

	/**
	 * The wall of this request's blocked call, when there is one.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $pending = null;

	/**
	 * Hooks.
	 */
	public function register(): void {
		add_action( 'axle_ai_guard_call_blocked', array( $this, 'on_blocked' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'on_error' ), 99 );
		add_filter( 'rest_post_dispatch', array( $this, 'on_response' ), 99 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue' ) );
	}

	/**
	 * Loads the shared script that turns a wall into a notice.
	 */
	public function enqueue(): void {
		/**
		 * Filters whether the wall script loads in the editor and wp-admin for this person.
		 *
		 * @param bool $load Default true for signed-in people.
		 */
		if ( is_user_logged_in() && apply_filters( 'axle_ai_guard_wall_enabled', true ) ) {
			AxleUi::enqueue_wall();
		}
	}

	/**
	 * Action axle_ai_guard_call_blocked: keeps the wall of a signed-in person's blocked call.
	 *
	 * @param string $rule   Rule.
	 * @param array  $caller Caller.
	 * @param array  $row    The logged row.
	 */
	public function on_blocked( $rule, $caller, $row ): void {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 || (int) ( $row['user_id'] ?? 0 ) !== $user_id ) {
			return;
		}

		$wall = $this->build( (string) $rule, (array) $caller, (array) $row );
		if ( ! $wall ) {
			return;
		}

		$this->pending = $wall;

		$plugin = Plugin::instance();
		if ( $plugin && '' !== (string) $wall['request_route'] ) {
			$plugin->requests()->remember_block( $user_id, (string) $rule, (string) ( $caller['slug'] ?? '' ) );
		}
	}

	/**
	 * The wall for a blocked call.
	 *
	 * @param string $rule   Rule, such as budget or data:email.
	 * @param array  $caller Caller.
	 * @param array  $row    The logged row.
	 * @return array<string, mixed>|null
	 */
	public function build( string $rule, array $caller, array $row ): ?array {
		$manager = current_user_can( Plugin::capability() );
		$wall    = array(
			'plugin'     => 'axle-ai-guard',
			'capability' => Plugin::capability(),
			'message'    => self::message( $rule ),
		);

		if ( $manager ) {
			$wall['action_label'] = __( 'Open AI Guard', 'axle-ai-guard' );
			$wall['action_url']   = Page::url( 'budget' === (string) strtok( $rule, ':' ) ? 'budgets' : 'overview' );
		} elseif ( Allowance::passes( $rule ) ) {
			$wall['action_label']  = __( 'Ask for more', 'axle-ai-guard' );
			$wall['request_route'] = self::ROUTE;
		}

		/**
		 * Filters the wall shown to a signed-in person whose AI call was blocked. Return an empty
		 * value to show nothing. Keep it free of budget amounts and other people's usage.
		 *
		 * @param array  $wall   plugin, capability, message, action_label, action_url, request_route and request_data.
		 * @param string $rule   Rule, such as budget, rate or data:email.
		 * @param array  $caller type, slug, file and line.
		 * @param array  $row    The logged row.
		 */
		$filtered = apply_filters( 'axle_ai_guard_wall', $wall, $rule, $caller, $row );
		$filtered = Hooks::arr( $filtered, array() );
		if ( ! $filtered || '' === (string) ( $filtered['message'] ?? '' ) ) {
			return null;
		}

		return AxleUi::wall( $filtered );
	}

	/**
	 * What a person is told, in plain words, for a rule.
	 *
	 * @param string $rule Rule such as budget or data:email.
	 * @return string
	 */
	public static function message( string $rule ): string {
		$base = (string) strtok( $rule, ':' );
		$map  = array(
			'kill'     => __( 'AI is switched off on this site right now, so this did not run.', 'axle-ai-guard' ),
			'caller'   => __( 'The plugin you used is not approved to use AI on this site yet, so this did not run.', 'axle-ai-guard' ),
			'context'  => __( 'AI is not allowed in this part of the site, so this did not run.', 'axle-ai-guard' ),
			'role'     => __( 'Your role may not use AI on this site, so this did not run.', 'axle-ai-guard' ),
			'budget'   => __( 'The AI allowance for this site is used up for now, so this did not run.', 'axle-ai-guard' ),
			'rate'     => __( 'You are using AI faster than this site allows. Wait a minute and try again.', 'axle-ai-guard' ),
			'provider' => __( 'The AI service this feature uses is not allowed on this site, so this did not run.', 'axle-ai-guard' ),
			'model'    => __( 'The AI model this feature uses is not allowed on this site, so this did not run.', 'axle-ai-guard' ),
			'files'    => __( 'Files may not be sent to an AI service from here, so this did not run.', 'axle-ai-guard' ),
			'data'     => __( 'The text has data that must not be sent to an AI service, so nothing was sent.', 'axle-ai-guard' ),
			'scan'     => __( 'The text is too long to check for private data, so nothing was sent.', 'axle-ai-guard' ),
		);
		$text = $map[ $base ] ?? __( 'AI Guard stopped this AI request, so it did not run.', 'axle-ai-guard' );

		/**
		 * Filters the plain-words reason a person is told when their AI call is blocked. It reaches
		 * people who cannot manage AI Guard: no budget amounts, no limits, nobody else's usage.
		 *
		 * @param string $text Message.
		 * @param string $rule Rule, such as budget, rate or data:email.
		 */
		return Hooks::str( apply_filters( 'axle_ai_guard_wall_message', $text, $rule ), $text );
	}

	/**
	 * Filter rest_request_after_callbacks: adds the wall to a REST error.
	 *
	 * @param mixed $response Response or error.
	 * @return mixed
	 */
	public function on_error( $response ) {
		if ( null === $this->pending || ! is_wp_error( $response ) ) {
			return $response;
		}

		$code = $response->get_error_code();
		$data = $response->get_error_data( $code );
		$data = is_array( $data ) ? $data : ( is_numeric( $data ) ? array( 'status' => (int) $data ) : array() );
		if ( ! isset( $data['axle_wall'] ) ) {
			$data['axle_wall'] = $this->pending;
			$response->add_data( $data, $code );
		}

		return $response;
	}

	/**
	 * Filter rest_post_dispatch: adds the wall to an error response a plugin built itself.
	 *
	 * @param mixed $result Response.
	 * @return mixed
	 */
	public function on_response( $result ) {
		if ( null === $this->pending || ! $result instanceof \WP_REST_Response || $result->get_status() < 400 ) {
			return $result;
		}

		$body = $result->get_data();
		if ( is_array( $body ) && isset( $body['message'] ) && ! isset( $body['data']['axle_wall'] ) ) {
			$body['data']              = isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();
			$body['data']['axle_wall'] = $this->pending;
			$result->set_data( $body );
		}

		return $result;
	}
}
