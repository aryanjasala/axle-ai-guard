<?php
/**
 * Base class with the plugin's objects wired to an in-memory database.
 *
 * @package AryanX\Axle\AiGuard
 */

namespace AryanX\Axle\AiGuard\Tests\Unit;

use AryanX\Axle\AiGuard\Alerts;
use AryanX\Axle\AiGuard\Calls;
use AryanX\Axle\AiGuard\Detectors;
use AryanX\Axle\AiGuard\Guard;
use AryanX\Axle\AiGuard\KillSwitch;
use AryanX\Axle\AiGuard\Policy;
use AryanX\Axle\AiGuard\Prices;
use AryanX\Axle\AiGuard\Reports;
use AryanX\Axle\AiGuard\Settings;
use AryanX\Axle\AiGuard\Usage;
use Brain\Monkey\Functions;

require_once dirname( __DIR__ ) . '/Stubs/axle-ai-guard.php';

/**
 * Wires Settings, Alerts, KillSwitch, Usage, Policy, Detectors, Prices,
 * Calls, Guard and Reports, and stubs the WordPress functions they call.
 */
abstract class GuardTestCase extends TestCase {

	/** @var \Aag_Fake_Wpdb */
	protected $db;
	/** @var Settings */
	protected $settings;
	/** @var Alerts */
	protected $alerts;
	/** @var KillSwitch */
	protected $kill;
	/** @var Usage */
	protected $usage;
	/** @var Policy */
	protected $policy;
	/** @var Detectors */
	protected $detectors;
	/** @var Prices */
	protected $prices;
	/** @var Calls */
	protected $calls;
	/** @var Guard */
	protected $guard;
	/** @var Reports */
	protected $reports;
	/** @var array Mails sent. */
	protected $mails = array();
	/** @var array Webhook posts. */
	protected $posts = array();
	/** @var array<string, mixed> Transients. */
	protected $transients = array();
	/** @var \WP_User */
	protected $user;
	/** @var string[] Emails of registered users. */
	protected $user_emails = array( 'jane@example.com' );

