<?php
/**
 * Tests for settings, policies and callers.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Settings;

/**
 * Covers Settings.
 */
final class SettingsTest extends GuardTestCase {

	public function test_defaults_and_clamping(): void {
		$this->assertSame( 'alert', $this->settings->get( 'new_caller' ) );
		$this->assertSame( 60, $this->settings->get( 'rate_plugin' ) );

		$saved = $this->settings->save(
			array(
				'new_caller'        => 'nope',
				'currency'          => 'eur',
				'soft_ratio'        => 500,
				'retention_prompts' => 0,
				'store_preview'     => '1',
				'rate_ip'           => -4,
				'alert_recipients'  => 'ops@example.com, junk, ops@example.com',
			)
		);

		$this->assertSame( 'alert', $saved['new_caller'] );
		$this->assertSame( 'EUR', $saved['currency'] );
		$this->assertSame( 100, $saved['soft_ratio'] );
		$this->assertSame( 1, $saved['retention_prompts'] );
		$this->assertTrue( $saved['store_preview'] );
		$this->assertSame( 0, $saved['rate_ip'] );
		$this->assertSame( 'ops@example.com', $saved['alert_recipients'] );
	}

	public function test_policies_are_sanitised(): void {
		$policy = $this->settings->save_policy(
			'plugin:chatbot',
			array(
				'monthly_cost' => '-5',
				'per_minute'   => '',
				'contexts'     => array( 'front', 'moon' ),
				'models'       => 'GPT-5*, claude-haiku-4 ',
				'block_files'  => '1',
			)
		);

		$this->assertSame( 0, $policy['monthly_cost'] );
		$this->assertSame( -1, $policy['per_minute'] );
		$this->assertSame( array( 'front' ), $policy['contexts'] );
		$this->assertSame( array( 'gpt-5*', 'claude-haiku-4' ), $policy['models'] );
		$this->assertTrue( $policy['block_files'] );
		$this->assertSame( Settings::empty_policy(), $this->settings->save_policy( 'nonsense subject', array() ) );
		$this->assertSame( 'global', Settings::subject( ' GLOBAL ' ) );
		$this->assertSame( '', Settings::subject( 'user:1' ) );
	}

	public function test_callers_are_remembered_and_can_be_approved(): void {
		$this->assertSame( '', $this->settings->caller_status( 'caller-a' ) );
		$this->settings->remember_caller( self::caller(), 'pending' );
		$this->assertSame( 'pending', $this->settings->caller_status( 'caller-a' ) );

		$this->assertTrue( $this->settings->set_caller_status( 'caller-a', 'approved' ) );
		$this->assertSame( 'approved', $this->settings->caller_status( 'caller-a' ) );
		$this->assertFalse( $this->settings->set_caller_status( 'caller-a', 'pending' ) );
		$this->assertFalse( $this->settings->set_caller_status( 'nobody', 'approved' ) );
	}
}
