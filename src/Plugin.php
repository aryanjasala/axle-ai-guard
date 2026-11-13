<?php
/**
 * Plugin bootstrap.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

use AryanX\Axle\AiGuard\Admin\Network;
use AryanX\Axle\AiGuard\Admin\Page;
use AryanX\Axle\AiGuard\Cli\Command;
use AryanX\Axle\AiGuard\Health\SiteHealth;
use AryanX\Axle\AiGuard\Rest\Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the services once and registers their hooks.
 */
final class Plugin {

	/**
	 * The running instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Alerts.
	 *
	 * @var Alerts
	 */
	private Alerts $alerts;

	/**
	 * Kill switches.
	 *
	 * @var KillSwitch
	 */
	private KillSwitch $kill;

	/**
	 * Usage counters.
	 *
	 * @var Usage
	 */
	private Usage $usage;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Data rules.
	 *
	 * @var Detectors
	 */
	private Detectors $detectors;

	/**
	 * Prices.
	 *
	 * @var Prices
	 */
	private Prices $prices;

	/**
	 * The call log.
	 *
	 * @var Calls
	 */
	private Calls $calls;

	/**
	 * The guard.
	 *
	 * @var Guard
	 */
	private Guard $guard;

	/**
	 * Reports.
	 *
	 * @var Reports
	 */
	private Reports $reports;

	/**
	 * Clean-up.
	 *
	 * @var Cleanup
	 */
	private Cleanup $cleanup;

	/**
	 * Runaway guard.
	 *
	 * @var Runaway
	 */
	private Runaway $runaway;

	/**
	 * Month-end forecast.
	 *
	 * @var Forecast
	 */
	private Forecast $forecast;

	/**
	 * Starts the plugin. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}

		if ( ! Requirements::met() ) {
			Requirements::fail();
			return;
		}

		self::$instance = new self();
		self::$instance->register();
	}

	/**
	 * The running instance.
	 *
	 * @return Plugin|null
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * The capability for every screen, route, ability and command.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to see and change AI Guard.
		 *
		 * @param string $capability Default axle_ai_guard_manage, given to administrators on activation.
		 */
		$cap = trim( (string) apply_filters( 'axle_ai_guard_capability', Schema::CAPABILITY ) );

		return '' !== $cap ? $cap : Schema::CAPABILITY;
	}

	/**
	 * Builds the shared objects.
	 */
	private function __construct() {
		$this->settings  = new Settings();
		$this->alerts    = new Alerts( $this->settings );
		$this->kill      = new KillSwitch( $this->alerts );
		$this->usage     = new Usage();
		$this->policy    = new Policy( $this->settings, $this->kill, $this->usage, $this->alerts );
		$this->detectors = new Detectors();
		$this->prices    = new Prices( $this->settings );
		$this->calls     = new Calls();
		$this->guard     = new Guard( $this->settings, $this->policy, $this->kill, $this->detectors, $this->prices, $this->calls, $this->alerts );
		$this->reports   = new Reports( $this->settings, $this->policy, $this->kill, $this->detectors, $this->prices, $this->calls, $this->usage, $this->guard );
		$this->cleanup   = new Cleanup( $this->settings, $this->calls, $this->usage, $this->prices );
		$this->runaway   = new Runaway( $this->settings, $this->kill, $this->usage, $this->alerts );
		$this->forecast  = new Forecast( $this->settings, $this->policy, $this->usage, $this->alerts, $this->runaway );
	}

	/**
	 * Settings, for other plugins.
	 *
	 * @return Settings
	 */
	public function settings(): Settings {
		return $this->settings;
	}

	/**
	 * Kill switches, for other plugins.
	 *
	 * @return KillSwitch
	 */
	public function kill(): KillSwitch {
		return $this->kill;
	}

	/**
	 * Reports, for other plugins.
	 *
	 * @return Reports
	 */
	public function reports(): Reports {
		return $this->reports;
	}

	/**
	 * The runaway guard, for other plugins.
	 *
	 * @return Runaway
	 */
	public function runaway(): Runaway {
		return $this->runaway;
	}

	/**
	 * The forecast, for other plugins.
	 *
	 * @return Forecast
	 */
	public function forecast(): Forecast {
		return $this->forecast;
	}

	/**
	 * Registers the services.
	 */
	private function register(): void {
		Schema::maybe_upgrade();

		$services = array(
			$this->guard,
			$this->kill,
			new Direct( $this->settings, $this->guard, $this->kill, $this->policy, $this->calls, $this->prices ),
			$this->cleanup,
			$this->runaway,
			$this->forecast,
			new Privacy( $this->calls ),
			new Abilities( $this->reports, $this->kill, $this->calls, $this->runaway, $this->forecast ),
			new Controller( $this->reports, $this->kill, $this->calls, $this->runaway, $this->prices ),
			new Page( $this->settings, $this->policy, $this->kill, $this->detectors, $this->prices, $this->calls, $this->reports, $this->cleanup, $this->runaway, $this->forecast ),
			new Network( $this->usage ),
			new SiteHealth( $this->settings, $this->calls, $this->policy, $this->runaway, $this->forecast ),
		);

		foreach ( $services as $service ) {
			$service->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'axle-ai-guard', new Command( $this->settings, $this->kill, $this->detectors, $this->prices, $this->calls, $this->reports, $this->cleanup, $this->runaway, $this->forecast ) );
		}
	}
}