	protected function setUp(): void {
		parent::setUp();

		$this->db       = new \Aag_Fake_Wpdb();
		$GLOBALS['wpdb'] = $this->db;
		$this->mails      = array();
		$this->transients = array();
		$this->user       = new \WP_User( 0 );
		$this->options['admin_email'] = 'admin@example.com';
		$this->options['blogname']    = 'Bean There';

		Functions\stubs(
			array(
				'sanitize_key'         => static function ( $key ) {
					return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
				},
				'sanitize_text_field'  => static function ( $text ) {
					return trim( wp_strip_all_tags_stub( (string) $text ) );
				},
				'sanitize_email'       => static function ( $email ) {
					return trim( (string) $email );
				},
				'is_email'             => static function ( $email ) {
					return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
				},
				'esc_url_raw'          => static function ( $url ) {
					return (string) $url;
				},
				'wp_json_encode'       => static function ( $data, $flags = 0 ) {
					return json_encode( $data, $flags );
				},
				'wp_cache_get'         => 'yes',
				'wp_cache_set'         => true,
				'wp_cache_add'         => true,
				'wp_cache_delete'      => true,
				'wp_using_ext_object_cache' => false,
				'is_multisite'         => false,
				'home_url'             => 'https://example.com/',
				'admin_url'            => static function ( $path = '' ) {
					return 'https://example.com/wp-admin/' . $path;
				},
				'wp_specialchars_decode' => static function ( $text ) {
					return (string) $text;
				},
				'wp_html_excerpt'      => static function ( $text, $length ) {
					return substr( (string) $text, 0, $length );
				},
				'is_user_logged_in'    => function () {
					return $this->user->ID > 0;
				},
				'wp_get_current_user'  => function () {
					return $this->user;
				},
				'get_current_user_id'  => function () {
					return $this->user->ID;
				},
				'wp_doing_cron'        => false,
				'wp_doing_ajax'        => false,
				'is_admin'             => false,
				'wp_unslash'           => static function ( $value ) {
					return $value;
				},
				'wp_privacy_anonymize_ip' => static function ( $ip ) {
					return preg_replace( '/\.\d+$/', '.0', (string) $ip );
				},
				'email_exists'         => function ( $email ) {
					return in_array( $email, $this->user_emails, true ) ? 7 : false;
				},
				'get_transient'        => function ( $key ) {
					return $this->transients[ $key ] ?? false;
				},
				'set_transient'        => function ( $key, $value ) {
					$this->transients[ $key ] = $value;
					return true;
				},
				'wp_mail'              => function ( $to, $subject ) {
					$this->mails[] = array( 'to' => $to, 'subject' => $subject );
					return true;
				},
				'wp_safe_remote_post'  => function ( $url, $args ) {
					$this->posts[] = array( $url, $args );
					return array();
				},
				'current_user_can'     => true,
				'wp_get_connectors'    => array(),
			)
		);

		$this->settings  = new Settings();
		$this->alerts    = new Alerts( $this->settings );
		$this->kill      = new KillSwitch( $this->alerts );
		$this->usage     = new Usage();
		$this->policy    = new Policy( $this->settings, $this->kill, $this->usage, $this->alerts );
		$this->detectors = new Detectors();
		$this->prices    = new Prices( $this->settings );
		$this->calls     = new Calls();
		$this->guard     = new Guard( $this->settings, $this->policy, $this->kill, $this->detectors, $this->prices, $this->calls, $this->alerts );
		$this->reports   = new Reports( $this->settings, $this->policy, $this->kill, $this->detectors, $this->prices, $this->calls, $this->usage );
	}

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'] );
		parent::tearDown();
	}

	/**
	 * A caller array.
	 *
	 * @param string $slug Caller key.
	 * @return array
	 */
	protected static function caller( string $slug = 'caller-a' ): array {
		return array(
			'type' => 'plugin',
			'slug' => $slug,
			'file' => 'plugins/' . $slug . '/' . $slug . '.php',
			'line' => 12,
		);
	}

	/**
	 * Call details as Context::call() builds them.
	 *
	 * @param string $slug    Caller key.
	 * @param array  $changes Fields to change.
	 * @return array
	 */
	protected static function call( string $slug = 'caller-a', array $changes = array() ): array {
		return array_merge(
			array(
				'path'       => 'client',
				'caller'     => self::caller( $slug ),
				'context'    => 'rest',
				'user_id'    => 0,
				'roles'      => array( 'anonymous' ),
				'ip'         => '',
				'provider'   => 'openai',
				'model'      => 'gpt-5-mini',
				'capability' => 'text_generation',
			),
			$changes
		);
	}

	/**
	 * An SDK-shaped before or after event.
	 *
	 * @param string   $text     User message text.
	 * @param array    $usage    prompt, completion, thought tokens for an after event; null for a before event.
	 * @param string   $system   System instruction.
	 * @param array    $files    Files as [ mime, base64 ].
	 * @return object
	 */
	protected static function event( string $text, ?array $usage = null, string $system = '', array $files = array() ) {
		$id = static function ( string $value ) {
			return new class( $value ) {
				/** @var string */
				private $value;
				public function __construct( string $value ) {
					$this->value = $value;
				}
				public function getId(): string {
					return $this->value;
				}
				public function __toString(): string {
					return $this->value;
				}
			};
		};

		$parts = array(
			new class( $text ) {
				/** @var string */
				private $text;
				public function __construct( string $text ) {
					$this->text = $text;
				}
				public function getText(): ?string {
					return $this->text;
				}
			},
		);
		foreach ( $files as $file ) {
			$parts[] = new class( $file ) {
				/** @var array */
				private $file;
				public function __construct( array $file ) {
					$this->file = $file;
				}
				public function getText(): ?string {
					return null;
				}
				public function getFile() {
					return new class( $this->file ) {
						/** @var array */
						private $file;
						public function __construct( array $file ) {
							$this->file = $file;
						}
						public function getMimeType(): string {
							return $this->file[0];
						}
						public function getBase64Data(): ?string {
							return $this->file[1];
						}
					};
				}
			};
		}

		$message = new class( $parts, $id( 'user' ) ) {
			/** @var array */
			private $parts;
			/** @var object */
			private $role;
			public function __construct( array $parts, $role ) {
				$this->parts = $parts;
				$this->role  = $role;
			}
			public function getRole() {
				return $this->role;
			}
			public function getParts(): array {
				return $this->parts;
			}
		};

		$config = new class( $system ) {
			/** @var string */
			private $system;
			public function __construct( string $system ) {
				$this->system = $system;
			}
			public function getSystemInstruction(): ?string {
				return '' === $this->system ? null : $this->system;
			}
			public function getMaxTokens(): ?int {
				return null;
			}
		};

		$model = new class( $id( 'openai' ), $id( 'gpt-5-mini' ), $config ) {
			/** @var object */
			private $provider;
			/** @var object */
			private $meta;
			/** @var object */
			private $config;
			public function __construct( $provider, $meta, $config ) {
				$this->provider = $provider;
				$this->meta     = $meta;
				$this->config   = $config;
			}
			public function providerMetadata() {
				return $this->provider;
			}
			public function metadata() {
				return $this->meta;
			}
			public function getConfig() {
				return $this->config;
			}
		};

		$result = null;
		if ( null !== $usage ) {
			$result = new class( $usage, $id( 'openai' ), $id( 'gpt-5-mini-2025-08-07' ) ) {
				/** @var array */
				private $usage;
				/** @var object */
				private $provider;
				/** @var object */
				private $model;
				public function __construct( array $usage, $provider, $model ) {
					$this->usage    = $usage;
					$this->provider = $provider;
					$this->model    = $model;
				}
				public function getTokenUsage() {
					return new class( $this->usage ) {
						/** @var array */
						private $u;
						public function __construct( array $u ) {
							$this->u = $u;
						}
						public function getPromptTokens(): int {
							return $this->u[0];
						}
						public function getCompletionTokens(): int {
							return $this->u[1];
						}
						public function getThoughtTokens(): ?int {
							return $this->u[2] ?? null;
						}
						public function getTotalTokens(): int {
							return $this->u[0] + $this->u[1] + ( $this->u[2] ?? 0 );
						}
					};
				}
				public function getProviderMetadata() {
					return $this->provider;
				}
				public function getModelMetadata() {
					return $this->model;
				}
			};
		}

		return new class( array( $message ), $model, $id( 'text_generation' ), $result ) {
			/** @var array */
			private $messages;
			/** @var object */
			private $model;
			/** @var object */
			private $capability;
			/** @var object|null */
			private $result;
			public function __construct( array $messages, $model, $capability, $result ) {
				$this->messages   = $messages;
				$this->model      = $model;
				$this->capability = $capability;
				$this->result     = $result;
			}
			public function getMessages(): array {
				return $this->messages;
			}
			public function getModel() {
				return $this->model;
			}
			public function getCapability() {
				return $this->capability;
			}
			public function getResult() {
				return $this->result;
			}
		};
	}
}

/**
 * Strips tags, like wp_strip_all_tags().
 *
 * @param string $text Text.
 * @return string
 */
function wp_strip_all_tags_stub( string $text ): string {
	return strip_tags( $text );
}
