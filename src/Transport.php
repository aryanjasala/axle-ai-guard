<?php
/**
 * The HTTP transport decorator the AI Client sends through.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the transport the SDK had (core's, or another decorator such as the
 * AI plugin's logger), so they chain. For a call the guard has open, it
 * redacts the request body and records sizes, status and failures. Requests
 * outside a call, such as model listings, pass through untouched.
 */
final class Transport implements HttpTransporterInterface {

	/**
	 * The wrapped transport.
	 *
	 * @var HttpTransporterInterface
	 */
	private HttpTransporterInterface $inner;

	/**
	 * The guard.
	 *
	 * @var Guard
	 */
	private Guard $guard;

	/**
	 * Data rules.
	 *
	 * @var Detectors
	 */
	private Detectors $detectors;

	/**
	 * Sets up the decorator.
	 *
	 * @param HttpTransporterInterface $inner     The wrapped transport.
	 * @param Guard                    $guard     The guard.
	 * @param Detectors                $detectors Data rules.
	 */
	public function __construct( HttpTransporterInterface $inner, Guard $guard, Detectors $detectors ) {
		$this->inner     = $inner;
		$this->guard     = $guard;
		$this->detectors = $detectors;
	}

	/**
	 * Sends a request.
	 *
	 * @param Request             $request The request.
	 * @param RequestOptions|null $options Options.
	 * @return Response
	 * @throws \Throwable Whatever the wrapped transport throws.
	 */
	public function send( Request $request, ?RequestOptions $options = null ): Response {
		$frame = $this->guard->current();
		if ( $frame && $frame['redact'] ) {
			$request = $this->redact( $request, (array) $frame['redact'] );
		}

		$bytes = 0;
		try {
			$bytes = strlen( (string) $request->getBody() );
		} catch ( \Throwable $e ) {
			$bytes = 0;
		}

		$this->guard->set_sending( true );
		try {
			$response = $this->inner->send( $request, $options );
		} catch ( \Throwable $e ) {
			$this->guard->set_sending( false );
			if ( $frame ) {
				$this->guard->note_http( $bytes, (int) $e->getCode() >= 400 ? (int) $e->getCode() : 0, 0, self::short_class( $e ) );
			}
			throw $e;
		}
		$this->guard->set_sending( false );

		if ( $frame ) {
			$this->guard->note_http( $bytes, $response->getStatusCode(), strlen( (string) $response->getBody() ) );
		}

		return $response;
	}

	/**
	 * Redacts the body of a request.
	 *
	 * @param Request  $request The request.
	 * @param string[] $ids     Detectors to redact.
	 * @return Request
	 */
	private function redact( Request $request, array $ids ): Request {
		$data   = $request->getData();
		$string = false;
		if ( null === $data ) {
			$decoded = json_decode( (string) $request->getBody(), true );
			if ( ! is_array( $decoded ) ) {
				return $request;
			}
			$data   = $decoded;
			$string = true;
		}

		$counts    = array();
		$detectors = $this->detectors;
		$rewritten = Rewriters::apply(
			$data,
			static function ( string $text ) use ( $detectors, $ids, &$counts ): string {
				list( $text, $found ) = $detectors->redact( $text, $ids );
				foreach ( $found as $id => $count ) {
					$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + $count;
				}

				return $text;
			}
		);

		if ( ! $counts ) {
			return $request;
		}

		$frame = $this->guard->current();
		foreach ( $counts as $id => $count ) {
			/**
			 * Fires when personal data was redacted from a request to an AI provider.
			 *
			 * @param string $detector Detector id.
			 * @param int    $count    How many values were replaced.
			 * @param int    $id       Row id of the call.
			 */
			do_action( 'axle_ai_guard_data_redacted', $id, $count, (int) ( $frame['id'] ?? 0 ) );
		}
		$this->guard->note_redacted( $counts );

		return $request->withData( $string ? (string) wp_json_encode( $rewritten ) : $rewritten );
	}

	/**
	 * The short class name of an exception, as an error code.
	 *
	 * @param \Throwable $e Exception.
	 * @return string
	 */
	private static function short_class( \Throwable $e ): string {
		$class = get_class( $e );
		$pos   = strrpos( $class, '\\' );

		return false === $pos ? $class : substr( $class, $pos + 1 );
	}
}
