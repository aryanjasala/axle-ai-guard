<?php
/**
 * Alerts by email and webhook, throttled.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Sends an alert once per event type and subject per hour (filterable), by
 * email to the recipients and as JSON to the optional webhook.
 */
final class Alerts {

	public const TYPES = array( 'budget_soft', 'budget_hard', 'kill_on', 'kill_off', 'data_block', 'new_caller', 'error_spike' );

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Sets up alerts.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Who gets alert emails.
	 *
	 * @param string $type Alert type.
	 * @return string[]
	 */
	public function recipients( string $type ): array {
		$list = Settings::emails( (string) $this->settings->get( 'alert_recipients' ) );
		if ( ! $list ) {
			$list = Settings::emails( (string) get_option( 'admin_email' ) );
		}

		/**
		 * Filters who gets alert emails.
		 *
		 * @param string[] $list Email addresses. Default the alert recipients setting, else the site admin email.
		 * @param string   $type Alert type.
		 */
		$filtered = apply_filters( 'axle_ai_guard_alert_recipients', $list, $type );

		return Settings::emails( Hooks::arr( $filtered, $list ) );
	}

	/**
	 * Seconds before the same alert for the same subject is sent again.
	 *
	 * @param string $type    Alert type.
	 * @param string $subject Subject.
	 * @return int
	 */
	public static function throttle( string $type, string $subject ): int {
		/**
		 * Filters how long the same alert stays quiet after it was sent.
		 *
		 * @param int    $seconds Default one hour. 0 sends every time.
		 * @param string $type    Alert type.
		 * @param string $subject Subject, such as plugin:caller-a.
		 */
		return max( 0, (int) apply_filters( 'axle_ai_guard_alert_throttle', HOUR_IN_SECONDS, $type, $subject ) );
	}

	/**
	 * Sends an alert unless the same one went out recently.
	 *
	 * @param string $type    Alert type.
	 * @param string $subject Subject, such as global or plugin:caller-a.
	 * @param string $message What happened, one sentence.
	 * @param array  $data    Extra details for the webhook.
	 * @return bool Whether it was sent.
	 */
	public function send( string $type, string $subject, string $message, array $data = array() ): bool {
		$key      = 'axle_ai_guard_alert_' . md5( $type . '|' . $subject );
		$throttle = self::throttle( $type, $subject );
		if ( $throttle > 0 && false !== get_transient( $key ) ) {
			return false;
		}
		if ( $throttle > 0 ) {
			set_transient( $key, time(), $throttle );
		}

		$site     = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$channels = array();

		if ( $this->settings->get( 'alert_email' ) ) {
			$to = $this->recipients( $type );
			if ( $to ) {
				$body = $message . "\n\n" . sprintf(
					/* translators: %s: URL of the AI Guard screen. */
					__( 'Details: %s', 'axle-ai-guard' ),
					admin_url( 'tools.php?page=axle-ai-guard' )
				);
				/* translators: 1: site name, 2: alert message. */
				$subject_line = sprintf( __( '[%1$s] AI Guard: %2$s', 'axle-ai-guard' ), $site, wp_html_excerpt( $message, 80, '...' ) );
				// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail -- A few throttled alerts to the site's own admins.
				if ( wp_mail( $to, $subject_line, $body ) ) {
					$channels[] = 'email';
				}
			}
		}

		$webhook = (string) $this->settings->get( 'webhook_url' );
		if ( '' !== $webhook ) {
			$payload = array(
				'event'   => $type,
				'subject' => $subject,
				'message' => $message,
				'site'    => home_url( '/' ),
				'time'    => gmdate( 'c' ),
				'data'    => $data,
			);

			/**
			 * Filters the JSON sent to the alert webhook. Return an empty value to skip it.
			 *
			 * @param array  $payload event, subject, message, site, time and data. Slack reads "text", so add it for Slack.
			 * @param string $type    Alert type.
			 */
			$payload = apply_filters( 'axle_ai_guard_webhook_payload', $payload, $type );
			$payload = Hooks::arr( $payload, array() );
			if ( $payload ) {
				wp_safe_remote_post(
					$webhook,
					array(
						'headers'  => array( 'Content-Type' => 'application/json' ),
						'body'     => (string) wp_json_encode( $payload ),
						'timeout'  => 3,
						'blocking' => false,
					)
				);
				$channels[] = 'webhook';
			}
		}

		/**
		 * Fires after an alert went out.
		 *
		 * @param string   $type     Alert type.
		 * @param string   $subject  Subject.
		 * @param string   $message  Message.
		 * @param string[] $channels email and/or webhook.
		 */
		do_action( 'axle_ai_guard_alert_sent', $type, $subject, $message, $channels );

		return (bool) $channels;
	}
}
