/**
 * Acceptance #9: pricing → signup → order-pay → "You're in" in one run; the pending path shows the poller and
 * resolves when the order flips to paid. Runs against a site in sandbox mode with the WitsValley Test gateway.
 */
import { test, expect, type Page } from '@playwright/test';

async function signUpForBasic( page: Page, label: string ): Promise< string > {
	await page.goto( '/membership/' );
	await page.getByRole( 'button', { name: /Join Basic/ } ).click();
	const email = `e2e.${ label }.${ Date.now() }.${ Math.floor( Math.random() * 1000 ) }@example.test`;
	await page.getByLabel( 'Full name' ).fill( 'E2E Member' );
	await page.getByLabel( 'Email' ).fill( email );
	await page.getByLabel( 'Phone' ).fill( '+256700123456' );
	await page.getByLabel( 'Password', { exact: true } ).fill( 'e2eTestPass123' );
	await page.locator( '.wvm-check' ).first().click(); // terms
	await Promise.all( [ page.waitForURL( /order-pay/, { timeout: 30_000 } ), page.getByRole( 'button', { name: /Continue to payment/ } ).click() ] );
	await expect( page.locator( '.wvm-pay' ) ).toBeVisible();
	await expect( page.locator( '.wvm-steps' ) ).toContainText( 'Pay' );
	return email;
}

test( 'happy path: sign up, pay with the Test gateway, land in the dashboard as an active Basic member', async ( { page } ) => {
	await signUpForBasic( page, 'approve' );
	await page.locator( 'input[name=payment_method][value=wvm_test]' ).check( { force: true } );
	await page.locator( 'input[name=wvm_test_outcome][value=approve]' ).check();
	await Promise.all( [ page.waitForURL( /\/dashboard\//, { timeout: 45_000 } ), page.locator( '#place_order' ).click() ] );
	await expect( page.getByText( /You're in/ ) ).toBeVisible( { timeout: 20_000 } );
	await expect( page.locator( '.wvm-plancard__name' ) ).toHaveText( /Basic/ );
	await expect( page.locator( '.wvm-plancard__state' ) ).toContainText( /Active/ );
	expect( page.url() ).not.toContain( 'welcome=' ); // query string cleaned
} );

test( 'pending path: poller waits, then resolves when the payment is confirmed', async ( { page } ) => {
	await signUpForBasic( page, 'pending' );
	await page.locator( 'input[name=payment_method][value=wvm_test]' ).check( { force: true } );
	await page.locator( 'input[name=wvm_test_outcome][value=pending]' ).check();
	await Promise.all( [ page.waitForURL( /\/dashboard\//, { timeout: 45_000 } ), page.locator( '#place_order' ).click() ] );
	await expect( page.getByText( /Confirming your payment/i ) ).toBeVisible( { timeout: 15_000 } );

	// The Test gateway confirms ~15s later via Action Scheduler; nudge WP-Cron so it runs on any host.
	const deadline = Date.now() + 90_000;
	while ( Date.now() < deadline ) {
		await page.request.get( '/wp-cron.php?doing_wp_cron=' + Date.now() ).catch( () => null );
		if ( await page.getByText( /You're in/ ).isVisible().catch( () => false ) ) {
			break;
		}
		await page.waitForTimeout( 5_000 );
	}
	await expect( page.getByText( /You're in/ ) ).toBeVisible( { timeout: 30_000 } );
	await expect( page.locator( '.wvm-plancard__state' ) ).toContainText( /Active/ );
} );

test( 'no dead ends: storefront and account URLs redirect into the membership flow', async ( { page } ) => {
	for ( const [ from, to ] of [
		[ '/shop/', /\/membership\// ],
		[ '/cart/', /\/membership\// ],
		[ '/my-account/', /\/dashboard\// ],
		[ '/pay/0000000000000000deadbeef/', /\/dashboard\// ],
	] as const ) {
		await page.goto( from );
		await expect( page ).toHaveURL( to );
	}
} );
