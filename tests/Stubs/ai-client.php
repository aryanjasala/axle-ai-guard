<?php
/**
 * Minimal stand-ins for the AI Client SDK classes the plugin types against:
 * the transport interface and its request and response objects, and the
 * registry. Used by PHPStan (the WordPress stubs stop at 6.9) and the unit
 * tests. They follow the signatures of the SDK bundled with WordPress 7.0.
 *
 * @package AryanX\Axle\AiGuard
 */

// phpcs:ignoreFile

namespace WordPress\AiClient\Providers\Http\DTO {

	if ( ! class_exists( RequestOptions::class ) ) {
		class RequestOptions {}
	}

	if ( ! class_exists( Request::class ) ) {
		class Request {
			/** @var string */
			private $uri;
			/** @var array|null */
			private $data;
			/** @var string|null */
			private $body;

			/**
			 * @param string            $uri  URI.
			 * @param array|string|null $data Data or raw body.
			 */
			public function __construct( string $uri = '', $data = null ) {
				$this->uri  = $uri;
				$this->data = is_array( $data ) ? $data : null;
				$this->body = is_string( $data ) ? $data : null;
			}

			public function getUri(): string {
				return $this->uri;
			}

			/** @return array|null */
			public function getData(): ?array {
				return $this->data;
			}

			public function getBody(): ?string {
				return null !== $this->body ? $this->body : ( null === $this->data ? null : (string) json_encode( $this->data ) );
			}

			/**
			 * @param array|string|null $data Data.
			 * @return self
			 */
			public function withData( $data ): self {
				return new self( $this->uri, $data );
			}
		}
	}

	if ( ! class_exists( Response::class ) ) {
		class Response {
			/** @var int */
			private $status;
			/** @var string|null */
			private $body;

			public function __construct( int $status = 200, array $headers = array(), ?string $body = null ) {
				$this->status = $status;
				$this->body   = $body;
			}

			public function getStatusCode(): int {
				return $this->status;
			}

			public function getBody(): ?string {
				return $this->body;
			}
		}
	}
}

namespace WordPress\AiClient\Providers\Http\Contracts {

	use WordPress\AiClient\Providers\Http\DTO\Request;
	use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
	use WordPress\AiClient\Providers\Http\DTO\Response;

	if ( ! interface_exists( HttpTransporterInterface::class ) ) {
		interface HttpTransporterInterface {
			public function send( Request $request, ?RequestOptions $options = null ): Response;
		}
	}
}

namespace WordPress\AiClient\Providers\Http {

	use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;

	if ( ! class_exists( HttpTransporterFactory::class ) ) {
		class HttpTransporterFactory {
			public static function createTransporter(): HttpTransporterInterface {
				throw new \RuntimeException( 'Not available in tests.' );
			}
		}
	}
}

namespace WordPress\AiClient\Providers {

	use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;

	if ( ! class_exists( ProviderRegistry::class ) ) {
		class ProviderRegistry {
			/** @var HttpTransporterInterface|null */
			public $transporter;

			public function getHttpTransporter(): HttpTransporterInterface {
				if ( null === $this->transporter ) {
					throw new \RuntimeException( 'HttpTransporterInterface instance not set.' );
				}
				return $this->transporter;
			}

			public function setHttpTransporter( HttpTransporterInterface $transporter ): void {
				$this->transporter = $transporter;
			}
		}
	}
}

namespace WordPress\AiClient {

	use WordPress\AiClient\Providers\ProviderRegistry;

	if ( ! class_exists( AiClient::class ) ) {
		class AiClient {
			/** @var ProviderRegistry|null */
			public static $registry;

			public static function defaultRegistry(): ProviderRegistry {
				if ( null === self::$registry ) {
					self::$registry = new ProviderRegistry();
				}
				return self::$registry;
			}
		}
	}
}
