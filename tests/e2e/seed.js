// Fixtures and data for the end-to-end tests, the accessibility screens, the screenshots and the demo.
const { wp, wpEval } = require( './utils' );
const { BASE_URL } = require( './utils/config' );

const OPTIONS = [
	'axle_ai_guard_settings',
	'axle_ai_guard_policies',
	'axle_ai_guard_rules',
	'axle_ai_guard_prices',
	'axle_ai_guard_kill',
	'axle_ai_guard_seen_callers',
	'aag_fixture_bodies',
	'aag_fixture_fail',
	'aag_fixture_mail',
	'aag_fixture_actions',
];

/** The fake provider (a must-use plugin) and the caller plugins. */
function installFixtures() {
	wpEval(
		'$src = WP_PLUGIN_DIR . "/axle-ai-guard/tests/e2e/fixtures/";' +
			'wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( $src . "axle-ai-guard-fixture.php", WPMU_PLUGIN_DIR . "/axle-ai-guard-fixture.php" );' +
			'foreach ( array( "caller-a", "caller-b", "chat-assistant", "seo-writer" ) as $slug ) { wp_mkdir_p( WP_PLUGIN_DIR . "/$slug" ); copy( $src . "caller.php", WP_PLUGIN_DIR . "/$slug/$slug.php" ); }' +
			'wp_mkdir_p( WP_PLUGIN_DIR . "/caller-direct" ); copy( $src . "caller-direct.php", WP_PLUGIN_DIR . "/caller-direct/caller-direct.php" );'
	);
	wp( 'plugin', 'activate', 'caller-a', 'caller-b', 'caller-direct', 'chat-assistant', 'seo-writer' );
}

/** Removes the fixtures again. */
function removeFixtures() {
	try {
		wp( 'plugin', 'deactivate', 'caller-a', 'caller-b', 'caller-direct', 'chat-assistant', 'seo-writer' );
	} catch ( error ) {
		// Not active.
	}
	wpEval(
		'@unlink( WPMU_PLUGIN_DIR . "/axle-ai-guard-fixture.php" );' +
			'foreach ( array( "caller-a", "caller-b", "caller-direct", "chat-assistant", "seo-writer" ) as $slug ) { @unlink( WP_PLUGIN_DIR . "/$slug/$slug.php" ); @rmdir( WP_PLUGIN_DIR . "/$slug" ); }'
	);
}

/** No settings, no calls, no counters, no alerts. */
function reset() {
	for ( const name of OPTIONS ) {
		try {
			wp( 'option', 'delete', name );
		} catch ( error ) {
			// Not set.
		}
	}
	wp( 'db', 'query', 'TRUNCATE TABLE wp_axle_ai_guard_calls' );
	wp( 'db', 'query', 'TRUNCATE TABLE wp_axle_ai_guard_usage' );
	wp( 'db', 'query', 'TRUNCATE TABLE wp_axle_ai_guard_prompts' );
	wp( 'db', 'query', "DELETE FROM wp_options WHERE option_name LIKE '\\_transient\\_%axle\\_ai\\_guard%'" );
	wp( 'cache', 'flush' );
	// The fixture provider costs 10 per million input and 20 per million output tokens:
	// 120 in and 30 out = 1,800 micro-units (USD 0.0018) per call.
	wp( 'axle-ai-guard', 'prices', 'set', 'aag-fixture/fixture-model', '--input=10', '--output=20' );
	// Every test call comes from the same anonymous IP address; its limit has a test of its own.
	wp( 'axle-ai-guard', 'limit', 'set', 'ips', '--per-minute=0' );
}

/**
 * fetch() that retries once when Apache closed an idle keep-alive socket under it.
 * That error comes before the request is read, so nothing runs twice.
 *
 * @param {string} url     URL.
 * @param {Object} options fetch() options.
 * @return {Promise<Response>} Response.
 */
async function request( url, options = {} ) {
	try {
		return await fetch( url, options );
	} catch ( error ) {
		if ( ! /other side closed|ECONNRESET|socket/i.test( String( error.cause || error ) ) ) {
			throw error;
		}
		return fetch( url, options );
	}
}

/**
 * Makes an AI call through a fixture caller plugin, as a visitor.
 *
 * @param {string} caller Caller plugin folder.
 * @param {string} prompt Prompt.
 * @param {Object} extra  More parameters, such as system.
 * @return {Promise<Object>} ok, text, or code, message and status.
 */
async function call( caller, prompt = 'Write a short tagline for a coffee shop.', extra = {} ) {
	const response = await request( `${ BASE_URL }/wp-json/${ caller }/v1/run`, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json' },
		body: JSON.stringify( { prompt, ...extra } ),
	} );
	return response.json();
}

/** Whether a caller plugin sees text generation as supported. */
async function supported( caller ) {
	const response = await request( `${ BASE_URL }/wp-json/${ caller }/v1/supported` );
	return ( await response.json() ).supported;
}

/** Calls the direct caller, which skips the AI Client. */
async function direct() {
	return ( await request( `${ BASE_URL }/wp-json/caller-direct/v1/run` ) ).json();
}

/** A site with some history: calls from three plugins, a redaction, blocks, an error and a budget. */
async function seedActivity() {
	installFixtures();
	reset();
	wp( 'axle-ai-guard', 'settings', 'set', 'alert_email', '0' );
	wp( 'axle-ai-guard', 'settings', 'set', 'store_preview', '1' );
	wp( 'axle-ai-guard', 'budget', 'set', 'global', '--monthly-cost=25' );
	wp( 'axle-ai-guard', 'budget', 'set', 'plugin:chat-assistant', '--monthly-cost=0.009' );
	wp( 'axle-ai-guard', 'budget', 'set', 'plugin:seo-writer', '--monthly-tokens=5000' );

	for ( let i = 0; i < 4; i++ ) {
		await call( 'chat-assistant', 'A visitor asks: do you ship to Pune?' );
	}
	await call( 'chat-assistant', 'Summarise the order of admin@example.com for support.' );
	await call( 'seo-writer', 'Write a meta description for the Ethiopian Yirgacheffe page.' );
	await call( 'seo-writer', 'Write a meta description for the decaf page.' );
	await call( 'seo-writer', 'Debug this user row: admin $P$BIRXVj/ZG0YRiBH8gnRy0chBx67WuK/ admin@example.com' );
	wp( 'option', 'update', 'aag_fixture_fail', '1' );
	await call( 'caller-a', 'Suggest three blog post titles.' );
	wp( 'option', 'delete', 'aag_fixture_fail' );
	await call( 'caller-a', 'Suggest three blog post titles.' );

	return { calls: Number( wp( 'db', 'query', 'SELECT COUNT(*) FROM wp_axle_ai_guard_calls', '--skip-column-names' ) ) };
}

/** Engages one kill switch on top of the seeded data. */
function killChat() {
	wp( 'axle-ai-guard', 'kill', '--plugin=chat-assistant', '--reason=Loop on the contact page' );
}

/** Back to a clean site. */
function unseed() {
	reset();
}

module.exports = { installFixtures, removeFixtures, reset, call, supported, direct, seedActivity, killChat, unseed, OPTIONS };
