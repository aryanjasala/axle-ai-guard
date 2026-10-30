<?php
/**
 * Tests for the interception layer.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\BlockedException;
use AryanX\Axle\AiGuard\Guard;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Guard.
 */
final class GuardTest extends GuardTestCase {

	protected function setUp(): void {
		parent::setUp();
		// Every call in these tests comes from caller-a.
		Filters\expectApplied( 'axle_ai_guard_caller' )->zeroOrMoreTimes()->andReturn( self::caller() );
	}

	public function test_it_hooks_into_the_ai_client(): void {
		$this->guard->register();

		$this->assertSame( 1000, has_filter( 'wp_supports_ai', array( $this->guard, 'supports' ) ) );
		$this->assertSame( 1000, has_filter( 'wp_ai_client_prevent_prompt', array( $this->guard, 'prevent' ) ) );
		$this->assertSame( 1000, has_action( 'wp_ai_client_before_generate_result', array( $this->guard, 'before' ) ) );
		$this->assertSame( 1000, has_action( 'wp_ai_client_after_generate_result', array( $this->guard, 'after' ) ) );
		$this->assertSame( 1000, has_filter( 'wp_ai_client_prevent_embedding', array( $this->guard, 'prevent_embedding' ) ) );
		$this->assertSame( 0, has_action( 'shutdown', array( $this->guard, 'close_open' ) ) );
	}

	public function test_supports_follows_the_global_and_context_switches(): void {
		$this->assertTrue( $this->guard->supports( true ) );
		$this->assertFalse( $this->guard->supports( false ) );

		$this->kill->engage( 'context', 'front', '', 1 );
		$this->assertFalse( $this->guard->supports( true ) );
		$this->kill->release( 'context', 'front', 1 );

		$this->kill->engage( 'global', '', '', 1 );
		$this->assertFalse( $this->guard->supports( true ) );
		$this->assertSame( array(), $this->db->calls, 'Outside a builder call nothing is logged.' );
	}

	public function test_prevent_blocks_a_killed_caller_and_logs_it(): void {
		$this->kill->engage( 'plugin', 'caller-a', '', 1 );
		Actions\expectDone( 'axle_ai_guard_call_blocked' )->once()->with( 'kill:plugin', Mockery::type( 'array' ), Mockery::on( static function ( $row ) {
			return 'blocked' === $row['status'] && 1 === $row['id'];
		} ) );

		$this->assertTrue( $this->guard->prevent( false ) );
		$this->assertSame( 'kill:plugin', $this->db->calls[1]['block_rule'] );
		$this->assertTrue( $this->guard->prevent( true ), 'An earlier prevent stays.' );
	}

	public function test_a_new_caller_is_remembered_with_the_default_and_its_filter(): void {
		Filters\expectApplied( 'axle_ai_guard_new_caller_default' )->once()->with( 'alert', Mockery::type( 'array' ) )->andReturn( 'deny' );
		Actions\expectDone( 'axle_ai_guard_new_caller_seen' )->once()->with( Mockery::type( 'array' ), 'deny' );

		$this->assertTrue( $this->guard->prevent( false ) );
		$this->assertSame( 'pending', $this->settings->caller_status( 'caller-a' ) );
		$this->assertSame( 'caller', $this->db->calls[1]['block_rule'] );
		$this->assertStringContainsString( 'blocked until you approve it', $this->mails[0]['subject'] );
	}

	public function test_the_full_life_of_a_call(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		Actions\expectDone( 'axle_ai_guard_call_started' )->once()->with( 1, Mockery::type( 'array' ) );
		Actions\expectDone( 'axle_ai_guard_call_finished' )->once()->with( 1, Mockery::type( 'array' ), Mockery::type( 'array' ) );

		$this->assertFalse( $this->guard->prevent( false ) );
		$this->guard->before( self::event( 'Write a welcome note for jane@example.com' ) );
		$this->assertSame( 'pending', $this->db->calls[1]['status'] );
		$this->assertSame( array( 'user_email' ), $this->guard->current()['redact'] );

		$this->guard->note_redacted( array( 'user_email' => 1 ) );
		$this->guard->note_http( 300, 200, 900 );
		$this->guard->after( self::event( 'x', array( 1000, 500, 0 ) ) );

		$row = $this->db->calls[1];
		$this->assertSame( 'ok', $row['status'] );
		$this->assertSame( 'gpt-5-mini-2025-08-07', $row['model'] );
		$this->assertSame( 1500, $row['total_tokens'] );
		$this->assertSame( 1250, $row['cost_micros'] );
		$this->assertSame( 300, $row['request_bytes'] );
		$profile = json_decode( $row['profile'], true );
		$this->assertSame( array( 'user_email' => 1 ), $profile['redacted'] );
		$this->assertSame( 1, $profile['findings']['user_email'] );
		$this->assertNull( $this->guard->current() );
	}

	public function test_a_data_rule_in_block_mode_throws(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		Actions\expectDone( 'axle_ai_guard_call_blocked' )->once()->with( 'data:password_hash', Mockery::type( 'array' ), Mockery::type( 'array' ) );

		try {
			$this->guard->before( self::event( 'hash $P$BIRXVj/ZG0YRiBH8gnRy0chBx67WuK/' ) );
			$this->fail( 'No exception.' );
		} catch ( BlockedException $e ) {
			$this->assertSame( 'data:password_hash', $e->rule() );
		}
		$this->assertSame( 'blocked', $this->db->calls[1]['status'] );
		$this->assertNull( $this->guard->current() );
	}

