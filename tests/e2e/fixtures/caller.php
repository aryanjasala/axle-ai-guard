<?php
/**
 * Plugin Name: AAG fixture caller
 * Description: Makes AI calls through the WordPress AI Client from a REST route. Copied as caller-a and caller-b. Test sites only.
 *
 * @package AryanX\Axle\AiGuard
 */

// phpcs:ignoreFile

$aag_caller_namespace = basename( __DIR__ ) . '/v1';

add_action(
	'rest_api_init',
	static function () use ( $aag_caller_namespace ) {
		register_rest_route(
			$aag_caller_namespace,
			'/run',
			array(
				'methods'             => 'GET,POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$builder = wp_ai_client_prompt( (string) $request->get_param( 'prompt' ) )->using_provider( 'aag-fixture' );
					if ( $request->get_param( 'system' ) ) {
						$builder = $builder->using_system_instruction( (string) $request->get_param( 'system' ) );
					}
					$text = $builder->generate_text();
					if ( is_wp_error( $text ) ) {
						$data = $text->get_error_data();
						return array(
							'ok'      => false,
							'code'    => $text->get_error_code(),
							'message' => $text->get_error_message(),
							'status'  => is_array( $data ) && isset( $data['status'] ) ? $data['status'] : 0,
						);
					}
					return array(
						'ok'   => true,
						'text' => $text,
					);
				},
			)
		);

		register_rest_route(
			$aag_caller_namespace,
			'/supported',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					return array( 'supported' => (bool) wp_ai_client_prompt( 'Hello' )->using_provider( 'aag-fixture' )->is_supported_for_text_generation() );
				},
			)
		);
	}
);
