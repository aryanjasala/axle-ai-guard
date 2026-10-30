<?php
/**
 * Tests for kill switches and alerts.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Alerts;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers KillSwitch and Alerts.
 */
final class KillSwitchAlertsTest extends GuardTestCase {

	public function test_engage_and_release_fire_their_actions_once(): void {
		Actions\expectDone( 'axle_ai_guard_kill_switch_on' )->once()->with( 'plugin', 'chatbot', Mockery::type( 'array' ) );
		Actions\expectDone( 'axle_ai_guard_kill_switch_off' )->once()->with( 'plugin', 'chatbot', 2 );

		$this->assertTrue( $this->kill->engage( 'plugin', 'ChatBot', 'Loop', 2 ) );
		$this->assertFalse( $this->kill->engage( 'plugin', 'chatbot', 'Again', 2 ), 'Already on.' );
		$this->assertTrue( $this->kill->is_on( 'plugin', 'chatbot' ) );
		$this->assertSame( 'kill:plugin', $this->kill->rule_for( self::call( 'chatbot' ) ) );

		$this->assertTrue( $this->kill->release( 'plugin', 'chatbot', 2 ) );
		$this->assertFalse( $this->kill->release( 'plugin', 'chatbot', 2 ) );
		$this->assertSame( '', $this->kill->rule_for( self::call( 'chatbot' ) ) );
	}

	public function test_scopes_and_validation(): void {
		$this->assertFalse( $this->kill->engage( 'plugin', '', 'x', 1 ) );
		$this->assertFalse( $this->kill->engage( 'context', 'moon', 'x', 1 ) );
		$this->assertTrue( $this->kill->engage( 'context', 'front', 'No AI for visitors', 1 ) );
		$this->assertSame( 'kill:context', $this->kill->rule_for( self::call( 'caller-a', array( 'context' => 'front' ) ) ) );

		$this->assertTrue( $this->kill->engage( 'global', 'ignored', '', 1 ) );
		$this->assertSame( 'kill:global', $this->kill->rule_for( self::call() ) );
		$this->assertCount( 2, $this->kill->active() );
		$this->assertSame( 2, $this->kill->release_all( 1 ) );
		$this->assertFalse( $this->kill->any_on() );
	}

	public function test_the_admin_bar_node(): void {
		$bar = Mockery::mock( 'WP_Admin_Bar' );
		$bar->shouldReceive( 'add_node' )->once()->with( Mockery::on( static function ( $node ) {
			return 'axle-ai-guard' === $node['id'];
		} ) );

		$this->kill->admin_bar( $bar );
		$this->kill->engage( 'provider', 'openai', '', 1 );
		$this->kill->admin_bar( $bar );
	}

	public function test_alerts_go_by_email_once_per_hour(): void {
		Actions\expectDone( 'axle_ai_guard_alert_sent' )->once()->with( 'new_caller', 'plugin:x', 'Hello', array( 'email' ) );

		$this->assertTrue( $this->alerts->send( 'new_caller', 'plugin:x', 'Hello' ) );
		$this->assertFalse( $this->alerts->send( 'new_caller', 'plugin:x', 'Hello' ) );
		$this->assertSame( array( 'admin@example.com' ), $this->mails[0]['to'] );
		$this->assertStringContainsString( '[Bean There] AI Guard: Hello', $this->mails[0]['subject'] );
	}

	public function test_the_recipients_and_throttle_filters(): void {
		$this->settings->save( array( 'alert_recipients' => 'ops@example.com' ) );
		Filters\expectApplied( 'axle_ai_guard_alert_recipients' )->atLeast()->once()->with( array( 'ops@example.com' ), 'kill_on' )->andReturn( array( 'sec@example.com', 'bad' ) );
		$this->assertSame( array( 'sec@example.com' ), $this->alerts->recipients( 'kill_on' ) );

		Filters\expectApplied( 'axle_ai_guard_alert_throttle' )->twice()->with( HOUR_IN_SECONDS, 'kill_on', 'global' )->andReturn( 0 );
		$this->alerts->send( 'kill_on', 'global', 'One' );
		$this->alerts->send( 'kill_on', 'global', 'Two' );
		$this->assertCount( 2, $this->mails );
		$this->assertSame( HOUR_IN_SECONDS, Alerts::throttle( 'x', 'y' ) );
	}

	public function test_the_webhook_and_its_payload_filter(): void {
		$this->settings->save(
			array(
				'webhook_url' => 'https://hooks.example.com/ai',
				'alert_email' => false,
			)
		);
		Filters\expectApplied( 'axle_ai_guard_webhook_payload' )->once()->with( Mockery::on( static function ( $payload ) {
			return 'budget_hard' === $payload['event'] && 'global' === $payload['subject'];
		} ), 'budget_hard' )->andReturnUsing( static function ( array $payload ): array {
			$payload['text'] = $payload['message'];
			return $payload;
		} );
		$this->assertTrue( $this->alerts->send( 'budget_hard', 'global', 'Over budget' ) );
		$this->assertCount( 1, $this->posts );
		$this->assertSame( 'https://hooks.example.com/ai', $this->posts[0][0] );
		$this->assertStringContainsString( '"text":"Over budget"', $this->posts[0][1]['body'] );
		$this->assertFalse( $this->posts[0][1]['blocking'] );
		$this->assertSame( array(), $this->mails );
	}
}