	public function test_without_the_exception_path_block_mode_redacts(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		Filters\expectApplied( 'axle_ai_guard_block_in_before_event' )->once()->with( true, Mockery::type( 'array' ) )->andReturn( false );

		$this->guard->before( self::event( 'hash $P$BIRXVj/ZG0YRiBH8gnRy0chBx67WuK/' ) );
		$this->assertSame( array( 'password_hash' ), $this->guard->current()['redact'] );
		$this->assertSame( 'data:password_hash', json_decode( $this->db->calls[1]['profile'], true )['not_blocked'] );
	}

	public function test_files_can_be_blocked_per_plugin(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		$this->settings->save_policy( 'plugin:caller-a', array( 'block_files' => true ) );

		$this->expectException( BlockedException::class );
		$this->guard->before( self::event( 'Describe this', null, '', array( array( 'image/png', base64_encode( 'png-bytes' ) ) ) ) );
	}

	public function test_too_large_to_scan_and_the_scan_size_filter(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		Filters\expectApplied( 'axle_ai_guard_max_scan_bytes' )->once()->andReturn( 10 );

		try {
			$this->guard->before( self::event( str_repeat( 'a', 2000 ) ) );
			$this->fail( 'No exception.' );
		} catch ( BlockedException $e ) {
			$this->assertSame( 'scan', $e->rule() );
		}
	}

	public function test_the_token_estimator_filter_feeds_the_budget(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		$this->settings->save_policy( 'global', array( 'daily_tokens' => 100 ) );
		Filters\expectApplied( 'axle_ai_guard_token_estimator' )->atLeast()->once()->with( 3, 12, 0, Mockery::type( 'array' ) )->andReturn( 500 );

		$this->expectException( BlockedException::class );
		$this->guard->before( self::event( 'Hello there' ) );
	}

	public function test_previews_are_redacted_and_follow_their_filters(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		Filters\expectApplied( 'axle_ai_guard_store_preview' )->once()->with( false, Mockery::type( 'array' ) )->andReturn( true );
		Filters\expectApplied( 'axle_ai_guard_preview_length' )->once()->with( 300, Mockery::type( 'array' ) )->andReturn( 30 );

		$this->guard->before( self::event( 'Write to jane@example.com about the order that shipped late' ) );
		$this->assertSame( 'Write to [redacted:user_email]', $this->db->calls[1]['preview'] );
	}

	public function test_the_prompt_store(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		$this->settings->save( array( 'store_prompts' => true ) );

		$this->guard->before( self::event( 'Hi jane@example.com', null, 'Be brief' ) );
		$stored = json_decode( $this->db->prompts[1]['redacted_messages'], true );
		$this->assertSame( 'Be brief', $stored[0]['text'] );
		$this->assertSame( 'Hi [redacted:user_email]', $stored[1]['text'] );
	}

	public function test_failures_close_the_row_and_fire_call_failed(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		Actions\expectDone( 'axle_ai_guard_call_failed' )->once()->with( 1, 'http_500', Mockery::type( 'array' ) );
		Actions\expectDone( 'axle_ai_guard_call_failed' )->once()->with( 2, 'incomplete', Mockery::type( 'array' ) );

		$this->guard->before( self::event( 'One' ) );
		$this->guard->note_http( 10, 500, 20 );
		$this->assertSame( 'error', $this->db->calls[1]['status'] );
		$this->assertSame( 500, $this->db->calls[1]['http_status'] );

		$this->guard->before( self::event( 'Two' ) );
		$this->guard->close_open();
		$this->assertSame( 'error', $this->db->calls[2]['status'] );
		$this->assertSame( 'incomplete', $this->db->calls[2]['error_code'] );
	}

	public function test_an_error_spike_trips_the_global_switch(): void {
		$this->settings->remember_caller( self::caller(), 'approved' );
		Filters\expectApplied( 'axle_ai_guard_autotrip_error_threshold' )->atLeast()->once()->with( 0 )->andReturn( 50 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->guard->before( self::event( 'Call ' . $i ) );
			$this->guard->note_http( 10, 503, 0 );
		}
		$this->assertTrue( $this->kill->global_on() );
	}

	public function test_support_checks_and_their_filter(): void {
		$this->assertFalse( $this->guard->prevent( false ) );
		$this->assertSame( array(), $this->db->calls );

		Filters\expectApplied( 'axle_ai_guard_count_support_checks' )->never();
		$this->assertTrue( Guard::block_in_before( self::call() ) );
	}

	public function test_a_broken_guard_fails_open_unless_told_otherwise(): void {
		\Brain\Monkey\Functions\when( 'wp_cache_get' )->justReturn( 'no' );
		$this->assertFalse( $this->guard->prevent( false ) );
		$this->assertStringContainsString( 'tables are missing', $this->transients[ Guard::DEGRADED ] );

		Filters\expectApplied( 'axle_ai_guard_fail_closed' )->atLeast()->once()->with( false, Mockery::type( 'Throwable' ) )->andReturn( true );
		$this->assertTrue( $this->guard->prevent( false ) );
		$this->expectException( BlockedException::class );
		$this->guard->before( self::event( 'x' ) );
	}
}
