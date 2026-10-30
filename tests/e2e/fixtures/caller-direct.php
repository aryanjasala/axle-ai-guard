<?php
/**
 * Plugin Name: AAG fixture direct caller
 * Description: Calls the fixture provider with wp_remote_post(), skipping the AI Client, plus one ordinary request. Test sites only.
 *
 * @package AryanX\Axle\AiGuard
 */

// phpcs:ignoreFile

add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'caller-direct/v1',
			'/run',
			array(
				'methods'             => 'GET,POST',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$ai    = wp_remote_post(
						'http://localhost/wp-json/aag-fixture/v1/chat/completions',
						array(
							'headers' => array( 'Content-Type' => 'application/json' ),
							'body'    => wp_json_encode(
								array(
									'model'    => 'fixture-direct',
									'messages' => array(
										array(
											'role'    => 'user',
											'content' => 'Hello from a direct call',
										),
									),
								)
							),
						)
					);
					$other = wp_remote_get( 'http://localhost/wp-json/' );

					return array(
						'ai'    => is_wp_error( $ai ) ? $ai->get_error_code() : wp_remote_retrieve_response_code( $ai ),
						'other' => is_wp_error( $other ) ? $other->get_error_code() : wp_remote_retrieve_response_code( $other ),
					);
				},
			)
		);
	}
);
