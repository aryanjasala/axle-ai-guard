<?php
/**
 * Tests for direct-call monitoring.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Direct;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Direct.
 */
final class DirectTest extends GuardTestCase {

	/** @var Direct */
	private $direct;

	protected function setUp(): void {
		parent::setUp();
		Filters\expectApplied( 'axle_ai_guard_caller' )->zeroOrMoreTimes()->andReturn( self::caller( 'seo-tool' ) );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \WP_Error;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
		$this->direct = new Direct( $this->settings, $this->guard, $this->kill, $this->policy, $this->calls, $this->prices );
		$this->settings->save( array( 'direct_monitoring' => true ) );
	}

	public function test_hosts_and_their_filter(): void {
		$this->assertSame( 'openai', Direct::provider_for( 'https://api.openai.com/v1/chat/completions' ) );
		$this->assertSame( '', Direct::provider_for( 'https://api.wordpress.org/core/version-check/' ) );

		Filters\expectApplied( 'axle_ai_guard_provider_hosts' )->atLeast()->once()->with( Mockery::type( 'array' ) )->andReturn( array( 'example.com/ai/' => 'acme' ) );
		$this->assertSame( 'acme', Direct::provider_for( 'https://example.com/ai/v1/chat' ) );
		$this->assertSame( '', Direct::provider_for( 'https://example.com/blog/' ) );
	}

	public function test_off_by_default_and_other_hosts_untouched(): void {
		$this->settings->save( array( 'direct_monitoring' => false ) );
		$this->kill->engage( 'global', '', '', 1 );
		$this->assertFalse( $this->direct->pre( false, array(), 'https://api.openai.com/v1/x' ) );

		$this->settings->save( array( 'direct_monitoring' => true ) );
		$this->assertFalse( $this->direct->pre( false, array(), 'https://example.org/' ) );
		$this->assertSame( 'short', $this->direct->pre( 'short', array(), 'https://api.openai.com/v1/x' ) );
	}

	public function test_a_kill_switch_blocks_a_direct_call(): void {
		$this->kill->engage( 'provider', 'openai', '', 1 );
		$result = $this->direct->pre( false, array( 'body' => '{"model":"gpt-5"}' ), 'https://api.openai.com/v1/x' );
		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'kill:provider', $this->db->calls[1]['block_rule'] );
		$this->assertSame( 'direct', $this->db->calls[1]['path'] );
	}

	public function test_a_direct_call_is_logged_with_tokens_from_the_response(): void {
		Actions\expectDone( 'axle_ai_guard_call_finished' )->once()->with( 1, Mockery::type( 'array' ), Mockery::type( 'array' ) );
		$args = array( 'body' => '{"model":"claude-haiku-4-5","messages":[]}' );
		$this->assertFalse( $this->direct->pre( false, $args, 'https://api.anthropic.com/v1/messages' ) );
		$this->direct->debug(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => '{"usage":{"input_tokens":1000,"output_tokens":200}}',
			),
			'response',
			'Requests',
			$args,
			'https://api.anthropic.com/v1/messages'
		);

		$row = $this->db->calls[1];
		$this->assertSame( 'ok', $row['status'] );
		$this->assertSame( 1200, $row['total_tokens'] );
		$this->assertSame( 0, $row['estimated'] );
		// 1,000 at 1.00 and 200 at 5.00 per million.
		$this->assertSame( 2000, $row['cost_micros'] );
	}

	public function test_requests_from_the_transport_are_left_to_it(): void {
		$this->guard->set_sending( true );
		$this->kill->engage( 'global', '', '', 1 );
		$this->assertFalse( $this->direct->pre( false, array(), 'https://api.openai.com/v1/x' ) );
		$this->guard->set_sending( false );
	}
}
