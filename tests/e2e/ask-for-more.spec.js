// Round 3: a blocked person is told why and can ask for more; an admin allows them for today.
// Also the visitor address (forwarded headers are never trusted unless the site names one).
const { test, expect, wp, appPasswordClient, logInAs, openEditor } = require( './utils' );
const { BASE_URL } = require( './utils/config' );
const { installFixtures, removeFixtures, reset, call, seedRunaway } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-ai-guard';
const PERSON = { login: 'aag_wall', email: 'aag_wall@example.com', role: 'author', name: 'Wanda Wall' };

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
const today = () => new Date().toISOString().slice( 0, 10 );
const grantRow = ( id ) => sql( `SELECT calls, tokens FROM wp_axle_ai_guard_usage WHERE period = 'g:${ today() }' AND subject = 'allow:${ id }'` )[ 0 ];

/** Uses up caller-a's monthly budget, so every next call of it is blocked. */
async function useUpBudget() {
	wp( 'axle-ai-guard', 'budget', 'set', 'plugin:caller-a', '--monthly-cost=0.0018' );
	expect( ( await call( 'caller-a' ) ).ok ).toBe( true );
}

test.describe( 'Ask for more', () => {
	let personId;

	test.beforeAll( () => {
		installFixtures();
		try {
			personId = Number( wp( 'user', 'create', PERSON.login, PERSON.email, `--role=${ PERSON.role }`, `--display_name=${ PERSON.name }`, '--user_pass=admin', '--porcelain' ) );
		} catch ( error ) {
			personId = Number( wp( 'user', 'get', PERSON.login, '--field=ID' ) );
		}
	} );

	test.beforeEach( () => {
		reset();
	} );

	test.afterAll( () => {
		reset();
		removeFixtures();
		try {
			wp( 'user', 'delete', PERSON.login, '--yes' );
		} catch ( error ) {
			// Not there.
		}
	} );

	test( 'the wall on a blocked call tells a person why in plain words, with no figures, and offers a request', async () => {
		await useUpBudget();

		const person = await appPasswordClient( PERSON.login, 'AI Guard wall' );
		try {
			const blocked = await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'Write an excerpt.' } );
			expect( blocked.status ).toBe( 503 );
			expect( blocked.body.code ).toBe( 'prompt_prevented' );
			const wall = blocked.body.data.axle_wall;
			expect( wall ).toMatchObject( { plugin: 'axle-ai-guard', action_label: 'Ask for more', request_route: 'axle-ai-guard/v1/access-requests' } );
			expect( wall.action_url ).toBe( '' );
			expect( wall.message ).toMatch( /allowance for this site is used up/ );

			// Nothing a person who cannot manage AI Guard should not see.
			const text = JSON.stringify( blocked.body );
			expect( text ).not.toMatch( /0\.0018|1800|budget_|monthly|caller-a|cost/i );
			expect( wall.message ).not.toMatch( /\d/ );
			expect( wall.capability ).toBe( 'axle_ai_guard_manage' );

			// A call that is not blocked has no wall.
			wp( 'axle-ai-guard', 'budget', 'set', 'plugin:caller-a', '--monthly-cost=0' );
			const fine = await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'Write an excerpt.' } );
			expect( fine.status ).toBe( 200 );
			expect( fine.body.ok ).toBe( true );
		} finally {
			await person.dispose();
		}

		// An administrator gets a link to the screen, not a request button.
		const admin = await appPasswordClient( 'admin', 'AI Guard wall admin' );
		try {
			wp( 'axle-ai-guard', 'budget', 'set', 'plugin:caller-a', '--monthly-cost=0.0018' );
			const blocked = await admin.call( 'POST', '/caller-a/v1/generate', { prompt: 'Write an excerpt.' } );
			expect( blocked.status ).toBe( 503 );
			expect( blocked.body.data.axle_wall ).toMatchObject( { action_label: 'Open AI Guard', request_route: '' } );
			expect( blocked.body.data.axle_wall.action_url ).toContain( 'page=axle-ai-guard' );
		} finally {
			await admin.dispose();
		}
	} );

	test( 'a rule an allowance cannot pass shows the reason and no request button', async () => {
		wp( 'axle-ai-guard', 'kill', '--plugin=caller-a', '--reason=Testing' );
		const person = await appPasswordClient( PERSON.login, 'AI Guard wall kill' );
		try {
			const blocked = await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'Write an excerpt.' } );
			const wall = blocked.body.data.axle_wall;
			expect( wall.message ).toMatch( /switched off/ );
			expect( wall.action_label ).toBe( '' );
			expect( wall.request_route ).toBe( '' );
			// Asking is refused: nothing that can be allowed was blocked.
			expect( ( await person.call( 'POST', '/axle-ai-guard/v1/access-requests' ) ).status ).toBe( 409 );
			// A grant does not pass a kill switch.
			wp( 'axle-ai-guard', 'allow', PERSON.login, '--calls=5' );
			expect( ( await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'Again.' } ) ).status ).toBe( 503 );
			expect( grantRow( personId ) ).toEqual( [ '0', '5' ] );
		} finally {
			await person.dispose();
		}
	} );

	test( 'in wp-admin the notice has Ask for more, and the admin allows the person for today with Undo', async ( { page, browser } ) => {
		await useUpBudget();

		// The person presses a button that fails. The shared script turns the error into a notice.
		const session = await logInAs( browser, PERSON.login );
		try {
			await session.page.goto( '/wp-admin/index.php' );
			await session.page.waitForFunction( () => window.wp && window.wp.apiFetch && window.axleWallReady );
			await session.page.evaluate( () => window.wp.apiFetch( { path: '/caller-a/v1/generate', method: 'POST', data: { prompt: 'Excerpt please.' } } ).catch( () => null ) );
			const notice = session.page.locator( '.axle-wall-notice' );
			await expect( notice ).toContainText( 'allowance for this site is used up' );
			await expect( notice ).not.toContainText( /\d/ );
			await notice.getByRole( 'button', { name: 'Ask for more' } ).click();
			await expect( session.page.locator( '.axle-wall-notice', { hasText: 'Request sent' } ) ).toBeVisible();

			const requests = option( 'axle_ai_guard_requests' );
			expect( requests ).toHaveLength( 1 );
			expect( requests[ 0 ] ).toMatchObject( { user_id: personId, rule: 'budget', caller: 'caller-a', status: 'open' } );

			// Asking again does not add another one.
			const ask = await session.page.evaluate( () => window.wp.apiFetch( { path: '/axle-ai-guard/v1/access-requests', method: 'POST' } ) );
			expect( ask.message ).toMatch( /already waiting/ );
			expect( option( 'axle_ai_guard_requests' ) ).toHaveLength( 1 );
		} finally {
			await session.context.close();
		}
		expect( option( 'aag_fixture_mail' ).map( ( mail ) => mail.subject ).join( '\n' ) ).toContain( 'Wanda Wall asked for more AI' );

		// The admin sees it and allows it.
		await page.goto( SCREEN );
		const row = page.locator( '.aag-request-table tbody tr' );
		await expect( row ).toContainText( 'Wanda Wall' );
		await expect( row ).toContainText( 'A budget' );
		await expect( row ).toContainText( 'caller-a' );
		await row.getByRole( 'button', { name: 'Allow for today' } ).click();
		await expect( page.locator( '.aag-done' ) ).toContainText( 'Allowed for today' );
		await expect( page.locator( '.aag-allowed' ) ).toContainText( 'Wanda Wall: 20 calls left today.' );
		expect( grantRow( personId ) ).toEqual( [ '0', '20' ] );
		expect( option( 'axle_ai_guard_requests' )[ 0 ] ).toMatchObject( { status: 'allowed', calls: 20 } );

		// Their call goes through now, takes one call, and is marked in its details.
		const person = await appPasswordClient( PERSON.login, 'AI Guard wall allowed' );
		try {
			const result = await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'Excerpt please.' } );
			expect( result.status ).toBe( 200 );
			expect( result.body.ok ).toBe( true );
			expect( grantRow( personId ) ).toEqual( [ '1', '20' ] );
			expect( one( 'SELECT profile FROM wp_axle_ai_guard_calls ORDER BY id DESC LIMIT 1' ) ).toContain( '"allowance":true' );
			// Nobody else gets through on it.
			expect( ( await call( 'caller-a' ) ).ok ).toBe( false );

			// Undo on the notice ends it and opens the request again.
			await page.goto( SCREEN );
			await page.locator( '.aag-allowed' ).getByRole( 'button', { name: 'Undo' } ).click();
			await expect( page.locator( '.aag-done' ) ).toContainText( 'allowance was ended' );
			expect( grantRow( personId ) ).toEqual( [ '1', '1' ] );
			expect( option( 'axle_ai_guard_requests' )[ 0 ].status ).toBe( 'open' );
			expect( ( await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'Excerpt please.' } ) ).status ).toBe( 503 );
		} finally {
			await person.dispose();
		}

		// Dismiss closes a request without allowing anything.
		await page.goto( SCREEN );
		await page.locator( '.aag-request-table' ).getByRole( 'button', { name: 'Dismiss' } ).click();
		await expect( page.locator( '.aag-done' ) ).toContainText( 'Request dismissed' );
		expect( option( 'axle_ai_guard_requests' )[ 0 ].status ).toBe( 'dismissed' );
	} );

	test( 'in the block editor the reason shows as a notice with Ask for more', async ( { page, browser } ) => {
		await useUpBudget();
		const postId = Number( wp( 'post', 'create', '--post_title=Wall test', '--post_status=draft', `--post_author=${ personId }`, '--porcelain' ) );
		const session = await logInAs( browser, PERSON.login );
		try {
			await openEditor( session.page, postId );
			await session.page.waitForFunction( () => window.axleWallReady );
			await session.page.evaluate( () => window.wp.apiFetch( { path: '/caller-a/v1/generate', method: 'POST', data: { prompt: 'Excerpt please.' } } ).catch( () => null ) );
			const notice = session.page.locator( '.components-notice' ).filter( { hasText: 'allowance for this site is used up' } );
			await expect( notice ).toBeVisible();
			await notice.getByRole( 'button', { name: 'Ask for more' } ).click();
			await expect( session.page.locator( '.components-snackbar' ) ).toContainText( 'Request sent' );
			expect( option( 'axle_ai_guard_requests' )[ 0 ] ).toMatchObject( { user_id: personId, status: 'open' } );
		} finally {
			await session.context.close();
			wp( 'post', 'delete', String( postId ), '--force' );
		}
	} );

	test( 'a grant cannot be used faster than it was given: a burst of parallel calls gets exactly its share', async () => {
		await useUpBudget();
		wp( 'axle-ai-guard', 'allow', PERSON.login, '--calls=3' );
		expect( grantRow( personId ) ).toEqual( [ '0', '3' ] );

		const person = await appPasswordClient( PERSON.login, 'AI Guard burst' );
		try {
			const results = await Promise.all( Array.from( { length: 14 }, () => person.call( 'POST', '/caller-a/v1/generate', { prompt: 'Burst.' } ) ) );
			const ok = results.filter( ( result ) => 200 === result.status ).length;
			expect( ok ).toBe( 3 );
			expect( results.filter( ( result ) => 503 === result.status ) ).toHaveLength( 11 );
			expect( grantRow( personId ) ).toEqual( [ '3', '3' ] );
			expect( one( `SELECT COUNT(*) FROM wp_axle_ai_guard_calls WHERE user_id = ${ personId } AND status = 'ok'` ) ).toBe( '3' );
		} finally {
			await person.dispose();
		}
	} );

	test( 'only a manager can allow, list or end; anyone signed in can ask; a visitor cannot', async ( { request } ) => {
		await useUpBudget();
		const person = await appPasswordClient( PERSON.login, 'AI Guard perms' );
		try {
			expect( ( await person.call( 'POST', '/axle-ai-guard/v1/access-requests' ) ).status ).toBe( 409 );
			await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'x' } );
			expect( ( await person.call( 'POST', '/axle-ai-guard/v1/access-requests' ) ).status ).toBe( 200 );
			const id = option( 'axle_ai_guard_requests' )[ 0 ].id;

			expect( ( await person.call( 'GET', '/axle-ai-guard/v1/access-requests' ) ).status ).toBe( 403 );
			expect( ( await person.call( 'POST', `/axle-ai-guard/v1/access-requests/${ id }/allow` ) ).status ).toBe( 403 );
			expect( ( await person.call( 'DELETE', `/axle-ai-guard/v1/allowances/${ personId }` ) ).status ).toBe( 403 );
			expect( grantRow( personId ) ).toBeUndefined();

			const guest = await request.post( `${ BASE_URL }/index.php?rest_route=/axle-ai-guard/v1/access-requests`, { headers: { Cookie: '' } } );
			expect( [ 401, 403 ] ).toContain( guest.status() );

			// The form handler needs the capability and a nonce too.
			const post = await request.post( `${ BASE_URL }/wp-admin/admin-post.php`, { form: { action: 'axle_ai_guard_requests', do: 'allow', request: id }, headers: { Cookie: '' }, maxRedirects: 0 } );
			expect( post.status() ).not.toBe( 200 );
			expect( option( 'axle_ai_guard_requests' )[ 0 ].status ).toBe( 'open' );
		} finally {
			await person.dispose();
		}

		const admin = await appPasswordClient( 'admin', 'AI Guard perms admin' );
		try {
			const listed = await admin.call( 'GET', '/axle-ai-guard/v1/access-requests' );
			expect( listed.body[ 0 ] ).toMatchObject( { user: 'Wanda Wall', rule: 'budget', status: 'open' } );
			const done = await admin.call( 'POST', `/axle-ai-guard/v1/access-requests/${ listed.body[ 0 ].id }/allow`, { calls: 5 } );
			expect( done.body ).toMatchObject( { status: 'allowed', calls: 5 } );
			expect( grantRow( personId ) ).toEqual( [ '0', '5' ] );
			expect( ( await admin.call( 'POST', `/axle-ai-guard/v1/access-requests/${ listed.body[ 0 ].id }/allow` ) ).status ).toBe( 409 );
			expect( ( await admin.call( 'DELETE', `/axle-ai-guard/v1/allowances/${ personId }` ) ).body.left ).toBe( 5 );
			expect( grantRow( personId ) ).toEqual( [ '0', '0' ] );

			// The ability lists requests, read only. There is no ability that allows.
			const ability = await admin.call( 'GET', '/wp-abilities/v1/abilities/axle-ai-guard/list-access-requests/run?input[status]=all' );
			expect( ability.status ).toBe( 200 );
			expect( ability.body.requests[ 0 ].user ).toBe( 'Wanda Wall' );
			const names = ( await admin.call( 'GET', '/wp-abilities/v1/abilities?per_page=100' ) ).body.map( ( entry ) => entry.name );
			expect( names.filter( ( name ) => name.startsWith( 'axle-ai-guard/' ) ).some( ( name ) => /allow|grant/.test( name ) ) ).toBe( false );
		} finally {
			await admin.dispose();
		}
	} );

	test( 'a grant belongs to its day and is removed with the person', async () => {
		wp( 'axle-ai-guard', 'allow', PERSON.login, '--calls=4' );
		// Move it to yesterday: it no longer counts.
		wp( 'db', 'query', `UPDATE wp_axle_ai_guard_usage SET period = 'g:2000-01-01' WHERE subject = 'allow:${ personId }'` );
		await useUpBudget();
		const person = await appPasswordClient( PERSON.login, 'AI Guard day' );
		try {
			expect( ( await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'x' } ) ).status ).toBe( 503 );
		} finally {
			await person.dispose();
		}
		// The nightly clean-up removes the old row.
		wp( 'axle-ai-guard', 'prune' );
		expect( one( "SELECT COUNT(*) FROM wp_axle_ai_guard_usage WHERE period LIKE 'g:%'" ) ).toBe( '0' );

		// Export lists the person's requests, the eraser removes them with their counters.
		wp( 'axle-ai-guard', 'allow', PERSON.login, '--calls=4' );
		wp( 'eval', `set_transient( 'axle_ai_guard_lastblock_${ personId }', array( 'rule' => 'budget', 'caller' => 'caller-a', 'at' => time() ) ); AryanX\\Axle\\AiGuard\\Plugin::instance()->requests()->ask( ${ personId } );` );
		const exported = JSON.parse( wp( 'eval', `echo wp_json_encode( ( new AryanX\\Axle\\AiGuard\\Privacy( new AryanX\\Axle\\AiGuard\\Calls() ) )->export( "${ PERSON.email }" ) );` ) );
		expect( exported.data.map( ( item ) => item.group_id ) ).toContain( 'axle-ai-guard-requests' );
		wp( 'eval', `( new AryanX\\Axle\\AiGuard\\Privacy( new AryanX\\Axle\\AiGuard\\Calls() ) )->erase( "${ PERSON.email }" );` );
		expect( option( 'axle_ai_guard_requests' ) ).toEqual( [] );
		expect( one( `SELECT COUNT(*) FROM wp_axle_ai_guard_usage WHERE subject IN ( 'allow:${ personId }', 'user:${ personId }' )` ) ).toBe( '0' );
	} );

	test( 'erasing a person clears their usage counters too', async () => {
		const person = await appPasswordClient( PERSON.login, 'AI Guard erase' );
		try {
			expect( ( await person.call( 'POST', '/caller-a/v1/generate', { prompt: 'x' } ) ).status ).toBe( 200 );
		} finally {
			await person.dispose();
		}
		expect( Number( one( `SELECT COUNT(*) FROM wp_axle_ai_guard_usage WHERE subject = 'user:${ personId }'` ) ) ).toBeGreaterThan( 0 );
		wp( 'eval', `( new AryanX\\Axle\\AiGuard\\Privacy( new AryanX\\Axle\\AiGuard\\Calls() ) )->erase( "${ PERSON.email }" );` );
		expect( one( `SELECT COUNT(*) FROM wp_axle_ai_guard_usage WHERE subject = 'user:${ personId }'` ) ).toBe( '0' );
		expect( one( "SELECT COUNT(*) FROM wp_axle_ai_guard_usage WHERE subject = 'global' AND period LIKE 'm:%'" ) ).toBe( '1' );
	} );
} );

