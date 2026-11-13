<?php
/**
 * Tools > AI Guard: overview, calls, budgets, data rules, prices and settings.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Admin;

use AryanX\Axle\AiGuard\Calls;
use AryanX\Axle\AiGuard\Cleanup;
use AryanX\Axle\AiGuard\Detectors;
use AryanX\Axle\AiGuard\Forecast;
use AryanX\Axle\AiGuard\Guard;
use AryanX\Axle\AiGuard\KillSwitch;
use AryanX\Axle\AiGuard\Plugin;
use AryanX\Axle\AiGuard\Policy;
use AryanX\Axle\AiGuard\Prices;
use AryanX\Axle\AiGuard\Reports;
use AryanX\Axle\AiGuard\Runaway;
use AryanX\Axle\AiGuard\Service;
use AryanX\Axle\AiGuard\Settings;
use AryanX\Axle\AiGuard\UserReport;
use const AryanX\Axle\AiGuard\PLUGIN_FILE;
use const AryanX\Axle\AiGuard\VERSION;

defined( 'ABSPATH' ) || exit;

/**
 * The admin screen. Every form posts to admin-post.php with a nonce and the
 * AI Guard capability, and redirects back with a message.
 */
final class Page implements Service {

	public const SLUG = 'axle-ai-guard';
	public const TABS = array( 'overview', 'calls', 'people', 'budgets', 'rules', 'prices', 'settings' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Policy.
	 *
	 * @var Policy
	 */
	private Policy $policy;

	/**
	 * Kill switches.
	 *
	 * @var KillSwitch
	 */
	private KillSwitch $kill;

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
	 * Reports.
	 *
	 * @var Reports
	 */
	private Reports $reports;

	/**
	 * Clean-up and recalculation.
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
	 * Sets up the screen.
	 *
	 * @param Settings   $settings  Settings.
	 * @param Policy     $policy    Policy.
	 * @param KillSwitch $kill      Kill switches.
	 * @param Detectors  $detectors Data rules.
	 * @param Prices     $prices    Prices.
	 * @param Calls      $calls     The call log.
	 * @param Reports    $reports   Reports.
	 * @param Cleanup    $cleanup   Clean-up.
	 * @param Runaway    $runaway   Runaway guard.
	 * @param Forecast   $forecast  Forecast.
	 */
	public function __construct( Settings $settings, Policy $policy, KillSwitch $kill, Detectors $detectors, Prices $prices, Calls $calls, Reports $reports, Cleanup $cleanup, Runaway $runaway, Forecast $forecast ) {
		$this->settings  = $settings;
		$this->policy    = $policy;
		$this->kill      = $kill;
		$this->detectors = $detectors;
		$this->prices    = $prices;
		$this->calls     = $calls;
		$this->reports   = $reports;
		$this->cleanup   = $cleanup;
		$this->runaway   = $runaway;
		$this->forecast  = $forecast;
	}

	/**
	 * Adds the menu, notices and form handlers.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
		foreach ( array( 'kill', 'budgets', 'callers', 'rules', 'prices', 'settings', 'runaway', 'steps', 'people_csv' ) as $action ) {
			add_action( 'admin_post_axle_ai_guard_' . $action, array( $this, 'handle_' . $action ) );
		}
	}

	/**
	 * Adds "Open AI Guard" to the plugin's row on the Plugins screen.
	 *
	 * @param mixed $links Row links.
	 * @return array
	 */
	public function action_links( $links ): array {
		$links = (array) $links;
		if ( current_user_can( Plugin::capability() ) ) {
			array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open AI Guard', 'axle-ai-guard' ) . '</a>' );
		}

		return $links;
	}

	/**
	 * URL of a tab.
	 *
	 * @param string $tab  Tab.
	 * @param array  $args Extra query arguments.
	 * @return string
	 */
	public static function url( string $tab = 'overview', array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'tools.php' )
		);
	}

	/**
	 * Adds Tools > AI Guard.
	 */
	public function add_page(): void {
		$hook = add_management_page( __( 'Axle AI Guard', 'axle-ai-guard' ), __( 'AI Guard', 'axle-ai-guard' ), Plugin::capability(), self::SLUG, array( $this, 'render' ) );
		if ( $hook ) {
			add_action( "admin_print_styles-{$hook}", array( $this, 'enqueue' ) );
			add_action( "load-{$hook}", array( $this, 'help' ) );
		}
	}

	/**
	 * Styles, script and the data the script renders.
	 */
	public function enqueue(): void {
		AxleUi::enqueue();
		wp_enqueue_style( 'axle-ai-guard-admin', plugins_url( 'assets/admin.css', PLUGIN_FILE ), array( AxleUi::HANDLE ), VERSION );
		wp_enqueue_script( 'axle-ai-guard-admin', plugins_url( 'assets/admin.js', PLUGIN_FILE ), array( 'wp-hooks', 'wp-api-fetch', 'wp-i18n', 'wp-a11y' ), VERSION, true );
		wp_set_script_translations( 'axle-ai-guard-admin', 'axle-ai-guard' );

		$data = array(
			'currency' => $this->prices->currency(),
			'tab'      => $this->tab(),
			'screen'   => self::url(),
		);
		if ( 'overview' === $this->tab() ) {
			$data['cards'] = $this->cards();
		}
		if ( 'calls' === $this->tab() ) {
			$data['calls'] = array_map(
				static function ( array $row ): array {
					$call         = Reports::present( $row );
					$call['when'] = AxleUi::relative_time( (int) strtotime( (string) $row['started_at'] . ' UTC' ) );

					return $call;
				},
				$this->calls_query()['rows']
			);
		}
		wp_add_inline_script( 'axle-ai-guard-admin', 'window.axleAiGuardData = ' . wp_json_encode( $data ) . ';', 'before' );
	}

	/**
	 * The current tab.
	 *
	 * @return string
	 */
	private function tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.

		return in_array( $tab, self::TABS, true ) ? $tab : 'overview';
	}

	/**
	 * Notices on every admin screen: kill switches on, and a degraded guard.
	 */
	public function notices(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			return;
		}

		$degraded = get_transient( Guard::DEGRADED );
		if ( is_string( $degraded ) && '' !== $degraded ) {
			printf(
				'<div class="notice notice-warning aag-notice"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %s: error message. */
						__( 'Axle AI Guard hit an error and is letting AI calls through without its checks: %s', 'axle-ai-guard' ),
						$degraded
					)
				)
			);
		}

		foreach ( $this->runaway->open_events() as $event ) {
			$this->render_runaway_notice( $event );
		}

		if ( $this->kill->global_on() ) {
			printf(
				'<div class="notice notice-error aag-notice aag-kill-notice"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'All AI calls on this site are switched off by Axle AI Guard.', 'axle-ai-guard' ),
				esc_url( self::url() ),
				esc_html__( 'Open AI Guard', 'axle-ai-guard' )
			);
		}
	}

	/**
	 * A runaway event: what happened, the numbers, and what to do.
	 *
	 * @param array $event Event from Runaway::open_events().
	 */
	private function render_runaway_notice( array $event ): void {
		$paused = ! empty( $event['paused'] );
		echo '<div class="notice notice-warning aag-notice aag-runaway-notice axle-ui" data-event="' . esc_attr( (string) $event['id'] ) . '"><p><strong>' . esc_html(
			sprintf(
				/* translators: %s: caller key. */
				__( '%s may be running away with AI calls.', 'axle-ai-guard' ),
				(string) $event['caller']
			)
		) . '</strong> ';
		echo esc_html(
			sprintf(
				/* translators: 1: calls in the last hour, 2: usual calls per hour, 3: how many times the usual number it allows. */
				__( 'It made %1$s AI calls this hour. Its usual is about %2$s an hour, and the limit is %3$d times that.', 'axle-ai-guard' ),
				number_format_i18n( (int) $event['calls'] ),
				number_format_i18n( (int) $event['base_calls'] ),
				(int) $event['multiple']
			)
		) . ' ';
		echo esc_html( $paused ? __( 'Its AI calls are paused.', 'axle-ai-guard' ) : __( 'Its calls were not paused.', 'axle-ai-guard' ) ) . ' ';
		echo wp_kses_post( AxleUi::relative_time( (int) $event['at'] ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form">';
		self::form_fields( 'runaway' );
		echo '<input type="hidden" name="event" value="' . esc_attr( (string) $event['id'] ) . '">';
		if ( $paused ) {
			echo '<button type="submit" name="do" value="resume" class="button button-primary">' . esc_html__( 'Resume', 'axle-ai-guard' ) . '</button> ';
			echo '<button type="submit" name="do" value="raise" class="button">' . esc_html__( 'Resume and raise the limit', 'axle-ai-guard' ) . '</button> ';
		} else {
			echo '<button type="submit" name="do" value="raise" class="button">' . esc_html__( 'Raise the limit', 'axle-ai-guard' ) . '</button> ';
			echo '<button type="submit" name="do" value="dismiss" class="button">' . esc_html__( 'Dismiss', 'axle-ai-guard' ) . '</button>';
		}
		echo '</form></div>';
	}

	/**
	 * Help tabs for the current tab.
	 */
	public function help(): void {
		$tabs = array(
			'overview' => array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'AI Guard sees every AI call that plugins make on this site: what it cost, how many tokens it used and what data it carried. This page shows today and this month, and a switch to stop AI at once.', 'axle-ai-guard' ) . '</p>',
				),
				array(
					'id'      => 'runaway',
					'title'   => __( 'Runaway protection', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'AI Guard learns how many calls each plugin makes in a normal hour. When one makes many times more, it pauses that plugin only and tells you. Resume it when the cause is fixed, or raise its limit if the new volume is normal.', 'axle-ai-guard' ) . '</p>',
				),
				array(
					'id'      => 'forecast',
					'title'   => __( 'Forecast', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'The forecast takes the pace of the last 7 days and says when a monthly budget would run out. It only shows for sites and plugins that have a monthly budget.', 'axle-ai-guard' ) . '</p>',
				),
			),
			'calls'    => array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'Every AI call the guard saw, newest first. Use the filters to narrow the list, and open Details to see tokens, cost and what the data rules found.', 'axle-ai-guard' ) . '</p>',
				),
			),
			'people'   => array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'Who triggered the AI calls on this site, with their calls, tokens and cost for the period you pick. Calls that no logged-in person triggered, such as visitors, cron and WP-CLI, are listed as No user.', 'axle-ai-guard' ) . '</p>',
				),
				array(
					'id'      => 'roles',
					'title'   => __( 'Roles', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'Each user counts under their first role, as they have it now, so the role totals add up to the site total. If someone changed role during the period, all their calls count under the new one.', 'axle-ai-guard' ) . '</p>',
				),
				array(
					'id'      => 'export',
					'title'   => __( 'Download', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'Download the table as a CSV file for a spreadsheet. There is one row for each user and currency, with the cost as a plain number. The file has login names but no email addresses. When a person asks for their data to be erased, their calls move to No user.', 'axle-ai-guard' ) . '</p>',
				),
			),
			'budgets'  => array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'Set how much AI each plugin and the whole site may use. A budget blocks calls once it is used up.', 'axle-ai-guard' ) . '</p>',
				),
				array(
					'id'      => 'callers',
					'title'   => __( 'Approving plugins', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'A plugin that is denied cannot make AI calls. Change how new plugins are treated under Settings.', 'axle-ai-guard' ) . '</p>',
				),
			),
			'rules'    => array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'Data rules look at what a call sends. Log records a finding, redact replaces it before the request leaves, and block stops the call.', 'axle-ai-guard' ) . '</p>',
				),
				array(
					'id'      => 'try',
					'title'   => __( 'Try your rules', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'Paste text, pick a plugin and where the call comes from, and see what each rule would do. The text is never stored and never sent anywhere.', 'axle-ai-guard' ) . '</p>',
				),
			),
			'prices'   => array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'The price table turns tokens into estimated costs. Edit a price when your provider changes it, then recalculate past costs.', 'axle-ai-guard' ) . '</p>',
				),
				array(
					'id'      => 'import',
					'title'   => __( 'Import and export', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'Export the table as JSON to keep a copy or move it to another site. Importing adds to the table and overrides prices with the same key. You can undo the last change.', 'axle-ai-guard' ) . '</p>',
				),
			),
			'settings' => array(
				array(
					'id'      => 'overview',
					'title'   => __( 'Overview', 'axle-ai-guard' ),
					'content' => '<p>' . esc_html__( 'How new plugins are handled, how long data is kept, where alerts go and how runaway protection behaves.', 'axle-ai-guard' ) . '</p>',
				),
			),
		);

		AxleUi::help_tabs( $tabs[ $this->tab() ] );
	}

	/**
	 * Steps of the Get started card, with their done state read from real data.
	 *
	 * @return array<int, array{title: string, text: string, done: bool, url: string, label: string}>
	 */
	private function steps(): array {
		$budget = (bool) array_filter( $this->policy->budget( 'global' ) );
		foreach ( array_keys( $this->settings->policies() ) as $subject ) {
			$budget = $budget || (bool) array_filter( $this->policy->budget( $subject ) );
		}

		return array(
			array(
				'title' => __( 'See the first AI call', 'axle-ai-guard' ),
				'text'  => __( 'Plugins show up as soon as they make a call.', 'axle-ai-guard' ),
				'done'  => (bool) $this->settings->callers(),
				'url'   => self::url( 'calls' ),
				'label' => __( 'Open the calls', 'axle-ai-guard' ),
			),
			array(
				'title' => __( 'Set a monthly budget', 'axle-ai-guard' ),
				'text'  => __( 'A budget stops spending, and powers the forecast.', 'axle-ai-guard' ),
				'done'  => $budget,
				'url'   => self::url( 'budgets' ),
				'label' => __( 'Set a budget', 'axle-ai-guard' ),
			),
			array(
				'title' => __( 'Choose what happens to personal data', 'axle-ai-guard' ),
				'text'  => __( 'Redact or block emails, keys and your own patterns.', 'axle-ai-guard' ),
				'done'  => false !== get_option( 'axle_ai_guard_rules', false ),
				'url'   => self::url( 'rules' ),
				'label' => __( 'Review the rules', 'axle-ai-guard' ),
			),
		);
	}

	/**
	 * The Get started card, until every step is done or the user dismisses it.
	 */
	private function render_steps(): void {
		if ( get_user_meta( get_current_user_id(), 'axle_ai_guard_steps_dismissed', true ) ) {
			return;
		}
		$steps = $this->steps();
		if ( ! array_filter(
			$steps,
			static function ( array $step ): bool {
				return ! $step['done'];
			}
		) ) {
			return;
		}

		echo '<section class="axle-card aag-steps" aria-labelledby="aag-steps-title"><div class="axle-card__head"><h2 class="axle-card__title" id="aag-steps-title">' . esc_html__( 'Get started', 'axle-ai-guard' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'steps' );
		echo '<button type="submit" class="button-link">' . esc_html__( 'Dismiss', 'axle-ai-guard' ) . '</button></form></div><ol class="axle-steps">';
		foreach ( $steps as $step ) {
			echo '<li class="axle-step"><div class="axle-step__body"><strong class="axle-step__title">' . esc_html( $step['title'] ) . '</strong><p>' . esc_html( $step['text'] ) . '</p></div>';
			if ( $step['done'] ) {
				echo '<span class="axle-badge axle-badge--good">' . esc_html__( 'Done', 'axle-ai-guard' ) . '</span>';
			} else {
				echo '<a class="button" href="' . esc_url( $step['url'] ) . '">' . esc_html( $step['label'] ) . '</a>';
			}
			echo '</li>';
		}
		echo '</ol></section>';
	}

	/**
	 * Runaway protection and the month-end forecast.
	 */
	private function render_runaway(): void {
		echo '<section class="axle-card aag-runaway" aria-labelledby="aag-runaway-title"><h2 class="axle-card__title" id="aag-runaway-title">' . esc_html__( 'Runaway protection and forecast', 'axle-ai-guard' ) . '</h2>';
		$modes = array(
			'off'   => __( 'Off', 'axle-ai-guard' ),
			'alert' => __( 'Alert only', 'axle-ai-guard' ),
			'pause' => __( 'Pause', 'axle-ai-guard' ),
		);
		$mode  = $this->runaway->mode();
		echo '<p><span class="axle-badge axle-badge--' . ( 'off' === $mode ? 'neutral' : 'good' ) . '">' . esc_html( $modes[ $mode ] ) . '</span> ';
		echo esc_html(
			'pause' === $mode
				? __( 'A plugin that makes far more calls than usual is paused, and you get an alert.', 'axle-ai-guard' )
				: ( 'alert' === $mode ? __( 'A plugin that makes far more calls than usual triggers an alert. Nothing is paused.', 'axle-ai-guard' ) : __( 'Nothing watches for a plugin calling AI in a loop.', 'axle-ai-guard' ) )
		) . ' <a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Change the mode', 'axle-ai-guard' ) . '</a></p>';

		$forecasts = $this->forecast->all();
		if ( ! $forecasts ) {
			echo '<div class="axle-empty aag-empty"><p>' . esc_html__( 'No forecast yet. Set a monthly budget for the site or a plugin and AI Guard says when it would run out at the pace of the last 7 days.', 'axle-ai-guard' ) . '</p><a class="button" href="' . esc_url( self::url( 'budgets' ) ) . '">' . esc_html__( 'Set a monthly budget', 'axle-ai-guard' ) . '</a></div></section>';
			return;
		}

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Forecast per budget', 'axle-ai-guard' ) . '"><table class="widefat striped aag-table aag-forecast"><thead><tr>';
		foreach ( array( __( 'Budget', 'axle-ai-guard' ), __( 'Used this month', 'axle-ai-guard' ), __( 'Pace per day', 'axle-ai-guard' ), __( 'Forecast', 'axle-ai-guard' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $forecasts as $forecast ) {
			$money = 'cost' === $forecast['unit'];
			$fmt   = function ( $value ) use ( $money ): string {
				return $money ? $this->money( (int) round( $value ) ) : number_format_i18n( (int) round( $value ) );
			};
			if ( 'reached' === $forecast['state'] ) {
				$text = '<span class="axle-badge axle-badge--error">' . esc_html__( 'Used up', 'axle-ai-guard' ) . '</span> ' . esc_html__( 'The monthly budget is used up. Calls are blocked until next month.', 'axle-ai-guard' );
			} elseif ( $forecast['crosses'] ) {
				$text = '<span class="axle-badge axle-badge--warning">' . esc_html__( 'Runs out early', 'axle-ai-guard' ) . '</span> ' . sprintf(
					/* translators: 1: time until the budget runs out, 2: day of the month. */
					esc_html__( 'At this pace the monthly budget runs out %1$s, on the %2$s.', 'axle-ai-guard' ),
					AxleUi::relative_time( (int) $forecast['runs_out'] ),
					esc_html( wp_date( 'jS', (int) $forecast['runs_out'] ) )
				);
			} elseif ( 'quiet' === $forecast['state'] ) {
				$text = '<span class="axle-badge axle-badge--neutral">' . esc_html__( 'Quiet', 'axle-ai-guard' ) . '</span> ' . esc_html__( 'No use in the last 7 days.', 'axle-ai-guard' );
			} else {
				$text = '<span class="axle-badge axle-badge--good">' . esc_html__( 'On track', 'axle-ai-guard' ) . '</span> ' . esc_html__( 'The monthly budget lasts to the end of the month.', 'axle-ai-guard' );
			}
			echo '<tr><th scope="row"><code>' . esc_html( 'global' === $forecast['subject'] ? __( 'Whole site', 'axle-ai-guard' ) : substr( $forecast['subject'], 7 ) ) . '</code></th>';
			echo '<td>' . esc_html( $fmt( $forecast['used'] ) . ' / ' . $fmt( $forecast['budget'] ) ) . '</td><td>' . esc_html( $fmt( $forecast['per_day'] ) ) . '</td><td>' . wp_kses_post( $text ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AI Guard.', 'axle-ai-guard' ) );
		}

		$tab    = $this->tab();
		$labels = array(
			'overview' => __( 'Overview', 'axle-ai-guard' ),
			'calls'    => __( 'Calls', 'axle-ai-guard' ),
			'people'   => __( 'Users and roles', 'axle-ai-guard' ),
			'budgets'  => __( 'Budgets and limits', 'axle-ai-guard' ),
			'rules'    => __( 'Data rules', 'axle-ai-guard' ),
			'prices'   => __( 'Prices', 'axle-ai-guard' ),
			'settings' => __( 'Settings', 'axle-ai-guard' ),
		);

		$intros = array(
			'overview' => __( 'See which plugins make AI calls on this site, what they cost and what was blocked. The kill switch stops every call at once.', 'axle-ai-guard' ),
			'calls'    => __( 'Every AI call the guard saw, newest first. Open a call to see its tokens, cost and what the data rules found.', 'axle-ai-guard' ),
			'people'   => __( 'See who triggers AI calls and what each person and role costs, for a day, a week or a month. Download it as CSV.', 'axle-ai-guard' ),
			'budgets'  => __( 'Set how many calls, tokens and how much money each plugin may use, and decide which plugins may call AI at all.', 'axle-ai-guard' ),
			'rules'    => __( 'Choose what happens when a call is about to send personal data or text that matches your own rules.', 'axle-ai-guard' ),
			'prices'   => __( 'The price table turns tokens into estimated costs. Edit it when your provider changes its prices.', 'axle-ai-guard' ),
			'settings' => __( 'How new plugins are handled, how long data is kept, and where alerts go.', 'axle-ai-guard' ),
		);

		echo '<div class="wrap axle-ui aag-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'AI Guard', 'axle-ai-guard' ) . '</h1>';
		if ( $this->kill->global_on() ) {
			echo ' <span class="axle-status axle-badge axle-badge--error">' . esc_html__( 'AI is off', 'axle-ai-guard' ) . '</span>';
		} elseif ( $this->kill->any_on() ) {
			echo ' <span class="axle-status axle-badge axle-badge--warning">' . esc_html__( 'AI partly off', 'axle-ai-guard' ) . '</span>';
		} else {
			echo ' <span class="axle-status axle-badge axle-badge--good">' . esc_html__( 'Guarding', 'axle-ai-guard' ) . '</span>';
		}
		echo '<hr class="wp-header-end">';
		$this->done_notice();

		echo '<nav class="nav-tab-wrapper aag-tabs" aria-label="' . esc_attr__( 'AI Guard sections', 'axle-ai-guard' ) . '">';
		foreach ( $labels as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s"%s>%s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				$key === $tab ? ' aria-current="page"' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<p class="axle-intro">' . esc_html( $intros[ $tab ] ) . '</p>';

		$method = 'render_' . $tab;
		$this->$method();
		AxleUi::footer();
		echo '</div>';
	}

	/**
	 * The message after a form was handled, with Undo when there is one.
	 */
	private function done_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only; Undo is a form with its own nonce.
		$done    = isset( $_GET['done'] ) ? sanitize_key( wp_unslash( $_GET['done'] ) ) : '';
		$scope   = isset( $_GET['k_scope'] ) ? sanitize_key( wp_unslash( $_GET['k_scope'] ) ) : '';
		$subject = isset( $_GET['k_subject'] ) ? sanitize_text_field( wp_unslash( $_GET['k_subject'] ) ) : '';
		$caller  = isset( $_GET['caller'] ) ? sanitize_text_field( wp_unslash( $_GET['caller'] ) ) : '';
		$prev    = isset( $_GET['prev'] ) ? sanitize_key( wp_unslash( $_GET['prev'] ) ) : '';
		$rows    = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0;
		$changed = isset( $_GET['c'] ) ? absint( $_GET['c'] ) : 0;
		$delta   = isset( $_GET['d'] ) ? (int) $_GET['d'] : 0;
		$next    = isset( $_GET['next'] ) ? absint( $_GET['next'] ) : 0;
		// phpcs:enable

		$map = array(
			'kill_on'              => __( 'Switched off. Calls in that scope are blocked now.', 'axle-ai-guard' ),
			'kill_off'             => __( 'Switched back on.', 'axle-ai-guard' ),
			'kill_same'            => __( 'Nothing changed: the switch was already in that state.', 'axle-ai-guard' ),
			'budgets'              => __( 'Budgets and limits saved.', 'axle-ai-guard' ),
			'callers'              => __( 'Caller updated.', 'axle-ai-guard' ),
			'rules'                => __( 'Data rules saved.', 'axle-ai-guard' ),
			'prices'               => __( 'Prices saved.', 'axle-ai-guard' ),
			'prices_reset'         => __( 'Prices are back to the shipped table.', 'axle-ai-guard' ),
			'prices_undone'        => __( 'The last price change was undone.', 'axle-ai-guard' ),
			'prices_imported'      => __( 'Prices imported. Models with the same key now have the imported price.', 'axle-ai-guard' ),
			'recalc'               => __( 'Costs of the last 30 days were recalculated with the current prices.', 'axle-ai-guard' ),
			'settings'             => __( 'Settings saved.', 'axle-ai-guard' ),
			'steps'                => __( 'The Get started card is hidden.', 'axle-ai-guard' ),
			'prices_import_failed' => '',
			'runaway_resumed'      => __( 'AI calls resumed.', 'axle-ai-guard' ),
			'runaway_raised'       => __( 'AI calls resumed, and the limit for this plugin is higher now.', 'axle-ai-guard' ),
			'runaway_closed'       => __( 'Warning dismissed.', 'axle-ai-guard' ),
			'runaway_same'         => __( 'Nothing changed: that warning was already dealt with.', 'axle-ai-guard' ),
		);

		$tone = 'success';
		$text = $map[ $done ] ?? '';
		if ( 'recalc_preview' === $done ) {
			$tone = 'info';
			$text = sprintf(
				/* translators: 1: calls that would change, 2: calls checked, 3: change in cost. */
				__( 'Preview: recalculating would change %1$s of %2$s calls, by %3$s in total. Nothing was changed.', 'axle-ai-guard' ),
				number_format_i18n( $changed ),
				number_format_i18n( $rows ),
				$this->money( abs( $delta ) )
			);
		}
		if ( 'recalc' === $done && $next > 0 ) {
			$tone = 'warning';
			$text = sprintf(
				/* translators: %s: calls recalculated so far. */
				__( 'Recalculated %s calls so far. There are more: continue below.', 'axle-ai-guard' ),
				number_format_i18n( $rows )
			);
		}
		if ( 'prices_import_failed' === $done ) {
			$tone = 'error';
			$text = (string) get_transient( 'axle_ai_guard_import_error_' . get_current_user_id() );
			$text = '' !== $text ? $text : __( 'The import failed. Check the JSON and try again.', 'axle-ai-guard' );
		}
		if ( '' === $text ) {
			return;
		}

		echo '<div class="notice notice-' . esc_attr( $tone ) . ' is-dismissible aag-done" role="status"><p>' . esc_html( $text );

		$undo = array();
		if ( 'kill_on' === $done && '' !== $scope ) {
			$undo = array(
				'action' => 'kill',
				'fields' => array(
					'scope'   => $scope,
					'subject' => $subject,
					'on'      => '0',
				),
				'label'  => __( 'Undo: switch back on', 'axle-ai-guard' ),
			);
		} elseif ( 'kill_off' === $done && '' !== $scope ) {
			$undo = array(
				'action' => 'kill',
				'fields' => array(
					'scope'   => $scope,
					'subject' => $subject,
					'on'      => '1',
				),
				'label'  => __( 'Undo: switch off again', 'axle-ai-guard' ),
			);
		} elseif ( 'callers' === $done && '' !== $caller && in_array( $prev, array( 'approved', 'denied' ), true ) ) {
			$undo = array(
				'action' => 'callers',
				'fields' => array(
					'caller' => $caller,
					'status' => $prev,
				),
				'label'  => __( 'Undo', 'axle-ai-guard' ),
			);
		} elseif ( in_array( $done, array( 'prices', 'prices_reset', 'prices_imported' ), true ) && $this->prices->can_undo() ) {
			$undo = array(
				'action' => 'prices',
				'fields' => array( 'do' => 'undo' ),
				'label'  => __( 'Undo', 'axle-ai-guard' ),
			);
		} elseif ( in_array( $done, array( 'runaway_resumed', 'runaway_raised' ), true ) && '' !== $caller ) {
			$undo = array(
				'action' => 'kill',
				'fields' => array(
					'scope'   => 'plugin',
					'subject' => $caller,
					'on'      => '1',
					'reason'  => __( 'Paused again after a resume.', 'axle-ai-guard' ),
				),
				'label'  => __( 'Undo: pause again', 'axle-ai-guard' ),
			);
		}

		if ( $undo ) {
			echo ' <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form">';
			self::form_fields( $undo['action'] );
			foreach ( $undo['fields'] as $name => $value ) {
				echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '">';
			}
			echo '<button type="submit" class="button-link">' . esc_html( $undo['label'] ) . '</button></form>';
		}
		echo '</p></div>';
	}

	/**
	 * The overview cards, rendered by the script.
	 *
	 * @return array<int, array{id: string, label: string, value: int, kind: string}>
	 */
	private function cards(): array {
		$cards = array();
		foreach ( array( 'day', 'month' ) as $period ) {
			$totals = $this->reports->totals( $period );
			$when   = 'day' === $period ? __( 'today', 'axle-ai-guard' ) : __( 'this month', 'axle-ai-guard' );
			$cards  = array_merge(
				$cards,
				array(
					/* translators: %s: today or this month. */
					array( 'id' => $period . '-calls', 'label' => sprintf( __( 'Calls %s', 'axle-ai-guard' ), $when ), 'value' => $totals['calls'], 'kind' => 'number' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					/* translators: %s: today or this month. */
					array( 'id' => $period . '-tokens', 'label' => sprintf( __( 'Tokens %s', 'axle-ai-guard' ), $when ), 'value' => $totals['tokens'], 'kind' => 'number' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					/* translators: %s: today or this month. */
					array( 'id' => $period . '-cost', 'label' => sprintf( __( 'Estimated cost %s', 'axle-ai-guard' ), $when ), 'value' => $totals['cost'], 'kind' => 'cost' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					/* translators: %s: today or this month. */
					array( 'id' => $period . '-blocked', 'label' => sprintf( __( 'Blocked %s', 'axle-ai-guard' ), $when ), 'value' => $totals['blocked'], 'kind' => 'number' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				)
			);
		}

		return $cards;
	}

	/**
	 * A money amount.
	 *
	 * @param int $micros Micro-units.
	 * @return string
	 */
	private function money( int $micros ): string {
		return Prices::format( $micros, $this->prices->currency() );
	}

	/**
	 * A hidden nonce and action for an admin-post form.
	 *
	 * @param string $action Action without the prefix.
	 */
	private static function form_fields( string $action ): void {
		echo '<input type="hidden" name="action" value="' . esc_attr( 'axle_ai_guard_' . $action ) . '">';
		wp_nonce_field( 'axle_ai_guard_' . $action );
	}

	/**
	 * A kill switch button form.
	 *
	 * @param string $scope   Scope.
	 * @param string $subject Subject.
	 * @param bool   $on      Whether the button turns it on.
	 * @param string $label   Button text.
	 * @param string $css     Button class.
	 */
	private function kill_button( string $scope, string $subject, bool $on, string $label, string $css = 'button' ): void {
		$confirm = '';
		if ( $on ) {
			$confirm = sprintf(
				' data-aag-confirm="1" data-confirm-title="%1$s" data-confirm-body="%2$s" data-confirm-yes="%3$s"',
				esc_attr(
					sprintf(
						/* translators: %s: what is switched off, such as the caller x. */
						__( 'Switch AI off for %s?', 'axle-ai-guard' ),
						KillSwitch::describe( $scope, $subject )
					)
				),
				esc_attr__( 'Its AI calls are blocked at once. Nothing is deleted, and you can turn it back on here. The notice that follows has an Undo link.', 'axle-ai-guard' ),
				esc_attr__( 'Switch off', 'axle-ai-guard' )
			);
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-kill-form"' . $confirm . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts above.
		self::form_fields( 'kill' );
		printf(
			'<input type="hidden" name="scope" value="%1$s"><input type="hidden" name="subject" value="%2$s"><input type="hidden" name="on" value="%3$s"><button type="submit" class="%4$s aag-kill-toggle" data-scope="%1$s" data-subject="%2$s" data-on="%3$s">%5$s</button>',
			esc_attr( $scope ),
			esc_attr( $subject ),
			$on ? '1' : '0',
			esc_attr( $css ),
			esc_html( $label )
		);
		echo '</form>';
	}

	/**
	 * Overview tab.
	 */
	private function render_overview(): void {
		$this->render_steps();
		// The global switch.
		echo '<section class="axle-card' . ( $this->kill->global_on() ? '' : ' axle-card--danger' ) . ' aag-global" aria-labelledby="aag-global-title">';
		echo '<h2 class="axle-card__title" id="aag-global-title">' . esc_html__( 'Kill switch', 'axle-ai-guard' ) . '</h2>';
		if ( $this->kill->global_on() ) {
			echo '<p class="aag-status aag-status-off"><span class="axle-badge axle-badge--error">' . esc_html__( 'Switched off', 'axle-ai-guard' ) . '</span> ' . esc_html__( 'All AI calls on this site are switched off.', 'axle-ai-guard' ) . '</p>';
			if ( KillSwitch::constant_on() || KillSwitch::network() ) {
				echo '<p>' . esc_html__( 'The AXLE_AI_GUARD_KILL constant or the network switch keeps it off. Change it there.', 'axle-ai-guard' ) . '</p>';
			}
			if ( $this->kill->state()['global'] ) {
				$this->kill_button( 'global', '', false, __( 'Turn AI back on', 'axle-ai-guard' ), 'button button-primary' );
			}
		} else {
			echo '<p class="aag-status"><span class="axle-badge axle-badge--good">' . esc_html__( 'Allowed', 'axle-ai-guard' ) . '</span> ' . esc_html__( 'AI calls are allowed, within your budgets and data rules.', 'axle-ai-guard' ) . '</p>';
			echo '<p>' . esc_html__( 'Switching AI off blocks every AI call on this site until you turn it back on. Nothing is deleted, and you can undo it here at any time.', 'axle-ai-guard' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form" data-aag-confirm="1" data-confirm-title="' . esc_attr__( 'Switch all AI off?', 'axle-ai-guard' ) . '" data-confirm-body="' . esc_attr__( 'Every AI call on this site is blocked at once, from every plugin. Nothing is deleted. You can turn it back on from this screen, and the notice that follows has an Undo link.', 'axle-ai-guard' ) . '" data-confirm-yes="' . esc_attr__( 'Switch all AI off', 'axle-ai-guard' ) . '">';
			self::form_fields( 'kill' );
			echo '<input type="hidden" name="scope" value="global"><input type="hidden" name="on" value="1">';
			echo '<label for="aag-kill-reason">' . esc_html__( 'Reason', 'axle-ai-guard' ) . '</label> ';
			echo '<input type="text" id="aag-kill-reason" name="reason" class="regular-text"> ';
			echo '<button type="submit" class="button axle-button--danger aag-danger">' . esc_html__( 'Switch all AI off', 'axle-ai-guard' ) . '</button>';
			echo '</form>';
		}
		echo '</section>';

		echo '<section class="aag-section" aria-labelledby="aag-cards-title"><h2 id="aag-cards-title">' . esc_html__( 'Usage', 'axle-ai-guard' ) . '</h2>';
		echo '<div class="axle-stats" data-aag-cards></div>';
		echo '<p class="axle-muted">' . esc_html(
			sprintf(
				/* translators: %s: date. */
				__( 'Costs are estimates from the price table (prices as of %s), not your provider bill.', 'axle-ai-guard' ),
				$this->prices->as_of()
			)
		) . '</p></section>';

		$this->render_runaway();
		$this->render_callers_table();
		$this->render_breakdown();
		$this->render_switches();
		$this->render_recent_blocks();
	}

	/**
	 * Per caller: usage this month, budget bar, last call, status and kill toggle.
	 */
	private function render_callers_table(): void {
		$month   = $this->reports->usage( 'month', 'plugin' );
		$by_name = array();
		foreach ( $month as $row ) {
			$by_name[ $row['name'] ] = $row;
		}
		$last = array();
		foreach ( $this->calls->breakdown( 'caller_slug', gmdate( 'Y-m-d H:i:s', time() - 90 * DAY_IN_SECONDS ) ) as $row ) {
			$last[ (string) $row['name'] ] = (string) $row['last_call'];
		}
		$names = array_unique( array_merge( array_keys( $this->settings->callers() ), array_keys( $by_name ) ) );
		sort( $names );

		echo '<section class="axle-card" aria-labelledby="aag-callers-title"><h2 class="axle-card__title" id="aag-callers-title">' . esc_html__( 'Plugins making AI calls this month', 'axle-ai-guard' ) . '</h2>';
		if ( ! $names ) {
			echo '<div class="axle-empty aag-empty"><p>' . esc_html__( 'No AI calls yet. Plugins show up here after their first call, with their usage and a switch to turn them off.', 'axle-ai-guard' ) . '</p></div></section>';
			return;
		}
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Plugins making AI calls', 'axle-ai-guard' ) . '"><table class="widefat striped aag-table aag-callers"><thead><tr>';
		$hourly = array();
		foreach ( $this->runaway->callers() as $entry ) {
			$hourly[ (string) $entry['caller'] ] = $entry;
		}
		foreach ( array( __( 'Caller', 'axle-ai-guard' ), __( 'Calls', 'axle-ai-guard' ), __( 'This hour', 'axle-ai-guard' ), __( 'Tokens', 'axle-ai-guard' ), __( 'Cost', 'axle-ai-guard' ), __( 'Monthly budget', 'axle-ai-guard' ), __( 'Last call', 'axle-ai-guard' ), __( 'Status', 'axle-ai-guard' ), __( 'Kill switch', 'axle-ai-guard' ) ) as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		foreach ( $names as $name ) {
			$row    = $by_name[ $name ] ?? array(
				'calls'   => 0,
				'tokens'  => 0,
				'cost'    => 0,
				'blocked' => 0,
			);
			$killed = $this->kill->is_on( 'plugin', $name );
			$status = $this->settings->caller_status( $name );

			echo '<tr data-caller="' . esc_attr( $name ) . '">';
			echo '<th scope="row"><code>' . esc_html( $name ) . '</code></th>';
			echo '<td>' . esc_html( number_format_i18n( $row['calls'] ) ) . ( $row['blocked'] ? ' <span class="aag-muted">' . esc_html( sprintf( /* translators: %s: number of blocked calls. */ __( '(%s blocked)', 'axle-ai-guard' ), number_format_i18n( $row['blocked'] ) ) ) . '</span>' : '' ) . '</td>';
			$hour = $hourly[ $name ] ?? null;
			echo '<td>' . ( $hour ? esc_html(
				sprintf(
					/* translators: 1: calls this hour, 2: the limit per hour. */
					__( '%1$s of %2$s', 'axle-ai-guard' ),
					number_format_i18n( (int) $hour['calls_hour'] ),
					number_format_i18n( (int) $hour['calls_limit'] )
				)
			) : '-' ) . '</td>';
			echo '<td>' . esc_html( number_format_i18n( $row['tokens'] ) ) . '</td>';
			echo '<td>' . esc_html( $this->money( $row['cost'] ) ) . '</td>';
			echo '<td>';
			$this->budget_bar( 'plugin:' . $name );
			echo '</td>';
			echo '<td>' . wp_kses_post( isset( $last[ $name ] ) ? self::when( $last[ $name ] ) : AxleUi::relative_time( 0, '-' ) ) . '</td>';
			echo '<td>' . ( $killed ? '<span class="axle-badge axle-badge--error">' . esc_html__( 'Switched off', 'axle-ai-guard' ) . '</span>' : self::status_badge( $status ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_badge().
			echo '<td>';
			$this->kill_button( 'plugin', $name, ! $killed, $killed ? __( 'Turn on', 'axle-ai-guard' ) : __( 'Switch off', 'axle-ai-guard' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * A caller's approval status in words.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$map = array(
			'approved' => __( 'Allowed', 'axle-ai-guard' ),
			'pending'  => __( 'Waiting for approval', 'axle-ai-guard' ),
			'denied'   => __( 'Denied', 'axle-ai-guard' ),
		);

		return $map[ $status ] ?? __( 'Allowed', 'axle-ai-guard' );
	}

	/**
	 * A caller's approval status as a badge.
	 *
	 * @param string $status Status.
	 * @return string HTML.
	 */
	private static function status_badge( string $status ): string {
		$tones = array(
			'pending' => 'warning',
			'denied'  => 'error',
		);

		return '<span class="axle-badge axle-badge--' . esc_attr( $tones[ $status ] ?? 'good' ) . '">' . esc_html( self::status_label( $status ) ) . '</span>';
	}

	/**
	 * A UTC datetime as relative time. Returns escaped HTML.
	 *
	 * @param string $utc UTC datetime.
	 * @return string
	 */
	private static function when( string $utc ): string {
		return AxleUi::relative_time( (int) strtotime( $utc . ' UTC' ), '-' );
	}

	/**
	 * The monthly budget bar of a subject: money first, else tokens.
	 *
	 * @param string $subject Subject.
	 */
	private function budget_bar( string $subject ): void {
		$budget = $this->policy->budget( $subject );
		$used   = $this->policy->used( $subject );
		$key    = $budget['monthly_cost'] > 0 ? 'monthly_cost' : ( $budget['monthly_tokens'] > 0 ? 'monthly_tokens' : '' );
		if ( '' === $key ) {
			echo '<span class="axle-muted">' . esc_html__( 'No budget', 'axle-ai-guard' ) . '</span>';
			return;
		}

		$money = 'monthly_cost' === $key;
		$text  = $money
			? sprintf( '%s / %s', $this->money( $used[ $key ] ), $this->money( $budget[ $key ] ) )
			: sprintf( '%s / %s', number_format_i18n( $used[ $key ] ), number_format_i18n( $budget[ $key ] ) );
		$pct   = (int) min( 100, round( $used[ $key ] / max( 1, $budget[ $key ] ) * 100 ) );

		$full = $pct >= 100;
		$soft = ! $full && $pct >= (int) $this->settings->get( 'soft_ratio' );
		$word = $full ? '<span class="axle-badge axle-badge--error">' . esc_html__( 'Limit reached', 'axle-ai-guard' ) . '</span>' : ( $soft ? '<span class="axle-badge axle-badge--warning">' . esc_html__( 'Near the limit', 'axle-ai-guard' ) . '</span>' : '' );

		printf(
			'<span class="aag-bar %6$s"><span class="axle-progress axle-progress--%1$s" role="progressbar" aria-valuenow="%2$d" aria-valuemin="0" aria-valuemax="100" aria-label="%3$s"><span class="axle-progress__bar" style="width:%2$d%%"></span></span> <span>%4$s</span> %5$s</span>',
			$full ? 'error' : ( $soft ? 'warning' : 'good' ),
			(int) $pct,
			/* translators: 1: subject, 2: percentage. */
			esc_attr( sprintf( __( 'Budget of %1$s: %2$d%% used', 'axle-ai-guard' ), $subject, $pct ) ),
			esc_html( $text ),
			$word, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped strings above.
			$full ? 'aag-bar-full' : ( $soft ? 'aag-bar-soft' : '' )
		);
	}

	/**
	 * Per provider and model, this month.
	 */
	private function render_breakdown(): void {
		$rows = $this->calls->breakdown( 'model', gmdate( 'Y-m-01 00:00:00' ) );
		echo '<section class="axle-card" aria-labelledby="aag-models-title"><h2 class="axle-card__title" id="aag-models-title">' . esc_html__( 'Providers and models this month', 'axle-ai-guard' ) . '</h2>';
		$providers = $this->reports->usage( 'month', 'provider' );
		if ( ! $providers && ! $rows ) {
			echo '<div class="axle-empty aag-empty"><p>' . esc_html__( 'No provider has been called this month. Usage per provider and model shows up here after the first call.', 'axle-ai-guard' ) . '</p></div></section>';
			return;
		}

		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Providers and models', 'axle-ai-guard' ) . '"><table class="widefat striped aag-table aag-models"><thead><tr><th scope="col">' . esc_html__( 'Provider', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Calls', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Tokens', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Cost', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Kill switch', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $providers as $row ) {
			$killed = $this->kill->is_on( 'provider', $row['name'] );
			echo '<tr><th scope="row"><code>' . esc_html( $row['name'] ) . '</code></th><td>' . esc_html( number_format_i18n( $row['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['tokens'] ) ) . '</td><td>' . esc_html( $this->money( $row['cost'] ) ) . '</td><td>';
			$this->kill_button( 'provider', $row['name'], ! $killed, $killed ? __( 'Turn on', 'axle-ai-guard' ) : __( 'Switch off', 'axle-ai-guard' ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<table class="widefat striped aag-table aag-models"><thead><tr><th scope="col">' . esc_html__( 'Model', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Calls', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Tokens', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Cost', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><th scope="row"><code>' . esc_html( '' !== (string) $row['name'] ? (string) $row['name'] : '-' ) . '</code></th><td>' . esc_html( number_format_i18n( (int) $row['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $row['tokens'] ) ) . '</td><td>' . esc_html( $this->money( (int) $row['cost'] ) ) . '</td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Switches that are on, and a form to switch off one provider or context.
	 */
	private function render_switches(): void {
		echo '<section class="axle-card" aria-labelledby="aag-switches-title"><h2 class="axle-card__title" id="aag-switches-title">' . esc_html__( 'Kill switches that are on', 'axle-ai-guard' ) . '</h2>';
		$active = $this->kill->active();
		if ( ! $active ) {
			echo '<p class="axle-muted">' . esc_html__( 'None. Every provider and context is allowed.', 'axle-ai-guard' ) . '</p>';
		} else {
			echo '<ul class="aag-switches">';
			foreach ( $active as $entry ) {
				$user = $entry['by'] ? get_userdata( $entry['by'] ) : null;
				echo '<li><strong>' . esc_html( ucfirst( KillSwitch::describe( $entry['scope'], $entry['subject'] ) ) ) . '</strong> ';
				echo '<span class="axle-muted">' . esc_html(
					trim(
						( '' !== $entry['reason'] ? $entry['reason'] . '. ' : '' ) .
						( $entry['at'] ? sprintf( /* translators: %s: who. */ __( 'By %s.', 'axle-ai-guard' ), $user ? $user->display_name : __( 'AI Guard', 'axle-ai-guard' ) ) : '' )
					)
				) . ' ' . wp_kses_post( $entry['at'] ? AxleUi::relative_time( (int) $entry['at'] ) : '' ) . '</span> ';
				if ( 'site' === $entry['source'] ) {
					$this->kill_button( $entry['scope'], $entry['subject'], false, __( 'Turn on', 'axle-ai-guard' ) );
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form aag-context-kill" data-aag-confirm="1" data-confirm-title="' . esc_attr__( 'Switch AI off in this context?', 'axle-ai-guard' ) . '" data-confirm-body="' . esc_attr__( 'Calls from that context are blocked at once. Nothing is deleted, and you can turn it back on here.', 'axle-ai-guard' ) . '" data-confirm-yes="' . esc_attr__( 'Switch off', 'axle-ai-guard' ) . '">';
		self::form_fields( 'kill' );
		echo '<input type="hidden" name="scope" value="context"><input type="hidden" name="on" value="1">';
		echo '<label for="aag-kill-context">' . esc_html__( 'Switch AI off in one context', 'axle-ai-guard' ) . '</label> <select id="aag-kill-context" name="subject">';
		foreach ( self::context_labels() as $context => $label ) {
			echo '<option value="' . esc_attr( $context ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select> <button type="submit" class="button axle-button--danger">' . esc_html__( 'Switch off', 'axle-ai-guard' ) . '</button></form>';
		echo '<p class="axle-muted">' . esc_html__( 'Calls from that context are blocked until you turn it back on. You can undo it here.', 'axle-ai-guard' ) . '</p>';
		echo '</section>';
	}

	/**
	 * Contexts in words.
	 *
	 * @return array<string, string>
	 */
	private static function context_labels(): array {
		return array(
			'front' => __( 'Front end', 'axle-ai-guard' ),
			'admin' => __( 'wp-admin', 'axle-ai-guard' ),
			'rest'  => __( 'REST API', 'axle-ai-guard' ),
			'ajax'  => __( 'Ajax', 'axle-ai-guard' ),
			'cron'  => __( 'Cron', 'axle-ai-guard' ),
			'cli'   => __( 'WP-CLI', 'axle-ai-guard' ),
		);
	}

	/**
	 * The last blocked calls.
	 */
	private function render_recent_blocks(): void {
		$rows = $this->calls->query(
			array(
				'status'   => 'blocked',
				'per_page' => 10,
			)
		)['rows'];

		echo '<section class="axle-card" aria-labelledby="aag-blocks-title"><h2 class="axle-card__title" id="aag-blocks-title">' . esc_html__( 'Recent blocks', 'axle-ai-guard' ) . '</h2>';
		if ( ! $rows ) {
			echo '<div class="axle-empty aag-empty"><p>' . esc_html__( 'No calls were blocked. A call is blocked by a budget, a data rule or a kill switch, and shows up here.', 'axle-ai-guard' ) . '</p></div></section>';
			return;
		}
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Recent blocks', 'axle-ai-guard' ) . '"><table class="widefat striped aag-table aag-blocks"><thead><tr><th scope="col">' . esc_html__( 'When', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Caller', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Rule', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Context', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . wp_kses_post( self::when( (string) $row['started_at'] ) ) . '</td><td><code>' . esc_html( (string) $row['caller_slug'] ) . '</code></td><td><code>' . esc_html( (string) $row['block_rule'] ) . '</code></td><td>' . esc_html( (string) $row['context'] ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '<p><a href="' . esc_url( self::url( 'calls', array( 'status' => 'blocked' ) ) ) . '">' . esc_html__( 'All blocked calls', 'axle-ai-guard' ) . '</a></p></section>';
	}

	/**
	 * The Calls filters from the URL.
	 *
	 * @return array<string, mixed>
	 */
	private function call_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only filters.
		$get     = static function ( string $key ): string {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};
		$filters = array(
			'status'   => in_array( $get( 'status' ), Calls::STATUSES, true ) ? $get( 'status' ) : '',
			'caller'   => $get( 'plugin' ),
			'provider' => $get( 'provider' ),
			'model'    => $get( 'model' ),
			'context'  => in_array( $get( 'context' ), Settings::CONTEXTS, true ) ? $get( 'context' ) : '',
			'user_id'  => absint( $get( 'user' ) ),
			'date'     => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $get( 'date' ) ) ? $get( 'date' ) : '',
			'page'     => max( 1, absint( $get( 'paged' ) ) ),
		);
		// phpcs:enable

		return $filters;
	}

	/**
	 * The calls for the current filters.
	 *
	 * @return array{rows: array, total: int}
	 */
	private function calls_query(): array {
		$filters = $this->call_filters();
		$args    = $filters;
		if ( '' !== $filters['date'] ) {
			$args['since'] = $filters['date'] . ' 00:00:00';
			$args['until'] = $filters['date'] . ' 23:59:59';
		}
		$args['per_page'] = 50;

		return $this->calls->query( $args );
	}

	/**
	 * Calls tab: filters, the table (rendered by the script) and the detail dialog.
	 */
	private function render_calls(): void {
		$filters = $this->call_filters();
		$result  = $this->calls_query();

		echo '<form method="get" class="aag-filters axle-toolbar" aria-label="' . esc_attr__( 'Filter calls', 'axle-ai-guard' ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="calls">';
		$selects = array(
			'status'   => array( __( 'Status', 'axle-ai-guard' ), array_combine( Calls::STATUSES, Calls::STATUSES ), $filters['status'] ),
			'plugin'   => array( __( 'Caller', 'axle-ai-guard' ), self::pairs( $this->calls->distinct( 'caller_slug' ) ), $filters['caller'] ),
			'provider' => array( __( 'Provider', 'axle-ai-guard' ), self::pairs( $this->calls->distinct( 'provider' ) ), $filters['provider'] ),
			'model'    => array( __( 'Model', 'axle-ai-guard' ), self::pairs( $this->calls->distinct( 'model' ) ), $filters['model'] ),
			'context'  => array( __( 'Context', 'axle-ai-guard' ), self::context_labels(), $filters['context'] ),
		);
		foreach ( $selects as $name => $select ) {
			echo '<span class="aag-filter"><label for="aag-filter-' . esc_attr( $name ) . '">' . esc_html( $select[0] ) . '</label> <select id="aag-filter-' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( 'All', 'axle-ai-guard' ) . '</option>';
			foreach ( $select[1] as $value => $label ) {
				echo '<option value="' . esc_attr( (string) $value ) . '"' . selected( (string) $value, $select[2], false ) . '>' . esc_html( (string) $label ) . '</option>';
			}
			echo '</select></span> ';
		}
		echo '<span class="aag-filter"><label for="aag-filter-user">' . esc_html__( 'User ID', 'axle-ai-guard' ) . '</label> <input type="number" min="0" id="aag-filter-user" name="user" value="' . esc_attr( $filters['user_id'] ? (string) $filters['user_id'] : '' ) . '" class="small-text"></span> ';
		echo '<span class="aag-filter"><label for="aag-filter-date">' . esc_html__( 'Date', 'axle-ai-guard' ) . '</label> <input type="date" id="aag-filter-date" name="date" value="' . esc_attr( $filters['date'] ) . '"></span> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'axle-ai-guard' ) . '</button></form>';

		echo '<p class="aag-count axle-muted">' . esc_html( sprintf( /* translators: %s: number of calls. */ _n( '%s call', '%s calls', $result['total'], 'axle-ai-guard' ), number_format_i18n( $result['total'] ) ) ) . '</p>';
		echo '<div class="axle-card aag-calls" data-aag-calls aria-busy="true"><p class="axle-muted"><span class="spinner is-active aag-spinner"></span> ' . esc_html__( 'Loading calls', 'axle-ai-guard' ) . '</p></div>';

		$pages = (int) ceil( $result['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<nav class="aag-pages" aria-label="' . esc_attr__( 'Pages', 'axle-ai-guard' ) . '">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $filters['page'],
						'total'   => $pages,
					)
				)
			);
			echo '</nav>';
		}

		echo '<dialog class="aag-modal axle-ui" aria-labelledby="aag-modal-title"><div class="aag-modal-inner"><h2 class="axle-card__title" id="aag-modal-title">' . esc_html__( 'Call details', 'axle-ai-guard' ) . '</h2><div class="aag-modal-body" data-aag-detail></div><p><button type="button" class="button aag-modal-close">' . esc_html__( 'Close', 'axle-ai-guard' ) . '</button></p></div></dialog>';
	}

	/**
	 * A list as value => label pairs.
	 *
	 * @param string[] $values Values.
	 * @return array<string, string>
	 */
	private static function pairs( array $values ): array {
		return $values ? array_combine( $values, $values ) : array();
	}

	/**
	 * A money field value from micro-units.
	 *
	 * @param int $micros Micro-units.
	 * @return string
	 */
	private static function money_field( int $micros ): string {
		return $micros > 0 ? rtrim( rtrim( number_format( $micros / 1000000, 6, '.', '' ), '0' ), '.' ) : '';
	}

	/**
	 * Users and roles tab: usage per person and per role, with CSV download.
	 */
	private function render_people(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter.
		$wanted  = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'month';
		$periods = UserReport::periods();
		$period  = UserReport::period_key( $wanted );
		$report  = $this->reports->user_usage( $period );

		echo '<form method="get" class="aag-filters axle-toolbar" aria-label="' . esc_attr__( 'Choose a period', 'axle-ai-guard' ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="people">';
		echo '<span class="aag-filter"><label for="aag-period">' . esc_html__( 'Period', 'axle-ai-guard' ) . '</label> <select id="aag-period" name="period">';
		foreach ( $periods as $key => $item ) {
			echo '<option value="' . esc_attr( $key ) . '"' . selected( $key, $period, false ) . '>' . esc_html( $item['label'] ) . '</option>';
		}
		echo '</select></span> <button type="submit" class="button">' . esc_html__( 'Show', 'axle-ai-guard' ) . '</button></form>';

		echo '<p class="aag-count axle-muted">' . esc_html(
			sprintf(
				/* translators: 1: period name, 2: first day, 3: last day (UTC). */
				__( '%1$s: %2$s to %3$s (UTC).', 'axle-ai-guard' ),
				$report['label'],
				substr( $report['since'], 0, 10 ),
				gmdate( 'Y-m-d', (int) strtotime( $report['until'] . ' UTC' ) - 1 )
			)
		) . '</p>';

		if ( ! $report['users'] ) {
			echo '<section class="axle-card"><div class="axle-empty aag-empty"><p>' . esc_html__( 'No AI calls in this period. When a plugin calls AI, the person who triggered it shows up here with their calls, tokens and cost. Pick a longer period to look further back.', 'axle-ai-guard' ) . '</p></div></section>';
			return;
		}

		if ( $this->reports->unpriced_models() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Some models have no price, so their calls count as a cost of zero here.', 'axle-ai-guard' ) . ' <a href="' . esc_url( self::url( 'prices' ) ) . '">' . esc_html__( 'Add the prices', 'axle-ai-guard' ) . '</a></p></div>';
		}
		if ( $report['truncated'] ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'This site has more users than the report reads at once, so the quietest ones are left out. Pick a shorter period.', 'axle-ai-guard' ) . '</p></div>';
		}

		$total = $report['totals'];
		echo '<p class="aag-people-total"><strong>' . esc_html__( 'Total', 'axle-ai-guard' ) . ':</strong> ' . esc_html(
			sprintf(
				/* translators: 1: calls, 2: blocked calls, 3: tokens, 4: cost. */
				__( '%1$s calls, %2$s blocked, %3$s tokens, %4$s', 'axle-ai-guard' ),
				number_format_i18n( $total['calls'] ),
				number_format_i18n( $total['blocked'] ),
				number_format_i18n( $total['tokens'] ),
				implode( ' + ', UserReport::money_list( $total['costs'], $report['currency'] ) )
			)
		) . '</p>';

		$headings = static function ( string $first ): string {
			return '<th scope="col">' . esc_html( $first ) . '</th><th scope="col">' . esc_html__( 'Calls', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Blocked', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Tokens', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Cost', 'axle-ai-guard' ) . '</th>';
		};
		$cost     = static function ( array $costs ) use ( $report ): string {
			return implode( '<br>', array_map( 'esc_html', UserReport::money_list( $costs, $report['currency'] ) ) );
		};

		echo '<section class="axle-card" aria-labelledby="aag-roles-title"><h2 class="axle-card__title" id="aag-roles-title">' . esc_html__( 'By role', 'axle-ai-guard' ) . '</h2>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Usage by role', 'axle-ai-guard' ) . '"><table class="widefat striped aag-table aag-people-roles"><thead><tr>' . $headings( __( 'Role', 'axle-ai-guard' ) ) . '<th scope="col">' . esc_html__( 'Users', 'axle-ai-guard' ) . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		foreach ( $report['roles'] as $row ) {
			echo '<tr data-role="' . esc_attr( $row['role'] ) . '"><th scope="row">' . esc_html( $row['label'] ) . '</th><td>' . esc_html( number_format_i18n( $row['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['blocked'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['tokens'] ) ) . '</td><td>' . $cost( $row['costs'] ) . '</td><td>' . esc_html( number_format_i18n( $row['users'] ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by $cost().
		}
		echo '</tbody></table></div></section>';

		echo '<section class="axle-card" aria-labelledby="aag-users-title"><h2 class="axle-card__title" id="aag-users-title">' . esc_html__( 'By user', 'axle-ai-guard' ) . '</h2>';
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Usage by user', 'axle-ai-guard' ) . '"><table class="widefat striped aag-table aag-people-users"><thead><tr>' . $headings( __( 'User', 'axle-ai-guard' ) ) . '<th scope="col">' . esc_html__( 'Role', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Last call', 'axle-ai-guard' ) . '</th></tr></thead><tbody>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
		foreach ( $report['users'] as $row ) {
			$who = esc_html( $row['name'] );
			if ( '' !== $row['login'] && $row['login'] !== $row['name'] ) {
				$who .= ' <span class="axle-muted">(' . esc_html( $row['login'] ) . ')</span>';
			}
			echo '<tr data-user="' . esc_attr( (string) $row['user_id'] ) . '"><th scope="row">' . $who . '</th><td>' . esc_html( number_format_i18n( $row['calls'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['blocked'] ) ) . '</td><td>' . esc_html( number_format_i18n( $row['tokens'] ) ) . '</td><td>' . $cost( $row['costs'] ) . '</td><td>' . esc_html( UserReport::role_label( $row['role'] ) ) . '</td><td>' . self::when( (string) $row['last_call'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above and by $cost() and when().
		}
		echo '</tbody></table></div></section>';

		echo '<section class="axle-card" aria-labelledby="aag-csv-title"><h2 class="axle-card__title" id="aag-csv-title">' . esc_html__( 'Download', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A CSV file for a spreadsheet, for the period above. It has login names and no email addresses.', 'axle-ai-guard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="axle-actions">';
		self::form_fields( 'people_csv' );
		echo '<input type="hidden" name="period" value="' . esc_attr( $period ) . '">';
		echo '<button type="submit" name="by" value="user" class="button">' . esc_html__( 'Download users as CSV', 'axle-ai-guard' ) . '</button> ';
		echo '<button type="submit" name="by" value="role" class="button">' . esc_html__( 'Download roles as CSV', 'axle-ai-guard' ) . '</button>';
		echo '</form></section>';
	}

	/**
	 * Budgets tab.
	 */
	private function render_budgets(): void {
		$all = $this->settings->all();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-budgets">';
		self::form_fields( 'budgets' );

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Rate limits', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Calls per minute. 0 turns a limit off.', 'axle-ai-guard' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody>';
		$rates = array(
			'rate_global' => __( 'Whole site', 'axle-ai-guard' ),
			'rate_plugin' => __( 'Per plugin (default)', 'axle-ai-guard' ),
			'rate_user'   => __( 'Per logged-in user', 'axle-ai-guard' ),
			'rate_ip'     => __( 'Per visitor IP address, not logged in', 'axle-ai-guard' ),
		);
		foreach ( $rates as $key => $label ) {
			printf(
				'<tr><th scope="row"><label for="aag-%1$s">%2$s</label></th><td><input type="number" min="0" id="aag-%1$s" name="settings[%1$s]" value="%3$d" class="small-text"></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				(int) $all[ $key ]
			);
		}
		printf(
			'<tr><th scope="row"><label for="aag-soft_ratio">%1$s</label></th><td><input type="number" min="1" max="100" id="aag-soft_ratio" name="settings[soft_ratio]" value="%2$d" class="small-text"> %3$s</td></tr>',
			esc_html__( 'Soft limit alert at', 'axle-ai-guard' ),
			(int) $all['soft_ratio'],
			esc_html__( '% of a budget', 'axle-ai-guard' )
		);
		echo '</tbody></table></section>';

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Budgets and rules', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: %s: currency code. */
				__( 'Empty means no limit. Money in %s. A call that is already running can go past a hard limit by its own size.', 'axle-ai-guard' ),
				$this->prices->currency()
			)
		) . '</p>';

		$subjects = array( 'global' );
		foreach ( array_keys( $this->settings->callers() ) as $caller ) {
			$subjects[] = 'plugin:' . $caller;
		}
		foreach ( array_keys( $this->settings->policies() ) as $subject ) {
			$subjects[] = $subject;
		}
		foreach ( array_unique( $subjects ) as $index => $subject ) {
			$this->render_policy( $subject, (int) $index );
		}

		echo '<p><label for="aag-new-subject">' . esc_html__( 'Add a plugin by its folder name', 'axle-ai-guard' ) . '</label> <input type="text" id="aag-new-subject" name="new_subject" class="regular-text"></p>';
		echo '</section>';
		submit_button( __( 'Save budgets and limits', 'axle-ai-guard' ) );
		echo '</form>';

		$this->render_approvals();
	}

	/**
	 * The fieldset of one subject's policy.
	 *
	 * @param string $subject Subject.
	 * @param int    $index   Position, for unique ids.
	 */
	private function render_policy( string $subject, int $index ): void {
		$policy = $this->settings->policy( $subject );
		$id     = 'aag-p' . $index;
		$name   = 'policy[' . $subject . ']';
		$title  = 'global' === $subject ? __( 'Whole site', 'axle-ai-guard' ) : substr( $subject, strpos( $subject, ':' ) + 1 );

		echo '<fieldset class="aag-policy" data-subject="' . esc_attr( $subject ) . '"><legend class="aag-policy-title">' . esc_html( $title ) . '</legend><div class="aag-grid">';
		$fields = array(
			'daily_tokens'   => array( __( 'Tokens per day', 'axle-ai-guard' ), $policy['daily_tokens'] ? (string) $policy['daily_tokens'] : '' ),
			'monthly_tokens' => array( __( 'Tokens per month', 'axle-ai-guard' ), $policy['monthly_tokens'] ? (string) $policy['monthly_tokens'] : '' ),
			'daily_cost'     => array( __( 'Money per day', 'axle-ai-guard' ), self::money_field( $policy['daily_cost'] ) ),
			'monthly_cost'   => array( __( 'Money per month', 'axle-ai-guard' ), self::money_field( $policy['monthly_cost'] ) ),
			'per_minute'     => array( __( 'Calls per minute (empty: default)', 'axle-ai-guard' ), $policy['per_minute'] >= 0 ? (string) $policy['per_minute'] : '' ),
		);
		foreach ( $fields as $key => $field ) {
			printf(
				'<p><label for="%1$s-%2$s">%3$s</label><input type="text" inputmode="decimal" id="%1$s-%2$s" name="%4$s[%2$s]" value="%5$s" class="small-text"></p>',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_html( $field[0] ),
				esc_attr( $name ),
				esc_attr( $field[1] )
			);
		}
		$lists = array(
			'providers' => __( 'Allowed providers (comma separated, empty: all)', 'axle-ai-guard' ),
			'models'    => __( 'Allowed models (comma separated, * at the end matches more, empty: all)', 'axle-ai-guard' ),
			'roles'     => __( 'Allowed roles (comma separated, anonymous for visitors, empty: all)', 'axle-ai-guard' ),
		);
		foreach ( $lists as $key => $label ) {
			printf(
				'<p class="aag-wide"><label for="%1$s-%2$s">%3$s</label><input type="text" id="%1$s-%2$s" name="%4$s[%2$s]" value="%5$s" class="regular-text"></p>',
				esc_attr( $id ),
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( $name ),
				esc_attr( implode( ', ', (array) $policy[ $key ] ) )
			);
		}
		echo '</div><fieldset class="aag-contexts"><legend>' . esc_html__( 'Allowed contexts (none ticked: all)', 'axle-ai-guard' ) . '</legend>';
		foreach ( self::context_labels() as $context => $label ) {
			printf(
				'<label><input type="checkbox" name="%1$s[contexts][]" value="%2$s"%3$s> %4$s</label> ',
				esc_attr( $name ),
				esc_attr( $context ),
				checked( in_array( $context, (array) $policy['contexts'], true ), true, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		printf(
			'<p><label><input type="checkbox" name="%1$s[block_files]" value="1"%2$s> %3$s</label></p>',
			esc_attr( $name ),
			checked( (bool) $policy['block_files'], true, false ),
			esc_html__( 'Block calls that attach files (images, PDFs). Files cannot be scanned for personal data.', 'axle-ai-guard' )
		);
		echo '</fieldset>';
	}

	/**
	 * Callers waiting for approval, and denied ones.
	 */
	private function render_approvals(): void {
		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Callers', 'axle-ai-guard' ) . '</h2>';
		$callers = $this->settings->callers();
		if ( ! $callers ) {
			echo '<div class="axle-empty aag-empty"><p>' . esc_html__( 'No plugin has made an AI call yet. Plugins show up here after their first call, where you can approve or deny them.', 'axle-ai-guard' ) . '</p></div></section>';
			return;
		}
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Callers', 'axle-ai-guard' ) . '"><table class="widefat striped aag-table aag-approvals"><thead><tr><th scope="col">' . esc_html__( 'Caller', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Type', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'First seen in', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Status', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Change', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $callers as $key => $caller ) {
			$status = (string) ( $caller['status'] ?? 'approved' );
			echo '<tr><th scope="row"><code>' . esc_html( (string) $key ) . '</code></th><td>' . esc_html( (string) ( $caller['type'] ?? '' ) ) . '</td><td><code>' . esc_html( (string) ( $caller['file'] ?? '' ) ) . '</code></td><td>' . self::status_badge( $status ) . '</td><td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in status_badge().
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form" data-aag-confirm="deny" data-confirm-title="' . esc_attr( sprintf( /* translators: %s: caller key. */ __( 'Deny %s?', 'axle-ai-guard' ), (string) $key ) ) . '" data-confirm-body="' . esc_attr__( 'Its AI calls are blocked until you approve it again. You can undo this from the notice that follows.', 'axle-ai-guard' ) . '" data-confirm-yes="' . esc_attr__( 'Deny', 'axle-ai-guard' ) . '">';
			self::form_fields( 'callers' );
			echo '<input type="hidden" name="caller" value="' . esc_attr( (string) $key ) . '"><input type="hidden" name="prev" value="' . esc_attr( $status ) . '">';
			if ( 'approved' !== $status ) {
				echo '<button type="submit" name="status" value="approved" class="button">' . esc_html__( 'Approve', 'axle-ai-guard' ) . '</button> ';
			}
			if ( 'denied' !== $status ) {
				echo '<button type="submit" name="status" value="denied" class="button" data-aag-confirm-trigger="1">' . esc_html__( 'Deny', 'axle-ai-guard' ) . '</button>';
			}
			echo '</form></td></tr>';
		}
		echo '</tbody></table></div></section>';
	}

	/**
	 * Data rules tab.
	 */
	private function render_rules(): void {
		$modes = array(
			'off'    => __( 'Off', 'axle-ai-guard' ),
			'log'    => __( 'Log', 'axle-ai-guard' ),
			'redact' => __( 'Redact', 'axle-ai-guard' ),
			'block'  => __( 'Block', 'axle-ai-guard' ),
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'rules' );
		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Detectors', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Each detector looks at what a call sends. Log records that it was found. Redact replaces it with a placeholder before the request leaves the site. Block stops the call. Files sent with a prompt cannot be scanned; block them per plugin under Budgets and limits.', 'axle-ai-guard' ) . '</p>';
		echo '<table class="widefat striped aag-table aag-rules"><thead><tr><th scope="col">' . esc_html__( 'Detector', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Mode', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		foreach ( $this->detectors->all() as $id => $detector ) {
			if ( 0 === strpos( $id, 'custom-' ) ) {
				continue;
			}
			echo '<tr><th scope="row"><label for="aag-rule-' . esc_attr( $id ) . '">' . esc_html( $detector['label'] ) . '</label></th><td><select id="aag-rule-' . esc_attr( $id ) . '" name="modes[' . esc_attr( $id ) . ']">';
			foreach ( $modes as $mode => $label ) {
				echo '<option value="' . esc_attr( $mode ) . '"' . selected( $mode, $this->detectors->mode( $id ), false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table></section>';

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Custom rules', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'A string matches without regard to case. A regex needs delimiters, such as /ORD-\d{6}/.', 'axle-ai-guard' ) . '</p>';
		echo '<table class="widefat striped aag-table aag-custom"><thead><tr><th scope="col">' . esc_html__( 'Label', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Type', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Pattern', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Mode', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		$custom   = $this->detectors->custom();
		$custom[] = array(
			'label'   => '',
			'type'    => 'string',
			'pattern' => '',
			'mode'    => 'redact',
		);
		foreach ( $custom as $i => $rule ) {
			/* translators: %d: rule number. */
			$row = sprintf( __( 'Custom rule %d', 'axle-ai-guard' ), $i + 1 );
			echo '<tr>';
			printf( '<td><input type="text" name="custom[%1$d][label]" value="%2$s" aria-label="%3$s"></td>', (int) $i, esc_attr( $rule['label'] ), esc_attr( $row . ': ' . __( 'label', 'axle-ai-guard' ) ) );
			printf( '<td><select name="custom[%1$d][type]" aria-label="%2$s"><option value="string"%3$s>%4$s</option><option value="regex"%5$s>%6$s</option></select></td>', (int) $i, esc_attr( $row . ': ' . __( 'type', 'axle-ai-guard' ) ), selected( $rule['type'], 'string', false ), esc_html__( 'String', 'axle-ai-guard' ), selected( $rule['type'], 'regex', false ), esc_html__( 'Regex', 'axle-ai-guard' ) );
			printf( '<td><input type="text" name="custom[%1$d][pattern]" value="%2$s" class="regular-text" aria-label="%3$s"></td>', (int) $i, esc_attr( $rule['pattern'] ), esc_attr( $row . ': ' . __( 'pattern', 'axle-ai-guard' ) ) );
			printf( '<td><select name="custom[%1$d][mode]" aria-label="%2$s">', (int) $i, esc_attr( $row . ': ' . __( 'mode', 'axle-ai-guard' ) ) );
			foreach ( $modes as $mode => $label ) {
				echo '<option value="' . esc_attr( $mode ) . '"' . selected( $mode, $rule['mode'], false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table></section>';
		submit_button( __( 'Save data rules', 'axle-ai-guard' ) );
		echo '</form>';

		$this->render_test_box();
	}

	/**
	 * The "Try your rules" card. The text goes to this page in the same request and comes
	 * back in the answer: it is never stored and never sent anywhere.
	 */
	private function render_test_box(): void {
		$result = null;
		$text   = '';
		$caller = '';
		$ctx    = 'admin';
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified on the next line.
		if ( isset( $_POST['aag_try'] ) && check_admin_referer( 'axle_ai_guard_try' ) ) {
			$text   = isset( $_POST['text'] ) ? sanitize_textarea_field( wp_unslash( $_POST['text'] ) ) : '';
			$caller = isset( $_POST['caller'] ) ? sanitize_text_field( wp_unslash( $_POST['caller'] ) ) : '';
			$ctx    = isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'admin';
			// phpcs:enable
			$result = $this->reports->test_rules( $text, $caller, $ctx );
		}

		echo '<section class="axle-card aag-test" id="aag-test" aria-labelledby="aag-test-title"><h2 class="axle-card__title" id="aag-test-title">' . esc_html__( 'Try your rules', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Paste a prompt to see what each rule would redact or block. The text is checked on this page only: it is never stored and never sent to any AI provider.', 'axle-ai-guard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( self::url( 'rules' ) . '#aag-test' ) . '">';
		wp_nonce_field( 'axle_ai_guard_try' );
		echo '<input type="hidden" name="aag_try" value="1">';
		echo '<p><label for="aag-test-text">' . esc_html__( 'Text to check', 'axle-ai-guard' ) . '</label></p>';
		echo '<textarea id="aag-test-text" name="text" rows="5" class="large-text" autocomplete="off">' . esc_textarea( $text ) . '</textarea>';
		echo '<div class="axle-toolbar"><span class="aag-filter"><label for="aag-test-caller">' . esc_html__( 'Plugin', 'axle-ai-guard' ) . '</label> <select id="aag-test-caller" name="caller"><option value="">' . esc_html__( 'Any plugin', 'axle-ai-guard' ) . '</option>';
		foreach ( array_keys( $this->settings->callers() ) as $name ) {
			echo '<option value="' . esc_attr( (string) $name ) . '"' . selected( (string) $name, $caller, false ) . '>' . esc_html( (string) $name ) . '</option>';
		}
		echo '</select></span> <span class="aag-filter"><label for="aag-test-context">' . esc_html__( 'Context', 'axle-ai-guard' ) . '</label> <select id="aag-test-context" name="context">';
		foreach ( self::context_labels() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $ctx, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></span> <button type="submit" class="button button-primary">' . esc_html__( 'Check the text', 'axle-ai-guard' ) . '</button></div>';
		echo '</form>';

		if ( is_array( $result ) ) {
			$this->render_try_result( $text, $result );
		}
		echo '</section>';
	}

	/**
	 * The answer of the rule test: outcome, rule, and the text with each match marked.
	 *
	 * @param string $text   The text.
	 * @param array  $result From Reports::test_rules().
	 */
	private function render_try_result( string $text, array $result ): void {
		$outcomes = array(
			'sent'     => array( 'good', __( 'Sent as it is', 'axle-ai-guard' ), __( 'Nothing in this text needs to change. A call with it goes out unchanged.', 'axle-ai-guard' ) ),
			'redacted' => array( 'info', __( 'Sent redacted', 'axle-ai-guard' ), __( 'A call with this text goes out with the matches replaced by placeholders.', 'axle-ai-guard' ) ),
			'blocked'  => array( 'error', __( 'Blocked', 'axle-ai-guard' ), __( 'A call with this text is stopped before any request leaves the site.', 'axle-ai-guard' ) ),
		);
		$outcome  = $outcomes[ $result['outcome'] ];

		echo '<div class="aag-test-result" role="status" aria-live="polite">';
		echo '<p><span class="axle-badge axle-badge--' . esc_attr( $outcome[0] ) . '">' . esc_html( $outcome[1] ) . '</span> <strong>' . esc_html( $outcome[2] ) . '</strong></p>';
		if ( '' !== $result['rule'] ) {
			echo '<p>' . esc_html__( 'Decided by the rule:', 'axle-ai-guard' ) . ' <code>' . esc_html( $result['rule'] ) . '</code></p>';
		}
		if ( $result['too_large'] ) {
			echo '<p class="axle-muted">' . esc_html__( 'The text is larger than the scan limit, so it was not scanned.', 'axle-ai-guard' ) . '</p>';
		}
		if ( ! $result['findings'] ) {
			echo '<p class="axle-muted">' . esc_html__( 'No rule found anything in this text.', 'axle-ai-guard' ) . '</p></div>';
			return;
		}

		echo '<h3>' . esc_html__( 'What each rule found', 'axle-ai-guard' ) . '</h3><ul class="aag-findings">';
		foreach ( $result['findings'] as $finding ) {
			/* translators: 1: detector label, 2: count, 3: mode. */
			echo '<li>' . esc_html( sprintf( __( '%1$s: %2$d found, mode %3$s', 'axle-ai-guard' ), $finding['label'], $finding['count'], $finding['mode'] ) ) . '</li>';
		}
		echo '</ul><h3>' . esc_html__( 'Your text, with matches marked', 'axle-ai-guard' ) . '</h3><div class="aag-marked" tabindex="0" role="region" aria-label="' . esc_attr__( 'Your text with each match marked and labelled', 'axle-ai-guard' ) . '">';
		$at = 0;
		foreach ( $result['spans'] as $span ) {
			echo esc_html( substr( $text, $at, $span['start'] - $at ) );
			printf(
				'<mark class="aag-mark aag-mark--%1$s">%2$s<span class="aag-mark__label"> [%3$s, %4$s]</span></mark>',
				esc_attr( $span['mode'] ),
				esc_html( substr( $text, $span['start'], $span['length'] ) ),
				esc_html( $span['label'] ),
				esc_html( $span['mode'] )
			);
			$at = $span['start'] + $span['length'];
		}
		echo esc_html( substr( $text, $at ) ) . '</div>';

		if ( 'blocked' !== $result['outcome'] ) {
			echo '<h3>' . esc_html__( 'What the provider would get', 'axle-ai-guard' ) . '</h3><div class="axle-diff"><del><span class="screen-reader-text">' . esc_html__( 'Before:', 'axle-ai-guard' ) . '</span> ' . esc_html( $text ) . '</del><ins><span class="screen-reader-text">' . esc_html__( 'After:', 'axle-ai-guard' ) . '</span> ' . esc_html( (string) $result['redacted'] ) . '</ins></div>';
		}
		echo '</div>';
	}

	/**
	 * Prices tab.
	 */
	private function render_prices(): void {
		$missing = $this->reports->unpriced_models();
		if ( $missing ) {
			echo '<div class="notice notice-warning inline aag-unpriced"><p><strong>' . esc_html__( 'Some models have no price, so their calls show a cost of zero.', 'axle-ai-guard' ) . '</strong></p><ul>';
			foreach ( $missing as $row ) {
				$key = strtolower( $row['provider'] . '/' . $row['model'] );
				echo '<li><code>' . esc_html( $key ) . '</code> ' . esc_html(
					sprintf(
						/* translators: %s: number of calls. */
						_n( '%s call', '%s calls', $row['calls'], 'axle-ai-guard' ),
						number_format_i18n( $row['calls'] )
					)
				) . ' <a href="' . esc_url( self::url( 'prices', array( 'add' => $key ) ) . '#aag-price-new' ) . '">' . esc_html__( 'Add a price', 'axle-ai-guard' ) . '</a></li>';
			}
			echo '</ul><p>' . esc_html__( 'After you add a price, recalculate the last 30 days to fix their costs.', 'axle-ai-guard' ) . '</p></div>';
		}

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Price table', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: date, 2: currency. */
				__( 'Prices per million tokens, in %2$s. %3$s Prices as of %1$s: check them against your provider, they change. A key ending in * matches every model id that starts with the rest.', 'axle-ai-guard' ),
				$this->prices->as_of(),
				$this->prices->currency(),
				$this->prices->edited() ? __( 'Edited on this site.', 'axle-ai-guard' ) : __( 'The shipped list prices.', 'axle-ai-guard' )
			)
		) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'prices' );
		echo '<div class="axle-scroll" tabindex="0" role="region" aria-label="' . esc_attr__( 'Price table', 'axle-ai-guard' ) . '"><table class="widefat striped aag-table aag-prices"><thead><tr><th scope="col">' . esc_html__( 'Provider/model', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Input', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Output', 'axle-ai-guard' ) . '</th><th scope="col">' . esc_html__( 'Thought', 'axle-ai-guard' ) . '</th></tr></thead><tbody>';
		$rows = array();
		foreach ( $this->prices->table() as $key => $row ) {
			$rows[] = array( 'key' => $key ) + $row;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Pre-fills a field, saves nothing.
		$add = isset( $_GET['add'] ) ? Prices::key( sanitize_text_field( wp_unslash( $_GET['add'] ) ) ) : '';
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[] = array(
				'key'     => 0 === $i ? $add : '',
				'input'   => '',
				'output'  => '',
				'thought' => '',
			);
		}
		foreach ( $rows as $i => $row ) {
			/* translators: %d: row number. */
			$label = sprintf( __( 'Price row %d', 'axle-ai-guard' ), $i + 1 );
			echo '<tr>';
			printf( '<td><input type="text" name="prices[%1$d][key]" value="%2$s" class="regular-text" aria-label="%3$s"' . ( '' !== $add && $add === $row['key'] && '' === (string) $row['input'] ? ' id="aag-price-new"' : '' ) . '></td>', (int) $i, esc_attr( (string) $row['key'] ), esc_attr( $label . ': ' . __( 'provider/model', 'axle-ai-guard' ) ) );
			foreach ( array( 'input', 'output', 'thought' ) as $field ) {
				printf( '<td><input type="text" inputmode="decimal" name="prices[%1$d][%2$s]" value="%3$s" class="small-text" aria-label="%4$s"></td>', (int) $i, esc_attr( $field ), esc_attr( (string) $row[ $field ] ), esc_attr( $label . ': ' . $field ) );
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
		echo '<p class="submit"><button type="submit" name="do" value="save" class="button button-primary">' . esc_html__( 'Save prices', 'axle-ai-guard' ) . '</button></p>';
		echo '</section></form>';

		echo '<section class="axle-card aag-price-json" aria-labelledby="aag-json-title"><h2 class="axle-card__title" id="aag-json-title">' . esc_html__( 'Import and export', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Export the table as JSON to keep a copy or use it on another site. Importing adds the models in the file and replaces the price of any model with the same key. The notice that follows has an Undo link.', 'axle-ai-guard' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="aag-inline-form">';
		self::form_fields( 'prices' );
		echo '<button type="submit" name="do" value="export" class="button">' . esc_html__( 'Download the price table', 'axle-ai-guard' ) . '</button></form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-aag-confirm="1" data-confirm-title="' . esc_attr__( 'Import these prices?', 'axle-ai-guard' ) . '" data-confirm-body="' . esc_attr__( 'Models in the JSON get the imported price, and models with the same key lose their current one. You can undo it from the notice that follows.', 'axle-ai-guard' ) . '" data-confirm-yes="' . esc_attr__( 'Import prices', 'axle-ai-guard' ) . '">';
		self::form_fields( 'prices' );
		echo '<p><label for="aag-import-json">' . esc_html__( 'Paste the JSON to import', 'axle-ai-guard' ) . '</label></p><textarea id="aag-import-json" name="json" rows="6" class="large-text code" autocomplete="off"></textarea>';
		echo '<p class="submit"><button type="submit" name="do" value="import" class="button">' . esc_html__( 'Import prices', 'axle-ai-guard' ) . '</button></p></form></section>';

		echo '<section class="axle-card axle-card--danger"><h2 class="axle-card__title">' . esc_html__( 'Replace costs or prices', 'axle-ai-guard' ) . '</h2>';
		echo '<p>' . esc_html__( 'Recalculating overwrites the stored cost of every call from the last 30 days with the current prices, and cannot be undone. Preview first to see how many calls would change. Going back to the shipped prices removes your edits; the notice that follows has an Undo link.', 'axle-ai-guard' ) . '</p>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		$next = isset( $_GET['next'] ) ? absint( $_GET['next'] ) : 0;
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="axle-actions" data-aag-confirm="deny" data-confirm-title="' . esc_attr__( 'Replace the stored costs?', 'axle-ai-guard' ) . '" data-confirm-body="' . esc_attr__( 'This overwrites the stored cost of every call from the last 30 days. It cannot be undone. Preview shows what would change.', 'axle-ai-guard' ) . '" data-confirm-yes="' . esc_attr__( 'Recalculate the last 30 days', 'axle-ai-guard' ) . '">';
		self::form_fields( 'prices' );
		if ( $next ) {
			echo '<input type="hidden" name="after" value="' . esc_attr( (string) $next ) . '">';
		}
		echo '<button type="submit" name="do" value="preview" class="button">' . esc_html__( 'Preview the change', 'axle-ai-guard' ) . '</button> ';
		echo '<button type="submit" name="do" value="recalc" class="button axle-button--danger" data-aag-confirm-trigger="1">' . esc_html( $next ? __( 'Continue recalculating', 'axle-ai-guard' ) : __( 'Recalculate the last 30 days', 'axle-ai-guard' ) ) . '</button> ';
		echo '<button type="submit" name="do" value="reset" class="button axle-button--danger" data-aag-reset="1">' . esc_html__( 'Back to the shipped prices', 'axle-ai-guard' ) . '</button>';
		echo '</form></section>';
	}

	/**
	 * Settings tab.
	 */
	private function render_settings(): void {
		$all = $this->settings->all();

		if ( self::ai_plugin_feature( 'connector-approval' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'The AI plugin\'s Connector Approval experiment is on. Approve connectors per plugin there; AI Guard does not ask again. Use "Allow" or "Allow and alert" below so the two do not both hold new plugins.', 'axle-ai-guard' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		self::form_fields( 'settings' );
		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Plugins and money', 'axle-ai-guard' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="aag-new_caller">' . esc_html__( 'A plugin making its first AI call', 'axle-ai-guard' ) . '</label></th><td><select id="aag-new_caller" name="settings[new_caller]">';
		foreach ( array(
			'allow' => __( 'Allow', 'axle-ai-guard' ),
			'alert' => __( 'Allow and alert', 'axle-ai-guard' ),
			'deny'  => __( 'Block until approved', 'axle-ai-guard' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $all['new_caller'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		self::text_row( 'currency', __( 'Currency (ISO code)', 'axle-ai-guard' ), (string) $all['currency'], 'small-text' );
		echo '</tbody></table></section>';

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'What is kept', 'axle-ai-guard' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::number_row( 'retention_calls', __( 'Keep calls for (days)', 'axle-ai-guard' ), (int) $all['retention_calls'] );
		self::number_row( 'retention_usage', __( 'Keep usage totals for (days)', 'axle-ai-guard' ), (int) $all['retention_usage'] );
		self::check_row( 'store_preview', __( 'Previews', 'axle-ai-guard' ), __( 'Store the first 300 characters of each prompt, after redaction', 'axle-ai-guard' ), (bool) $all['store_preview'] );
		self::check_row( 'store_prompts', __( 'Prompt store', 'axle-ai-guard' ), __( 'Store each full prompt, with every finding redacted, for debugging', 'axle-ai-guard' ), (bool) $all['store_prompts'] );
		self::number_row( 'retention_prompts', __( 'Keep stored prompts for (days)', 'axle-ai-guard' ), (int) $all['retention_prompts'] );
		echo '</tbody></table></section>';

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Alerts', 'axle-ai-guard' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::check_row( 'alert_email', __( 'Email alerts', 'axle-ai-guard' ), __( 'Email alerts for budgets, forecasts, runaway plugins, kill switches, data rule blocks, new plugins and error spikes', 'axle-ai-guard' ), (bool) $all['alert_email'] );
		self::text_row( 'alert_recipients', __( 'Alert recipients (empty: the site admin email)', 'axle-ai-guard' ), (string) $all['alert_recipients'], 'regular-text' );
		self::text_row( 'webhook_url', __( 'Alert webhook URL (JSON POST)', 'axle-ai-guard' ), (string) $all['webhook_url'], 'regular-text' );
		echo '</tbody></table></section>';

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Runaway protection', 'axle-ai-guard' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Watches how many AI calls each plugin makes per hour against its own normal hour from the last 7 days.', 'axle-ai-guard' ) . '</p>';
		echo '<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="aag-runaway_mode">' . esc_html__( 'When a plugin runs away', 'axle-ai-guard' ) . '</label></th><td><select id="aag-runaway_mode" name="settings[runaway_mode]">';
		foreach ( array(
			'off'   => __( 'Do nothing', 'axle-ai-guard' ),
			'alert' => __( 'Alert only', 'axle-ai-guard' ),
			'pause' => __( 'Pause that plugin and alert', 'axle-ai-guard' ),
		) as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $all['runaway_mode'], false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';
		self::number_row( 'runaway_multiple', __( 'Runaway at this many times the usual hour', 'axle-ai-guard' ), (int) $all['runaway_multiple'] );
		self::number_row( 'runaway_min_calls', __( 'But never below this many calls in an hour', 'axle-ai-guard' ), (int) $all['runaway_min_calls'] );
		echo '</tbody></table></section>';

		echo '<section class="axle-card"><h2 class="axle-card__title">' . esc_html__( 'Monitoring and automatic switch-off', 'axle-ai-guard' ) . '</h2>';
		echo '<table class="form-table" role="presentation"><tbody>';
		self::check_row( 'direct_monitoring', __( 'Direct calls', 'axle-ai-guard' ), __( 'Watch requests to known AI provider hosts that skip the AI Client', 'axle-ai-guard' ), (bool) $all['direct_monitoring'] );
		self::check_row( 'autotrip_budget', __( 'Budget auto-trip', 'axle-ai-guard' ), __( 'Switch all AI off when the monthly money budget of the whole site is reached', 'axle-ai-guard' ), (bool) $all['autotrip_budget'] );
		self::number_row( 'autotrip_errors', __( 'Switch all AI off when this % of calls fail in 5 minutes (0: never)', 'axle-ai-guard' ), (int) $all['autotrip_errors'] );
		echo '</tbody></table></section>';

		submit_button( __( 'Save settings', 'axle-ai-guard' ) );
		echo '</form>';
	}

	/**
	 * A text settings row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param string $value Value.
	 * @param string $css   Input class.
	 */
	private static function text_row( string $key, string $label, string $value, string $css ): void {
		printf( '<tr><th scope="row"><label for="aag-%1$s">%2$s</label></th><td><input type="text" id="aag-%1$s" name="settings[%1$s]" value="%3$s" class="%4$s"></td></tr>', esc_attr( $key ), esc_html( $label ), esc_attr( $value ), esc_attr( $css ) );
	}

	/**
	 * A number settings row.
	 *
	 * @param string $key   Key.
	 * @param string $label Label.
	 * @param int    $value Value.
	 */
	private static function number_row( string $key, string $label, int $value ): void {
		printf( '<tr><th scope="row"><label for="aag-%1$s">%2$s</label></th><td><input type="number" min="0" id="aag-%1$s" name="settings[%1$s]" value="%3$d" class="small-text"></td></tr>', esc_attr( $key ), esc_html( $label ), (int) $value );
	}

	/**
	 * A checkbox settings row.
	 *
	 * @param string $key     Key.
	 * @param string $heading Row heading.
	 * @param string $label   Checkbox label.
	 * @param bool   $checked Whether on.
	 */
	private static function check_row( string $key, string $heading, string $label, bool $checked ): void {
		printf( '<tr><th scope="row">%2$s</th><td><input type="hidden" name="settings[%1$s]" value="0"><label><input type="checkbox" name="settings[%1$s]" value="1"%3$s> %4$s</label></td></tr>', esc_attr( $key ), esc_html( $heading ), checked( $checked, true, false ), esc_html( $label ) );
	}

	/**
	 * Whether a feature of the official AI plugin is on.
	 *
	 * @param string $feature Feature id, such as connector-approval or ai-request-logging.
	 * @return bool
	 */
	public static function ai_plugin_feature( string $feature ): bool {
		$active = (array) get_option( 'active_plugins', array() );

		return in_array( 'ai/ai.php', $active, true ) && (bool) get_option( 'wpai_feature_' . $feature . '_enabled' );
	}

	/**
	 * Checks the capability and nonce of a form.
	 *
	 * @param string $action Action without the prefix.
	 */
	private static function verify( string $action ): void {
		if ( ! current_user_can( Plugin::capability() ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage AI Guard.', 'axle-ai-guard' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'axle_ai_guard_' . $action );
	}

	/**
	 * Back to a tab with a message.
	 *
	 * @param string $tab  Tab.
	 * @param string $done Message key.
	 * @param array  $args Extra query arguments, such as what Undo needs.
	 */
	private static function back( string $tab, string $done, array $args = array() ): void {
		wp_safe_redirect( self::url( $tab, array_merge( array( 'done' => $done ), $args ) ) );
		exit;
	}

	/**
	 * A posted field.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	private static function posted( string $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked in verify(); every value is sanitised where it is used.
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : null;
	}

	/**
	 * Handles a kill switch form.
	 */
	public function handle_kill(): void {
		self::verify( 'kill' );
		$scope   = sanitize_key( (string) self::posted( 'scope' ) );
		$subject = sanitize_text_field( (string) self::posted( 'subject' ) );
		$on      = '1' === (string) self::posted( 'on' );
		$reason  = sanitize_text_field( (string) self::posted( 'reason' ) );

		$changed = $on ? $this->kill->engage( $scope, $subject, $reason, get_current_user_id() ) : $this->kill->release( $scope, $subject, get_current_user_id() );
		self::back(
			'overview',
			$changed ? ( $on ? 'kill_on' : 'kill_off' ) : 'kill_same',
			array(
				'k_scope'   => $scope,
				'k_subject' => $subject,
			)
		);
	}

	/**
	 * Sends the users and roles report as a CSV download.
	 */
	public function handle_people_csv(): void {
		self::verify( 'people_csv' );
		$period = UserReport::period_key( sanitize_key( (string) self::posted( 'period' ) ) );
		$by     = 'role' === sanitize_key( (string) self::posted( 'by' ) ) ? 'role' : 'user';
		$report = $this->reports->user_usage( $period );
		$rows   = $this->reports->user_usage_csv( $report, $by );

		/**
		 * Fires when the users and roles report is downloaded as CSV.
		 *
		 * @param string $by      user or role.
		 * @param string $period  Period key.
		 * @param int    $rows    Data rows, without the header.
		 * @param int    $user_id The person who downloaded it.
		 */
		do_action( 'axle_ai_guard_user_report_exported', $by, $period, count( $rows ) - 1, get_current_user_id() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="axle-ai-guard-' . $by . 's-' . $period . '-' . gmdate( 'Y-m-d' ) . '.csv"' );
		echo UserReport::to_csv( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A CSV download, not HTML.
		exit;
	}

	/**
	 * Handles the budgets form.
	 */
	public function handle_budgets(): void {
		self::verify( 'budgets' );
		$settings = (array) self::posted( 'settings' );
		$this->settings->save( array_intersect_key( $settings, array_flip( array( 'rate_global', 'rate_plugin', 'rate_user', 'rate_ip', 'soft_ratio' ) ) ) );

		foreach ( (array) self::posted( 'policy' ) as $subject => $fields ) {
			$fields = (array) $fields;
			foreach ( array( 'daily_cost', 'monthly_cost' ) as $money ) {
				$fields[ $money ] = Prices::to_micros( $fields[ $money ] ?? '' );
			}
			$fields['contexts']    = (array) ( $fields['contexts'] ?? array() );
			$fields['block_files'] = ! empty( $fields['block_files'] );
			$this->settings->save_policy( (string) $subject, $fields );
		}

		$new = sanitize_key( (string) self::posted( 'new_subject' ) );
		if ( '' !== $new ) {
			$this->settings->save_policy( 'plugin:' . $new, array() );
		}

		self::back( 'budgets', 'budgets' );
	}

	/**
	 * Handles approve and deny of a caller.
	 */
	public function handle_callers(): void {
		self::verify( 'callers' );
		$caller = sanitize_text_field( (string) self::posted( 'caller' ) );
		$prev   = sanitize_key( (string) self::posted( 'prev' ) );
		$this->settings->set_caller_status( $caller, sanitize_key( (string) self::posted( 'status' ) ) );
		self::back(
			'budgets',
			'callers',
			array(
				'caller' => $caller,
				'prev'   => $prev,
			)
		);
	}

	/**
	 * Handles the data rules form.
	 */
	public function handle_rules(): void {
		self::verify( 'rules' );
		$modes = array_map( 'sanitize_key', (array) self::posted( 'modes' ) );
		$this->detectors->save( $modes, array_values( (array) self::posted( 'custom' ) ) );
		self::back( 'rules', 'rules' );
	}

	/**
	 * Handles the prices form: save, reset or recalculate.
	 */
	public function handle_prices(): void {
		self::verify( 'prices' );
		$do = sanitize_key( (string) self::posted( 'do' ) );

		if ( 'undo' === $do ) {
			$this->prices->undo();
			self::back( 'prices', 'prices_undone' );
		}
		if ( 'reset' === $do ) {
			$this->prices->reset();
			self::back( 'prices', 'prices_reset' );
		}
		if ( 'export' === $do ) {
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="axle-ai-guard-prices.json"' );
			echo $this->prices->export_json(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- A JSON download, not HTML.
			exit;
		}
		if ( 'import' === $do ) {
			$result = $this->prices->import_json( (string) self::posted( 'json' ) );
			if ( is_wp_error( $result ) ) {
				set_transient( 'axle_ai_guard_import_error_' . get_current_user_id(), $result->get_error_message(), MINUTE_IN_SECONDS );
				self::back( 'prices', 'prices_import_failed' );
			}
			self::back( 'prices', 'prices_imported' );
		}
		if ( 'preview' === $do || 'recalc' === $do ) {
			$after   = absint( (string) self::posted( 'after' ) );
			$summary = $this->cleanup->recalculate( 30, 'preview' === $do, 'preview' === $do ? 0 : $after );
			if ( 'preview' === $do ) {
				self::back(
					'prices',
					'recalc_preview',
					array(
						'n' => $summary['rows'],
						'c' => $summary['changed'],
						'd' => $summary['delta'],
					)
				);
			}
			self::back(
				'prices',
				'recalc',
				array(
					'n'    => $summary['rows'],
					'next' => $summary['complete'] ? 0 : $summary['next'],
				)
			);
		}

		$table = array();
		foreach ( (array) self::posted( 'prices' ) as $row ) {
			$row = (array) $row;
			$key = Prices::key( sanitize_text_field( (string) ( $row['key'] ?? '' ) ) );
			if ( '' === $key || ! is_numeric( $row['input'] ?? '' ) || ! is_numeric( $row['output'] ?? '' ) ) {
				continue;
			}
			$table[ $key ] = array(
				'input'   => (float) $row['input'],
				'output'  => (float) $row['output'],
				'thought' => is_numeric( $row['thought'] ?? '' ) ? (float) $row['thought'] : '',
			);
		}
		$this->prices->save( $table );
		self::back( 'prices', 'prices' );
	}

	/**
	 * Handles the settings form.
	 */
	public function handle_settings(): void {
		self::verify( 'settings' );
		$this->settings->save( (array) self::posted( 'settings' ) );
		self::back( 'settings', 'settings' );
	}

	/**
	 * Handles Resume, Resume and raise the limit, and Dismiss on a runaway warning.
	 */
	public function handle_runaway(): void {
		self::verify( 'runaway' );
		$event = sanitize_key( (string) self::posted( 'event' ) );
		$do    = sanitize_key( (string) self::posted( 'do' ) );
		$name  = '';
		foreach ( $this->runaway->open_events() as $open ) {
			if ( $event === $open['id'] ) {
				$name = (string) $open['caller'];
			}
		}

		if ( 'dismiss' === $do ) {
			$ok = $this->runaway->dismiss( $event, get_current_user_id() );
			self::back( 'overview', $ok ? 'runaway_closed' : 'runaway_same' );
		}
		$raise = 'raise' === $do;
		$ok    = $this->runaway->resume( $event, get_current_user_id(), $raise );
		self::back( 'overview', $ok ? ( $raise ? 'runaway_raised' : 'runaway_resumed' ) : 'runaway_same', array( 'caller' => $name ) );
	}

	/**
	 * Hides the Get started card for the current user.
	 */
	public function handle_steps(): void {
		self::verify( 'steps' );
		update_user_meta( get_current_user_id(), 'axle_ai_guard_steps_dismissed', 1 );
		self::back( 'overview', 'steps' );
	}
}
