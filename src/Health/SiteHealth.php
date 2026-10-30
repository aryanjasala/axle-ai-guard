<?php
/**
 * Site Health tests.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Health;

use AryanX\Axle\AiGuard\Admin\Page;
use AryanX\Axle\AiGuard\Calls;
use AryanX\Axle\AiGuard\Policy;
use AryanX\Axle\AiGuard\Service;
use AryanX\Axle\AiGuard\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Five checks: AI calls without any budget, providers with their own
 * transport (no redaction), many unknown callers, double logging with the AI
 * plugin, and connector keys stored in the database.
 */
final class SiteHealth implements Service {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The call log.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Sets up the tests.
	 *
	 * @param Settings $settings Settings.
	 * @param Calls    $calls    The call log.
	 * @param Policy   $policy   Policy.
	 */
	public function __construct( Settings $settings, Calls $calls, Policy $policy ) {
		$this->settings = $settings;
		$this->calls    = $calls;
		$this->policy   = $policy;
	}

	/**
	 * Adds the tests.
	 */
	public function register(): void {
		add_filter( 'site_status_tests', array( $this, 'tests' ) );
	}

	/**
	 * Registers the tests.
	 *
	 * @param mixed $tests Tests.
	 * @return array
	 */
	public function tests( $tests ): array {
		$tests = (array) $tests;
		foreach ( array( 'budget', 'transport', 'unknown', 'double_log', 'keys' ) as $test ) {
			$tests['direct'][ 'axle_ai_guard_' . $test ] = array(
				'label' => __( 'Axle AI Guard', 'axle-ai-guard' ),
				'test'  => array( $this, 'test_' . $test ),
			);
		}

		return $tests;
	}

	/**
	 * A result.
	 *
	 * @param string $id          Test id.
	 * @param bool   $good        Whether it passed.
	 * @param string $label       Heading.
	 * @param string $description Text.
	 * @return array
	 */
	private static function result( string $id, bool $good, string $label, string $description ): array {
		return array(
			'label'       => $label,
			'status'      => $good ? 'good' : 'recommended',
			'badge'       => array(
				'label' => __( 'AI', 'axle-ai-guard' ),
				'color' => $good ? 'blue' : 'orange',
			),
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => $good ? '' : sprintf( '<p><a href="%s">%s</a></p>', esc_url( Page::url( 'budgets' ) ), esc_html__( 'Open AI Guard', 'axle-ai-guard' ) ),
			'test'        => 'axle_ai_guard_' . $id,
		);
	}

	/**
	 * Health numbers for the last 30 days.
	 *
	 * @return array{calls: int, unknown: int, untransported: string[]}
	 */
	private function recent(): array {
		return $this->calls->health( gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) );
	}

	/**
	 * AI calls happen and no budget is set.
	 *
	 * @return array
	 */
	public function test_budget(): array {
		$has = (bool) array_filter( $this->policy->budget( 'global' ) );
		foreach ( array_keys( $this->settings->policies() ) as $subject ) {
			$has = $has || (bool) array_filter( $this->policy->budget( $subject ) );
		}
		$good = $has || 0 === $this->recent()['calls'];

		return self::result(
			'budget',
			$good,
			$good ? __( 'AI spending has a limit, or there is none to limit', 'axle-ai-guard' ) : __( 'AI calls happen with no budget', 'axle-ai-guard' ),
			$good ? __( 'Either a budget is set, or no AI calls were made in the last 30 days.', 'axle-ai-guard' ) : __( 'Plugins made AI calls in the last 30 days and no budget is set, so nothing stops a runaway loop from running up the bill. Set a monthly money budget for the site.', 'axle-ai-guard' )
		);
	}

	/**
	 * Providers whose calls never went through the transport, so redaction cannot reach them.
	 *
	 * @return array
	 */
	public function test_transport(): array {
		$own  = $this->recent()['untransported'];
		$good = ! $own;

		return self::result(
			'transport',
			$good,
			$good ? __( 'AI requests go through the WordPress transport', 'axle-ai-guard' ) : __( 'An AI provider sends requests on its own', 'axle-ai-guard' ),
			$good
				? __( 'Data rules in redact mode can rewrite what providers receive.', 'axle-ai-guard' )
				/* translators: %s: provider ids. */
				: sprintf( __( 'These providers send requests without the AI Client transport, so personal data can be blocked but not redacted: %s.', 'axle-ai-guard' ), implode( ', ', $own ) )
		);
	}

	/**
	 * More than 10% of calls come from callers that could not be attributed.
	 *
	 * @return array
	 */
	public function test_unknown(): array {
		$recent = $this->recent();
		$good   = 0 === $recent['calls'] || $recent['unknown'] * 10 <= $recent['calls'];

		return self::result(
			'unknown',
			$good,
			$good ? __( 'AI calls are attributed to their plugins', 'axle-ai-guard' ) : __( 'Many AI calls come from unknown callers', 'axle-ai-guard' ),
			$good ? __( 'Most calls in the last 30 days were traced to a plugin, theme or mu-plugin.', 'axle-ai-guard' ) : __( 'More than 1 in 10 AI calls in the last 30 days could not be traced to a plugin, so per-plugin budgets and switches do not apply to them. The Calls screen shows the file each call came from; rules for the caller "unknown" cover them.', 'axle-ai-guard' )
		);
	}

	/**
	 * The AI plugin logs requests too.
	 *
	 * @return array
	 */
	public function test_double_log(): array {
		$good = ! Page::ai_plugin_feature( 'ai-request-logging' );

		return self::result(
			'double_log',
			$good,
			$good ? __( 'AI calls are logged once', 'axle-ai-guard' ) : __( 'AI calls are logged twice', 'axle-ai-guard' ),
			$good ? __( 'Only Axle AI Guard logs AI calls.', 'axle-ai-guard' ) : __( 'The AI plugin\'s request logging is also on. Both work side by side; you may want to keep only one log.', 'axle-ai-guard' )
		);
	}

	/**
	 * Connector API keys stored in the database.
	 *
	 * @return array
	 */
	public function test_keys(): array {
		$stored = array();
		if ( function_exists( 'wp_get_connectors' ) ) {
			foreach ( array_keys( (array) wp_get_connectors() ) as $id ) {
				$value = get_option( 'connectors_ai_' . sanitize_key( (string) $id ) . '_api_key', '' );
				if ( is_string( $value ) && '' !== $value ) {
					$stored[] = (string) $id;
				}
			}
		}
		$good = ! $stored;

		return self::result(
			'keys',
			$good,
			$good ? __( 'No AI API keys are stored in the database', 'axle-ai-guard' ) : __( 'AI API keys are stored in the database', 'axle-ai-guard' ),
			$good
				? __( 'Connector keys come from environment variables or constants, or none are set.', 'axle-ai-guard' )
				/* translators: %s: connector ids. */
				: sprintf( __( 'The keys of these connectors are stored as plain text options: %s. Anyone with a database copy can use them. Move them to an environment variable or a constant in wp-config.php.', 'axle-ai-guard' ), implode( ', ', $stored ) )
		);
	}
}
