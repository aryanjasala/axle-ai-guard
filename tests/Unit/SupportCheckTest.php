<?php
/**
 * Tests for support checks and generations seen through the builder's __call frame.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Sdk;
use Brain\Monkey\Filters;
use Mockery;

/**
 * Covers Sdk::builder_method() and the support-check path of Guard.
 */
final class SupportCheckTest extends GuardTestCase {

	protected function setUp(): void {
		parent::setUp();
		Filters\expectApplied( 'axle_ai_guard_caller' )->zeroOrMoreTimes()->andReturn( self::caller() );
		$this->settings->remember_caller( self::caller(), 'approved' );
	}

	/**
	 * Runs the prevent filter as if from inside a builder method.
	 *
	 * @param string $method Builder method.
	 * @return bool
	 */
	private function prevent_from( string $method ): bool {
		$builder = new \WP_AI_Client_Prompt_Builder(
			function () {
				return $this->guard->prevent( false );
			}
		);

		return $builder->$method();
	}

	public function test_the_builder_method_is_read_from_the_backtrace(): void {
		$builder = new \WP_AI_Client_Prompt_Builder( array( Sdk::class, 'current_builder_method' ) );
		$this->assertSame( 'generate_text', $builder->generate_text() );
		$this->assertSame( '', Sdk::current_builder_method() );
		$this->assertTrue( Sdk::is_support_check( 'is_supported_for_text_generation' ) );
	}

	public function test_support_checks_skip_limits_and_are_not_logged(): void {
		$this->settings->save_policy( 'plugin:caller-a', array( 'per_minute' => 0, 'contexts' => array( 'admin' ) ) );

		$this->assertFalse( $this->prevent_from( 'is_supported_for_text_generation' ), 'The context rule does not hide the feature.' );
		$this->assertSame( array(), $this->db->calls );
		$this->assertTrue( $this->prevent_from( 'generate_text' ) );
		$this->assertSame( 'context', $this->db->calls[1]['block_rule'] );

		$this->kill->engage( 'plugin', 'caller-a', '', 1 );
		$this->assertTrue( $this->prevent_from( 'is_supported_for_text_generation' ) );
		$this->assertCount( 1, $this->db->calls );
	}

	public function test_the_count_support_checks_filter(): void {
		$this->settings->save_policy( 'plugin:caller-a', array( 'contexts' => array( 'admin' ) ) );
		Filters\expectApplied( 'axle_ai_guard_count_support_checks' )->once()->with( false, Mockery::type( 'array' ) )->andReturn( true );

		$this->assertTrue( $this->prevent_from( 'is_supported_for_text_generation' ) );
		$this->assertCount( 1, $this->db->calls );
	}

	public function test_a_generation_under_the_global_switch_is_logged(): void {
		$this->kill->engage( 'global', '', '', 1 );
		$builder = new \WP_AI_Client_Prompt_Builder(
			function () {
				return $this->guard->supports( true );
			}
		);

		$this->assertFalse( $builder->is_supported() );
		$this->assertSame( array(), $this->db->calls );
		$this->assertFalse( $builder->generate_text() );
		$this->assertSame( 'kill:global', $this->db->calls[1]['block_rule'] );
	}
}
