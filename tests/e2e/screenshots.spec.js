// Regenerates .wordpress-org/screenshot-N.png (readme.txt "== Screenshots ==" and README.md).
// Run on demand: npm run screenshots
const { test, expect, wp } = require( './utils' );
const { STORAGE_STATE } = require( './utils/config' );
const { prepareSite, shot } = require( './utils/media' );
const { seedActivity, unseed, removeFixtures } = require( './seed' );

const SCREEN = '/wp-admin/tools.php?page=axle-ai-guard';

test.describe( 'Screenshots', () => {
	let context;
	let page;

	test.beforeAll( async ( { browser } ) => {
		test.setTimeout( 180_000 );
		prepareSite( 'Bean There Coffee' );
		await seedActivity();
		wp( 'axle-ai-guard', 'settings', 'set', 'store_preview', '1' );

		context = await browser.newContext( { storageState: STORAGE_STATE, viewport: { width: 1280, height: 900 }, deviceScaleFactor: 2 } );
		page = await context.newPage();
	} );

	test.afterAll( async () => {
		await context.close();
		unseed();
		removeFixtures();
	} );

	test( '1: the overview', async () => {
		await page.goto( SCREEN );
		await expect( page.locator( '.aag-callers tbody tr' ) ).toHaveCount( 3 );
		await shot( page, 1, { fullPage: true } );
	} );

	test( '2: the calls', async () => {
		await page.goto( `${ SCREEN }&tab=calls` );
		await expect( page.locator( '.aag-calls-table tbody tr' ) ).toHaveCount( 10 );
		await shot( page, 2 );
	} );

	test( '3: call details', async () => {
		await page.goto( `${ SCREEN }&tab=calls&plugin=chat-assistant` );
		await page.locator( '.aag-calls-table tbody tr' ).first().getByRole( 'button', { name: /Details/ } ).click();
		await expect( page.locator( '.aag-detail' ) ).toContainText( 'user_email: 1' );
		await shot( page, 3 );
	} );

	test( '4: budgets and limits', async () => {
		await page.goto( `${ SCREEN }&tab=budgets` );
		await expect( page.locator( '.aag-policy' ).first() ).toBeVisible();
		await shot( page, 4 );
	} );

	test( '5: data rules and the test box', async () => {
		await page.goto( `${ SCREEN }&tab=rules` );
		await page.getByLabel( 'Paste text. Nothing is sent to any AI provider.' ).fill( 'Order 1042 for admin@example.com, phone +91 98765 43210. Card 4111 1111 1111 1111.' );
		await page.getByRole( 'button', { name: 'Test', exact: true } ).click();
		await expect( page.locator( '.aag-test-result' ) ).toBeVisible();
		await shot( page, 5, { fullPage: true } );
	} );

	test( '6: prices', async () => {
		wp( 'axle-ai-guard', 'prices', 'reset' );
		await page.goto( `${ SCREEN }&tab=prices` );
		await expect( page.locator( '.aag-prices' ) ).toBeVisible();
		await shot( page, 6 );
	} );
} );