test.describe( 'Visitor address', () => {
	test.beforeAll( () => {
		installFixtures();
	} );

	test.beforeEach( () => {
		reset();
		wp( 'axle-ai-guard', 'limit', 'set', 'ips', '--per-minute=2' );
	} );

	test.afterAll( () => {
		reset();
		removeFixtures();
	} );

	const withHeader = ( ip ) => ( { 'X-Real-IP': ip } );
	const run = async ( ip ) => {
		const send = () =>
			fetch( `${ BASE_URL }/wp-json/caller-a/v1/run`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', ...( ip ? withHeader( ip ) : {} ) },
				body: JSON.stringify( { prompt: 'Hello.' } ),
			} );
		// Apache can close an idle keep-alive socket before it reads the request: nothing ran, so retry once.
		const response = await send().catch( () => send() );
		return response.json();
	};

	test( 'a forwarded header is ignored unless the site names it: faking one does not escape the limit', async () => {
		const results = [];
		for ( const ip of [ '198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4' ] ) {
			results.push( ( await run( ip ) ).ok );
		}
		expect( results ).toEqual( [ true, true, false, false ] );
		expect( one( 'SELECT ip FROM wp_axle_ai_guard_calls ORDER BY id DESC LIMIT 1' ) ).not.toMatch( /^198\.51\.100/ );
	} );

	test( 'when the site names the header, visitors behind the proxy get a limit each', async () => {
		wp( 'option', 'update', 'aag_fixture_ip_header', 'HTTP_X_REAL_IP' );
		const results = [];
		for ( const ip of [ '198.51.100.1', '198.51.101.1', '198.51.102.1', '198.51.103.1' ] ) {
			results.push( ( await run( ip ) ).ok );
		}
		expect( results ).toEqual( [ true, true, true, true ] );
		expect( one( 'SELECT ip FROM wp_axle_ai_guard_calls ORDER BY id DESC LIMIT 1' ) ).toBe( '198.51.103.0' );

		// The same visitor is still limited.
		// The address is anonymised to its /24, so these three share a bucket of two.
		expect( ( await run( '198.51.110.7' ) ).ok ).toBe( true );
		expect( ( await run( '198.51.110.8' ) ).ok ).toBe( true );
		expect( ( await run( '198.51.110.9' ) ).ok ).toBe( false );
	} );
} );

test.describe( 'Admin bar', () => {
	test.beforeAll( () => {
		installFixtures();
	} );

	test.afterAll( () => {
		reset();
		removeFixtures();
	} );

	test( 'a flagged runaway shows in the admin bar even when nothing is switched off, and stays visible on a phone', async ( { page } ) => {
		reset();
		wp( 'axle-ai-guard', 'settings', 'set', 'runaway_mode', 'alert' );
		await seedRunaway();
		expect( wp( 'axle-ai-guard', 'switches', '--format=json' ) ).toBe( '[]' );

		await page.setViewportSize( { width: 1280, height: 800 } );
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '#wp-admin-bar-axle-ai-guard' ) ).toContainText( 'AI warning' );

		await page.setViewportSize( { width: 390, height: 800 } );
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '#wp-admin-bar-axle-ai-guard' ) ).toBeVisible();

		// Quiet again once it is dealt with.
		const id = option( 'axle_ai_guard_runaway' ).events[ 0 ].id;
		wp( 'axle-ai-guard', 'runaway', 'dismiss', id );
		await page.goto( '/wp-admin/index.php' );
		await expect( page.locator( '#wp-admin-bar-axle-ai-guard' ) ).toHaveCount( 0 );
	} );
} );
