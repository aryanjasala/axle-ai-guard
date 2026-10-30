<?php
/**
 * Tests for the transport decorator and the body rewriters.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Rewriters;
use AryanX\Axle\AiGuard\Transport;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;
use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Http\Contracts\HttpTransporterInterface;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\RequestOptions;
use WordPress\AiClient\Providers\Http\DTO\Response;

/**
 * Covers Transport and Rewriters.
 */
final class TransportTest extends GuardTestCase {

	/** @var array Requests the inner transport got. */
	private $sent = array();

	/** @var int Status the inner transport answers with. */
	private $status = 200;

	protected function setUp(): void {
		parent::setUp();
		Filters\expectApplied( 'axle_ai_guard_caller' )->zeroOrMoreTimes()->andReturn( self::caller() );
		$this->settings->remember_caller( self::caller(), 'approved' );
		AiClient::$registry = null;
	}

	/**
	 * The inner transport.
	 *
	 * @return HttpTransporterInterface
	 */
	private function inner(): HttpTransporterInterface {
		$test = $this;
		return new class( $test ) implements HttpTransporterInterface {
			/** @var TransportTest */
			private $test;
			public function __construct( $test ) {
				$this->test = $test;
			}
			public function send( Request $request, ?RequestOptions $options = null ): Response {
				return $this->test->receive( $request );
			}
		};
	}

	/**
	 * What the inner transport does.
	 *
	 * @param Request $request Request.
	 * @return Response
	 */
	public function receive( Request $request ): Response {
		$this->sent[] = $request;
		if ( 0 === $this->status ) {
			throw new \RuntimeException( 'Network down' );
		}
		return new Response( $this->status, array(), '{"ok":true}' );
	}

	public function test_it_installs_around_the_existing_transport_once(): void {
		$registry              = AiClient::defaultRegistry();
		$registry->transporter = $this->inner();

		$this->guard->install_transport();
		$this->assertInstanceOf( Transport::class, $registry->transporter );
		$wrapped = $registry->transporter;
		$this->guard->install_transport();
		$this->assertSame( $wrapped, $registry->transporter );
	}

	public function test_it_redacts_an_openai_body_and_records_the_request(): void {
		Actions\expectDone( 'axle_ai_guard_data_redacted' )->once()->with( 'user_email', 2, 1 );
		$this->guard->before( self::event( 'Hi jane@example.com' ) );

		$transport = new Transport( $this->inner(), $this->guard, $this->detectors );
		$transport->send(
			new Request(
				'https://api.openai.com/v1/chat/completions',
				array(
					'model'    => 'jane@example.com-model',
					'messages' => array(
						array(
							'role'    => 'system',
							'content' => 'Reply to jane@example.com',
						),
						array(
							'role'    => 'user',
							'content' => array(
								array(
									'type' => 'text',
									'text' => 'Hi jane@example.com',
								),
							),
						),
					),
				)
			)
		);

		$data = $this->sent[0]->getData();
		$this->assertSame( 'Reply to [redacted:user_email]', $data['messages'][0]['content'] );
		$this->assertSame( 'Hi [redacted:user_email]', $data['messages'][1]['content'][0]['text'] );
		$this->assertSame( 'jane@example.com-model', $data['model'], 'Only text fields change.' );
		$this->assertSame( array( 'user_email' => 2 ), $this->guard->current()['redacted'] );
		$this->assertSame( 200, $this->guard->current()['http']['http_status'] );
	}

	public function test_requests_outside_a_call_pass_untouched(): void {
		$transport = new Transport( $this->inner(), $this->guard, $this->detectors );
		$request   = new Request( 'https://api.openai.com/v1/models', '{"q":"jane@example.com"}' );
		$transport->send( $request );
		$this->assertSame( $request, $this->sent[0] );
		$this->assertFalse( $this->guard->is_sending() );
	}

	public function test_a_raw_json_body_is_redacted_too(): void {
		$this->guard->before( self::event( 'Hi jane@example.com' ) );
		$transport = new Transport( $this->inner(), $this->guard, $this->detectors );
		$transport->send( new Request( 'https://generativelanguage.googleapis.com/v1/x', '{"contents":[{"parts":[{"text":"Hi jane@example.com"}]}]}' ) );

		$this->assertSame( '{"contents":[{"parts":[{"text":"Hi [redacted:user_email]"}]}]}', $this->sent[0]->getBody() );
	}

	public function test_failures_close_the_call(): void {
		$this->guard->before( self::event( 'One' ) );
		$this->status = 0;
		$transport    = new Transport( $this->inner(), $this->guard, $this->detectors );
		try {
			$transport->send( new Request( 'https://api.openai.com/v1/x', array( 'messages' => array() ) ) );
			$this->fail( 'No exception.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'Network down', $e->getMessage() );
		}
		$this->assertSame( 'RuntimeException', $this->db->calls[1]['error_code'] );
		$this->assertNull( $this->guard->current() );
		$this->assertFalse( $this->guard->is_sending() );
	}

	public function test_the_rewriters_per_format(): void {
		$upper = 'strtoupper';
		$this->assertSame( array( 'system' => 'BE NICE', 'anthropic_version' => 'x', 'messages' => array( array( 'role' => 'user', 'content' => 'HI' ) ) ), Rewriters::apply( array( 'system' => 'be nice', 'anthropic_version' => 'x', 'messages' => array( array( 'role' => 'user', 'content' => 'hi' ) ) ), $upper ) );
		$this->assertSame( array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'HI' ) ) ) ) ), Rewriters::apply( array( 'contents' => array( array( 'role' => 'user', 'parts' => array( array( 'text' => 'hi' ) ) ) ) ), $upper ) );
		$this->assertSame( array( 'model' => 'm', 'input' => array( 'A', 'B' ) ), Rewriters::apply( array( 'model' => 'm', 'input' => array( 'a', 'b' ) ), $upper ) );
		$this->assertSame( array( 'prompt' => 'X', 'model' => 'm' ), Rewriters::apply( array( 'prompt' => 'x', 'model' => 'm' ), $upper ) );
	}

	public function test_the_rewriters_filter(): void {
		Filters\expectApplied( 'axle_ai_guard_provider_body_rewriters' )->once()->with( Mockery::type( 'array' ) )->andReturn(
			array(
				'custom' => static function ( array $body, callable $redact ): array {
					$body['query'] = $redact( $body['query'] );
					return $body;
				},
				'broken' => 'not callable',
			)
		);
		$this->assertSame( array( 'query' => 'SECRET' ), Rewriters::apply( array( 'query' => 'secret' ), 'strtoupper' ) );
	}
}
