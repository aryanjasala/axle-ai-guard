// Records .github/media/demo.gif for the README. Run on demand: npm run demo
const { test, expect, wp } = require( './utils' );
const { prepareSite, recordDemo } = require( './utils/media' );
const { seedActivity, unseed, removeFixtures } = require( './seed' );

test( 'demo: a user email is redacted, then a runaway plugin is switched off', async ( { browser } ) => {
	test.setTimeout( 240_000 );
	prepareSite( 'Bean There Coffee' );
	await seedActivity();

	try {
		await recordDemo( browser, async ( page, demo ) => {
			await page.goto( '/wp-admin/tools.php?page=axle-ai-guard' );
			await expect( page.locator( '.aag-callers tbody tr' ) ).toHaveCount( 3 );
			await demo.start();
			await demo.pause( 1500 );

			// Which plugins call AI, what it costs, and the budgets.
			await demo.moveTo( page.locator( 'tr[data-caller="chat-assistant"] .aag-bar' ) );
			await demo.pause( 1500 );

			// The last call: the user's email never reached the provider.
			await demo.click( page.locator( '.aag-tabs' ).getByRole( 'link', { name: 'Calls' } ), 300 );
			const chat = page.locator( '.aag-calls-table tbody tr', { hasText: 'chat-assistant' } ).first();
			await demo.click( chat.locator( '.aag-detail-button' ), 300 );
			await expect( page.locator( '.aag-detail' ) ).toContainText( 'user_email: 1' );
			await demo.moveTo( page.locator( '.aag-detail dd', { hasText: 'user_email: 1' } ).last() );
			await demo.pause( 2500 );
			await demo.click( page.getByRole( 'button', { name: 'Close' } ), 300 );

			// Switch the chatbot off.
			await demo.click( page.locator( '.aag-tabs' ).getByRole( 'link', { name: 'Overview' } ), 300 );
			await demo.click( page.locator( 'tr[data-caller="chat-assistant"]' ).getByRole( 'button', { name: 'Switch off' } ), 300 );
			await expect( page.locator( 'tr[data-caller="chat-assistant"]' ) ).toContainText( 'Switched off' );
			await demo.moveTo( page.locator( 'tr[data-caller="chat-assistant"]' ) );
			await demo.pause( 2500 );
		} );
		expect( JSON.parse( wp( 'option', 'get', 'axle_ai_guard_kill', '--format=json' ) ).plugin ).toHaveProperty( 'chat-assistant' );
	} finally {
		unseed();
		removeFixtures();
	}
} );
