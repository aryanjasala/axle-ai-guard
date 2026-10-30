<?php
/**
 * Tests for caller attribution and the request context.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Caller;
use AryanX\Axle\AiGuard\Context;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Covers Caller and Context.
 */
final class CallerContextTest extends GuardTestCase {

	/**
	 * Frames as debug_backtrace() gives them, innermost first.
	 *
	 * @param string ...$files Files.
	 * @return array
	 */
	private static function frames( string ...$files ): array {
		return array_map(
			static function ( string $file ): array {
				return array(
					'file' => $file,
					'line' => 42,
				);
			},
			$files
		);
	}

	public function test_the_first_frame_outside_core_and_this_plugin_wins(): void {
		$frames = self::frames(
			'/srv/wp/wp-content/plugins/axle-ai-guard/src/Guard.php',
			ABSPATH . 'wp-includes/class-wp-hook.php',
			ABSPATH . 'wp-includes/php-ai-client/src/Builders/PromptBuilder.php',
			'/srv/wp/wp-content/plugins/chatbot/includes/class-chat.php',
			'/srv/wp/wp-content/themes/twentytwentyfive/functions.php'
		);

		$this->assertSame(
			array(
				'type' => 'plugin',
				'slug' => 'chatbot',
				'file' => 'plugins/chatbot/includes/class-chat.php',
				'line' => 42,
			),
			Caller::from_frames( $frames )
		);
	}

	public function test_mu_plugins_themes_single_files_and_unknown_paths(): void {
		$this->assertSame( 'mu:helper', Caller::from_frames( self::frames( '/srv/wp/wp-content/mu-plugins/helper.php' ) )['slug'] );
		$this->assertSame( 'theme:shop', Caller::from_frames( self::frames( '/srv/wp/wp-content/themes/shop/inc/ai.php' ) )['slug'] );
		$this->assertSame( 'hello', Caller::from_frames( self::frames( '/srv/wp/wp-content/plugins/hello.php' ) )['slug'] );
		$this->assertSame( 'unknown', Caller::from_frames( self::frames( '/opt/vendor/lib.php' ) )['slug'] );
		$this->assertSame( 'core', Caller::from_frames( self::frames( ABSPATH . 'wp-admin/admin.php', ABSPATH . 'wp-settings.php' ) )['slug'] );
	}

	public function test_the_caller_filter_can_override_and_is_cleaned(): void {
		$frames = self::frames( '/srv/wp/wp-content/plugins/shared-lib/ai.php' );
		Filters\expectApplied( 'axle_ai_guard_caller' )->once()->with( Mockery::type( 'array' ), $frames )->andReturn(
			array(
				'type' => 'plugin',
				'slug' => 'Real Owner!',
				'file' => 'x.php',
				'line' => 3,
			)
		);
		$this->assertSame( 'realowner', Caller::from_frames( $frames )['slug'] );

		Filters\expectApplied( 'axle_ai_guard_caller' )->once()->andReturn( 'nonsense' );
		$this->assertSame( 'shared-lib', Caller::from_frames( $frames )['slug'] );
	}

	public function test_context_detection_and_filter(): void {
		$this->assertSame( 'front', Context::current() );

		Functions\when( 'is_admin' )->justReturn( true );
		$this->assertSame( 'admin', Context::current() );

		Functions\when( 'wp_doing_cron' )->justReturn( true );
		$this->assertSame( 'cron', Context::current() );

		Filters\expectApplied( 'axle_ai_guard_context' )->once()->with( 'cron' )->andReturn( 'cli' );
		$this->assertSame( 'cli', Context::current() );

		Filters\expectApplied( 'axle_ai_guard_context' )->once()->andReturn( 'moon' );
		$this->assertSame( 'cron', Context::current(), 'An unknown context falls back.' );
	}

	public function test_visitor_ip_is_anonymised_and_only_for_visitors(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$this->assertSame( '203.0.113.0', Context::visitor_ip( 'front' ) );
		$this->assertSame( '', Context::visitor_ip( 'cron' ) );

		$this->user = new \WP_User( 3, array( 'editor' ) );
		$this->assertSame( '', Context::visitor_ip( 'front' ) );

		$call = Context::call( 'client', self::caller() );
		$this->assertSame( array( 'editor' ), $call['roles'] );
		$this->assertSame( 3, $call['user_id'] );
	}
}
