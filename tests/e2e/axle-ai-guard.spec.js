const { test, expect, wp, appPasswordClient } = require( './utils' );
const { installFixtures, removeFixtures, reset, call, supported, direct } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-ai-guard';

/** Runs SQL and returns the rows as arrays of strings. */
const sql = ( query ) =>
	wp( 'db', 'query', query, '--skip-column-names' )
		.split( '\n' )
		.filter( Boolean )
		.map( ( line ) => line.split( '\t' ) );
const one = ( query ) => ( sql( query )[ 0 ] || [] )[ 0 ];
const option = ( name ) => {
	try {
		return JSON.parse( wp( 'option', 'get', name, '--format=json' ) );
	} catch ( error ) {
		return null;
	}
};
const lastCall = () => JSON.parse( wp( 'axle-ai-guard', 'calls', '--limit=1', '--format=json' ) )[ 0 ];
const callCount = () => Number( one( 'SELECT COUNT(*) FROM wp_axle_ai_guard_calls' ) );
const bodies = () => option( 'aag_fixture_bodies' ) || [];

test.describe( 'Axle AI Guard', () => {
	test.beforeAll( () => {
		installFixtures();
	} );

	test.beforeEach( () => {
		reset();
	} );

	test.afterAll( () => {
		reset();
		removeFixtures();
	} );

	test( 'a call is logged with its caller, tokens and cost', async ( { page } ) => {
		const result = await call( 'caller-a' );
		expect( result.ok ).toBe( true );

		const row = lastCall();
		expect( row ).toMatchObject( { status: 'ok', caller: 'caller-a', provider: 'aag-fixture', model: 'fixture-model', tokens: 150, http: 200, path: 'client' } );
		// 120 input tokens at 10 and 30 output tokens at 20 per million.
		expect( one( 'SELECT cost_micros FROM wp_axle_ai_guard_calls ORDER BY id DESC LIMIT 1' ) ).toBe( '1800' );
		expect( one( "SELECT tokens FROM wp_axle_ai_guard_usage WHERE subject = 'plugin:caller-a' AND period LIKE 'm:%'" ) ).toBe( '150' );

		await page.goto( `${ SCREEN }&tab=calls` );
		const first = page.locator( '.aag-calls-table tbody tr' ).first();
		await expect( first ).toContainText( 'caller-a' );
		await expect( first ).toContainText( 'USD 0.0018' );

		await first.getByRole( 'button', { name: /Details/ } ).click();
		const dialog = page.getByRole( 'dialog', { name: 'Call details' } );
		await expect( dialog ).toContainText( 'plugins/caller-a/caller-a.php' );
		await expect( dialog ).toContainText( '120 in, 30 out' );
		await dialog.getByRole( 'button', { name: 'Close' } ).click();
		await expect( dialog ).toBeHidden();
	} );

	test( 'a money budget blocks the next call and alerts at the soft limit', async ( { page } ) => {
		wp( 'axle-ai-guard', 'budget', 'set', 'plugin:caller-a', '--monthly-cost=0.0018' );

		expect( ( await call( 'caller-a' ) ).ok ).toBe( true );
		const blocked = await call( 'caller-a' );
		expect( blocked ).toMatchObject( { ok: false, code: 'prompt_prevented', status: 503 } );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'budget', caller: 'caller-a' } );
		expect( ( await call( 'caller-b' ) ).ok ).toBe( true );

		const actions = option( 'aag_fixture_actions' ).map( ( entry ) => entry[ 0 ] + ' ' + entry[ 1 ] );
		expect( actions ).toContain( 'axle_ai_guard_budget_soft_reached plugin:caller-a' );
		expect( actions ).toContain( 'axle_ai_guard_budget_hard_reached plugin:caller-a' );
		expect( option( 'aag_fixture_mail' ).map( ( mail ) => mail.subject ).join( '\n' ) ).toContain( 'plugin:caller-a used 100% of its monthly cost budget' );

		await page.goto( SCREEN );
		await expect( page.locator( 'tr[data-caller="caller-a"] .aag-bar-full' ) ).toContainText( 'USD 0.0018 / USD 0.0018' );
	} );

	test( 'the budget screen saves limits per plugin', async ( { page } ) => {
		await call( 'caller-a' );
		await page.goto( `${ SCREEN }&tab=budgets` );
		const fieldset = page.locator( 'fieldset[data-subject="plugin:caller-a"]' );
		await fieldset.getByLabel( 'Tokens per day' ).fill( '1000' );
		await fieldset.getByLabel( 'Money per month' ).fill( '12.5' );
		await fieldset.getByLabel( 'Front end' ).check();
		await page.getByRole( 'button', { name: 'Save budgets and limits' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Budgets and limits saved.' );

		expect( option( 'axle_ai_guard_policies' )[ 'plugin:caller-a' ] ).toMatchObject( { daily_tokens: 1000, monthly_cost: 12500000, contexts: [ 'front' ] } );

		// Only the front end is allowed now; the fixture calls through REST.
		expect( ( await call( 'caller-a' ) ).ok ).toBe( false );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'context' } );
	} );

	test( 'a rate limit blocks the third call in a minute', async () => {
		wp( 'axle-ai-guard', 'limit', 'set', 'plugin:caller-a', '--per-minute=2' );
		expect( ( await call( 'caller-a' ) ).ok ).toBe( true );
		expect( ( await call( 'caller-a' ) ).ok ).toBe( true );
		expect( await call( 'caller-a' ) ).toMatchObject( { ok: false, code: 'prompt_prevented' } );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'rate' } );
		expect( ( await call( 'caller-b' ) ).ok ).toBe( true );
	} );

	test( 'visitors from one IP address get the anonymous rate limit', async () => {
		wp( 'axle-ai-guard', 'limit', 'set', 'ips', '--per-minute=1' );
		expect( ( await call( 'caller-a' ) ).ok ).toBe( true );
		expect( ( await call( 'caller-b' ) ).ok ).toBe( false );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'rate', caller: 'caller-b' } );
		expect( one( 'SELECT ip FROM wp_axle_ai_guard_calls ORDER BY id DESC LIMIT 1' ) ).toMatch( /\.0$|::$/ );
	} );

	test( 'kill switches per plugin, from WP-CLI, the screen and an ability', async ( { page } ) => {
		wp( 'axle-ai-guard', 'kill', '--plugin=caller-b', '--reason=Testing' );
		expect( await call( 'caller-b' ) ).toMatchObject( { ok: false, code: 'prompt_prevented', status: 503 } );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'kill:plugin', caller: 'caller-b' } );
		expect( ( await call( 'caller-a' ) ).ok ).toBe( true );
		expect( await supported( 'caller-b' ) ).toBe( false );

		await page.goto( SCREEN );
		await page.locator( 'tr[data-caller="caller-b"]' ).getByRole( 'button', { name: 'Turn on' } ).click();
		await expect( page.locator( 'tr[data-caller="caller-b"]' ).getByRole( 'button', { name: 'Switch off' } ) ).toBeVisible();
		expect( option( 'axle_ai_guard_kill' ).plugin ).toEqual( [] );
		expect( ( await call( 'caller-b' ) ).ok ).toBe( true );

		const agent = await appPasswordClient( 'admin', 'AI Guard e2e agent' );
		try {
			const engaged = await agent.call( 'POST', '/wp-abilities/v1/abilities/axle-ai-guard/engage-kill-switch/run', { input: { scope: 'plugin', subject: 'caller-a', reason: 'Agent saw a loop' } } );
			expect( engaged.status ).toBe( 200 );
			expect( engaged.body ).toMatchObject( { scope: 'plugin', subject: 'caller-a', changed: true, on: true } );
			expect( option( 'axle_ai_guard_kill' ).plugin[ 'caller-a' ].reason ).toBe( 'Agent saw a loop' );
			expect( ( await call( 'caller-a' ) ).ok ).toBe( false );

			const usage = await agent.call( 'GET', '/wp-abilities/v1/abilities/axle-ai-guard/get-usage/run?input[period]=day&input[by]=plugin' );
			expect( usage.status ).toBe( 200 );
			expect( usage.body.groups.map( ( group ) => group.name ) ).toContain( 'caller-a' );

			const limits = await agent.call( 'GET', '/wp-abilities/v1/abilities/axle-ai-guard/get-limits/run' );
			expect( limits.body.kill.map( ( entry ) => entry.subject ) ).toContain( 'caller-a' );

			// No ability turns a switch back off.
			const list = await agent.call( 'GET', '/wp-abilities/v1/abilities?category=axle-ai-guard&per_page=50' );
			const names = list.body.map( ( ability ) => ability.name ).sort();
			expect( names ).toEqual( [ 'axle-ai-guard/engage-kill-switch', 'axle-ai-guard/get-limits', 'axle-ai-guard/get-usage', 'axle-ai-guard/list-calls', 'axle-ai-guard/test-data-rules' ] );
		} finally {
			await agent.dispose();
		}

		wp( 'axle-ai-guard', 'resume', '--all' );
		expect( ( await call( 'caller-a' ) ).ok ).toBe( true );
	} );

	test( 'the global switch hides AI from support checks without logging them', async ( { page } ) => {
		expect( await supported( 'caller-a' ) ).toBe( true );
		await page.goto( SCREEN );
		await page.getByLabel( 'Reason' ).fill( 'Provider outage' );
		await page.getByRole( 'button', { name: 'Switch all AI off' } ).click();
		await expect( page.locator( '.aag-kill-notice' ) ).toBeVisible();
		await expect( page.locator( '#wp-admin-bar-axle-ai-guard' ) ).toContainText( 'AI is off' );
		expect( option( 'axle_ai_guard_kill' ).global.reason ).toBe( 'Provider outage' );

		const before = callCount();
		expect( await supported( 'caller-a' ) ).toBe( false );
		expect( callCount() ).toBe( before );

		expect( await call( 'caller-a' ) ).toMatchObject( { ok: false, code: 'prompt_prevented' } );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'kill:global' } );

		await page.getByRole( 'button', { name: 'Turn AI back on' } ).click();
		await expect( page.locator( '.aag-kill-notice' ) ).toHaveCount( 0 );
		expect( await supported( 'caller-a' ) ).toBe( true );
	} );

	test( 'a user email is redacted before the request leaves the site', async () => {
		const result = await call( 'caller-a', 'Write a welcome note for admin@example.com, who joined today.' );
		expect( result.ok ).toBe( true );

		const sent = bodies().pop();
		expect( sent ).toContain( '[redacted:user_email]' );
		expect( sent ).not.toContain( 'admin@example.com' );

		const id = one( 'SELECT id FROM wp_axle_ai_guard_calls ORDER BY id DESC LIMIT 1' );
		const detail = JSON.parse( wp( 'axle-ai-guard', 'call', id ) );
		expect( detail.profile.findings ).toEqual( { user_email: 1, email: 1 } );
		expect( detail.profile.actions.user_email ).toBe( 'redact' );
		expect( detail.profile.redacted ).toEqual( { user_email: 1 } );
		expect( option( 'aag_fixture_actions' ) ).toContainEqual( [ 'axle_ai_guard_data_redacted', 'user_email', 1 ] );
	} );

	test( 'a data rule in block mode stops the call before any request', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=rules` );
		await page.getByLabel( 'Emails of registered users' ).selectOption( 'block' );
		await page.getByRole( 'button', { name: 'Save data rules' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Data rules saved.' );
		expect( option( 'axle_ai_guard_rules' ).detectors.user_email ).toBe( 'block' );

		const sent = bodies().length;
		const result = await call( 'caller-a', 'Write a welcome note for admin@example.com.' );
		expect( result ).toMatchObject( { ok: false, code: 'prompt_builder_error' } );
		expect( result.message ).toContain( 'user_email' );
		expect( bodies().length ).toBe( sent );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'data:user_email' } );
	} );

	test( 'a password hash is blocked by default', async () => {
		const result = await call( 'caller-a', 'Why does $P$BIRXVj/ZG0YRiBH8gnRy0chBx67WuK/ not log in?' );
		expect( result.ok ).toBe( false );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'data:password_hash' } );
		expect( bodies() ).toEqual( [] );
	} );

	test( 'the test box runs the rules with no AI call', async ( { page } ) => {
		await page.goto( `${ SCREEN }&tab=rules` );
		await page.getByLabel( 'Paste text. Nothing is sent to any AI provider.' ).fill( 'Call +91 98765 43210 or mail admin@example.com' );
		await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
		const result = page.locator( '.aag-test-result' );
		await expect( result ).toContainText( 'Emails of registered users: 1 found, mode redact' );
		await expect( result ).toContainText( 'Phone numbers: 1 found, mode log' );
		await expect( result.locator( 'pre' ) ).toContainText( '[redacted:user_email]' );
		expect( callCount() ).toBe( 0 );
		expect( bodies() ).toEqual( [] );
	} );

	test( 'a provider error closes the row as an error', async () => {
		wp( 'option', 'update', 'aag_fixture_fail', '1' );
		const result = await call( 'caller-a' );
		expect( result ).toMatchObject( { ok: false, code: 'prompt_upstream_server_error' } );
		expect( lastCall() ).toMatchObject( { status: 'error', http: 500 } );
		expect( one( "SELECT COUNT(*) FROM wp_axle_ai_guard_calls WHERE status = 'pending'" ) ).toBe( '0' );
	} );

	test( 'direct calls are logged and stopped by the kill switch when monitoring is on', async ( { page } ) => {
		expect( await direct() ).toEqual( { ai: 200, other: 200 } );
		expect( callCount() ).toBe( 0 );

		await page.goto( `${ SCREEN }&tab=settings` );
		await page.getByLabel( 'Watch requests to known AI provider hosts that skip the AI Client' ).check();
		await page.getByRole( 'button', { name: 'Save settings' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Settings saved.' );
		expect( option( 'axle_ai_guard_settings' ).direct_monitoring ).toBe( true );

		expect( await direct() ).toEqual( { ai: 200, other: 200 } );
		expect( lastCall() ).toMatchObject( { status: 'ok', path: 'direct', caller: 'caller-direct', model: 'fixture-direct', tokens: 150 } );

		wp( 'axle-ai-guard', 'kill' );
		expect( await direct() ).toEqual( { ai: 'axle_ai_guard_blocked', other: 200 } );
		expect( lastCall() ).toMatchObject( { status: 'blocked', path: 'direct', rule: 'kill:global' } );

		// AI Client calls are not logged twice.
		wp( 'axle-ai-guard', 'resume' );
		await call( 'caller-a' );
		expect( one( "SELECT COUNT(*) FROM wp_axle_ai_guard_calls WHERE path = 'direct' AND caller_slug = 'caller-a'" ) ).toBe( '0' );
	} );

	test( 'prices can be edited and costs recalculated', async ( { page } ) => {
		await call( 'caller-a' );
		await page.goto( `${ SCREEN }&tab=prices` );
		const row = page.locator( 'tr', { has: page.locator( 'input[value="aag-fixture/fixture-model"]' ) } );
		await row.getByLabel( /: input$/ ).fill( '20' );
		await page.getByRole( 'button', { name: 'Save prices' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Prices saved.' );
		expect( option( 'axle_ai_guard_prices' ).models[ 'aag-fixture/fixture-model' ].input ).toBe( 20 );

		await page.getByRole( 'button', { name: 'Recalculate the last 30 days' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'recalculated' );
		// 120 x 20 + 30 x 20.
		expect( one( 'SELECT cost_micros FROM wp_axle_ai_guard_calls ORDER BY id DESC LIMIT 1' ) ).toBe( '3000' );
		expect( one( "SELECT cost_micros FROM wp_axle_ai_guard_usage WHERE subject = 'global' AND period LIKE 'm:%'" ) ).toBe( '3000' );
	} );

	test( 'a new caller is held until approved when the settings say so', async ( { page } ) => {
		wp( 'axle-ai-guard', 'settings', 'set', 'new_caller', 'deny' );
		expect( await call( 'caller-b' ) ).toMatchObject( { ok: false, code: 'prompt_prevented' } );
		expect( lastCall() ).toMatchObject( { status: 'blocked', rule: 'caller' } );
		expect( option( 'axle_ai_guard_seen_callers' )[ 'caller-b' ].status ).toBe( 'pending' );

		await page.goto( `${ SCREEN }&tab=budgets` );
		await page.locator( '.aag-approvals tr', { hasText: 'caller-b' } ).getByRole( 'button', { name: 'Approve' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Caller updated.' );
		expect( option( 'axle_ai_guard_seen_callers' )[ 'caller-b' ].status ).toBe( 'approved' );
		expect( ( await call( 'caller-b' ) ).ok ).toBe( true );
	} );

	test( 'JavaScript hooks: columns, cards, cost format and the kill switch action', async ( { page } ) => {
		await call( 'caller-a' );
		await page.goto( `${ SCREEN }&tab=calls` );
		await expect( page.locator( '.aag-calls-table tbody tr' ) ).toHaveCount( 1 );
		await page.evaluate( () =>
			window.wp.hooks.addFilter( 'axleAiGuard.callColumns', 'e2e', ( columns ) => [ ...columns, { key: 'bytes', label: 'Bytes sent', render: ( call ) => String( call.request_bytes ) } ] )
		);
		await expect( page.locator( '.aag-calls-table thead th[data-column="bytes"]' ) ).toHaveText( 'Bytes sent' );
		expect( Number( await page.locator( '.aag-calls-table tbody td[data-column="bytes"]' ).innerText() ) ).toBeGreaterThan( 0 );

		await page.goto( SCREEN );
		await page.evaluate( () => {
			window.wp.hooks.addFilter( 'axleAiGuard.formatCost', 'e2e', ( text, micros ) => 'cost:' + micros );
			window.wp.hooks.addFilter( 'axleAiGuard.overviewCards', 'e2e', ( cards ) => cards.filter( ( card ) => 'day-cost' === card.id ) );
		} );
		await expect( page.locator( '.aag-card' ) ).toHaveCount( 1 );
		await expect( page.locator( '.aag-card-value' ) ).toHaveText( 'cost:1800' );

		await page.evaluate( () =>
			window.wp.hooks.addAction( 'axleAiGuard.killSwitchToggled', 'e2e', ( result ) => window.sessionStorage.setItem( 'aagToggled', JSON.stringify( result ) ) )
		);
		await page.locator( 'tr[data-caller="caller-a"]' ).getByRole( 'button', { name: 'Switch off' } ).click();
		await expect( page.locator( 'tr[data-caller="caller-a"]' ).getByRole( 'button', { name: 'Turn on' } ) ).toBeVisible();
		expect( JSON.parse( await page.evaluate( () => window.sessionStorage.getItem( 'aagToggled' ) ) ) ).toMatchObject( { scope: 'plugin', subject: 'caller-a', on: true, changed: true } );
		expect( option( 'axle_ai_guard_kill' ).plugin[ 'caller-a' ] ).toBeTruthy();
	} );

	test( 'personal data export and erase cover AI calls', async () => {
		const id = Number( wp( 'user', 'create', 'aagvisitor', 'aagvisitor@example.com', '--porcelain' ) );
		try {
			wp( 'eval', `wp_set_current_user( ${ id } ); wp_ai_client_prompt( "Hello" )->using_provider( "aag-fixture" )->generate_text();` );
			expect( one( `SELECT COUNT(*) FROM wp_axle_ai_guard_calls WHERE user_id = ${ id }` ) ).toBe( '1' );
			const exported = JSON.parse( wp( 'eval', 'echo wp_json_encode( AryanX\\Axle\\AiGuard\\Plugin::instance() ? ( new AryanX\\Axle\\AiGuard\\Privacy( new AryanX\\Axle\\AiGuard\\Calls() ) )->export( "aagvisitor@example.com" ) : null );' ) );
			expect( exported.data ).toHaveLength( 1 );
			expect( exported.data[ 0 ].group_id ).toBe( 'axle-ai-guard-calls' );

			wp( 'eval', '( new AryanX\\Axle\\AiGuard\\Privacy( new AryanX\\Axle\\AiGuard\\Calls() ) )->erase( "aagvisitor@example.com" );' );
			expect( one( `SELECT COUNT(*) FROM wp_axle_ai_guard_calls WHERE user_id = ${ id }` ) ).toBe( '0' );
			expect( callCount() ).toBe( 1 );
		} finally {
			wp( 'user', 'delete', String( id ), '--yes' );
		}
	} );
} );
