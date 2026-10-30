<?php
/**
 * Plugin Name: Axle AI Guard e2e fixture
 * Description: A fake AI provider that answers from a REST route on this site, an email catcher, and a direct-call host. Test sites only.
 *
 * @package AryanX\Axle\AiGuard
 */

// phpcs:ignoreFile

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\DTO\ApiKeyRequestAuthentication;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

const AAG_FIXTURE_BASE = 'http://localhost/wp-json/aag-fixture/v1';

if ( class_exists( AbstractApiProvider::class ) ) {

	class AAG_Fixture_Model extends AbstractOpenAiCompatibleTextGenerationModel {
		protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
			return new Request( $method, AAG_FIXTURE_BASE . '/' . ltrim( $path, '/' ), $headers, $data );
		}
	}

	class AAG_Fixture_Directory implements ModelMetadataDirectoryInterface {
		public function listModelMetadata(): array {
			$options = array();
			foreach ( OptionEnum::cases() as $option ) {
				$options[] = new SupportedOption( $option );
			}
			return array( new ModelMetadata( 'fixture-model', 'Fixture model', array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() ), $options ) );
		}

		public function hasModelMetadata( string $modelId ): bool {
			return 'fixture-model' === $modelId;
		}

		public function getModelMetadata( string $modelId ): ModelMetadata {
			return $this->listModelMetadata()[0];
		}
	}

	class AAG_Fixture_Availability implements ProviderAvailabilityInterface {
		public function isConfigured(): bool {
			return true;
		}
	}

	class AAG_Fixture_Provider extends AbstractApiProvider {
		protected static function baseUrl(): string {
			return AAG_FIXTURE_BASE;
		}

		protected static function createModel( ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata ): ModelInterface {
			return new AAG_Fixture_Model( $modelMetadata, $providerMetadata );
		}

		protected static function createProviderMetadata(): ProviderMetadata {
			return new ProviderMetadata( 'aag-fixture', 'AAG Fixture', ProviderTypeEnum::cloud(), null, RequestAuthenticationMethod::apiKey() );
		}

		protected static function createProviderAvailability(): ProviderAvailabilityInterface {
			return new AAG_Fixture_Availability();
		}

		protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
			return new AAG_Fixture_Directory();
		}
	}

	add_action(
		'init',
		static function () {
			$registry = AiClient::defaultRegistry();
			if ( ! $registry->hasProvider( 'aag-fixture' ) ) {
				$registry->registerProvider( AAG_Fixture_Provider::class );
				$registry->setProviderRequestAuthentication( 'aag-fixture', new ApiKeyRequestAuthentication( 'fixture-key-not-secret' ) );
			}
		},
		5
	);
}

// The fake provider endpoint: records what it got, answers like OpenAI.
add_action(
	'rest_api_init',
	static function () {
		register_rest_route(
			'aag-fixture/v1',
			'/chat/completions',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => static function ( WP_REST_Request $request ) {
					$bodies   = get_option( 'aag_fixture_bodies', array() );
					$bodies[] = $request->get_body();
					update_option( 'aag_fixture_bodies', $bodies, false );

					if ( get_option( 'aag_fixture_fail' ) ) {
						return new WP_REST_Response( array( 'error' => array( 'message' => 'Fixture provider failure' ) ), 500 );
					}

					$json = json_decode( $request->get_body(), true );
					return new WP_REST_Response(
						array(
							'id'      => 'fixture-' . count( $bodies ),
							'model'   => is_array( $json ) && isset( $json['model'] ) ? $json['model'] : 'fixture-model',
							'choices' => array(
								array(
									'index'         => 0,
									'message'       => array(
										'role'    => 'assistant',
										'content' => 'Fixture answer.',
									),
									'finish_reason' => 'stop',
								),
							),
							'usage'   => array(
								'prompt_tokens'     => 120,
								'completion_tokens' => 30,
								'total_tokens'      => 150,
							),
						),
						200
					);
				},
			)
		);
	}
);

// Direct-call monitoring watches the fixture endpoint like a provider host.
add_filter(
	'axle_ai_guard_provider_hosts',
	static function ( $hosts ) {
		$hosts['localhost/wp-json/aag-fixture'] = 'aag-fixture';
		return $hosts;
	}
);

// Application passwords over plain HTTP, for the abilities tests.
add_filter( 'wp_is_application_passwords_available', '__return_true' );

// Mail goes to an option instead of out.
add_filter(
	'pre_wp_mail',
	static function ( $short, $atts ) {
		$mail   = get_option( 'aag_fixture_mail', array() );
		$mail[] = array(
			'to'      => (array) $atts['to'],
			'subject' => $atts['subject'],
		);
		update_option( 'aag_fixture_mail', $mail, false );
		return true;
	},
	10,
	2
);

// Records the actions the e2e suite checks.
foreach ( array( 'axle_ai_guard_budget_soft_reached', 'axle_ai_guard_budget_hard_reached', 'axle_ai_guard_data_redacted' ) as $aag_fixture_action ) {
	add_action(
		$aag_fixture_action,
		static function ( ...$args ) use ( $aag_fixture_action ) {
			$log   = get_option( 'aag_fixture_actions', array() );
			$log[] = array( $aag_fixture_action, $args[0] ?? '', $args[1] ?? '' );
			update_option( 'aag_fixture_actions', $log, false );
		},
		10,
		4
	);
}
