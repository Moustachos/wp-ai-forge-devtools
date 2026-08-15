import path from 'node:path';
import { chromium } from 'playwright';
import { ADMIN_PASSWORD, ADMIN_USER, BASE_URL, TIMEOUTS, VIEWPORT } from './config.js';
import { AssertionFailure } from './journey.js';

const LOGIN_ATTEMPTS = 3;

/**
 * Log in and land on wp-admin, or say exactly why not.
 *
 * A submitted form can bounce straight back to the login screen when the test
 * cookie did not survive the round trip, which is worth one more try; a wrong
 * password or a blocked interstitial is not, and both name themselves.
 */
async function login( page, log ) {
	let landed = '';
	let notice = '';

	for ( let attempt = 1; attempt <= LOGIN_ATTEMPTS; attempt++ ) {
		await page.goto( `${ BASE_URL }/wp-login.php`, { waitUntil: 'domcontentloaded' } );
		await page.fill( '#user_login', ADMIN_USER );
		await page.fill( '#user_pass', ADMIN_PASSWORD );
		await page.click( '#wp-submit' );

		try {
			await page.waitForURL( ( url ) => ! url.pathname.endsWith( '/wp-login.php' ), {
				timeout: TIMEOUTS.action,
				waitUntil: 'domcontentloaded',
			} );
		} catch {
			// Falls through to the diagnostics below.
		}

		landed = page.url();

		// The interstitial is neutralised in preflight by pushing
		// admin_email_lifespan a year out; landing on it means that step did
		// not take, and clicking through would hide the real problem.
		if ( landed.includes( 'confirm_admin_email' ) ) {
			throw new AssertionFailure(
				'login landed on the confirm_admin_email interstitial: the preflight admin_email_lifespan update did not apply'
			);
		}

		if ( landed.includes( '/wp-admin/' ) ) {
			log( `logged in as ${ ADMIN_USER }${ attempt > 1 ? ` (attempt ${ attempt })` : '' }` );

			return;
		}

		notice = ( await page.locator( '#login_error, .notice' ).allInnerTexts() )
			.join( ' ' )
			.replace( /\s+/g, ' ' )
			.trim();

		log( `login attempt ${ attempt } stayed on ${ landed }${ notice ? `: ${ notice }` : '' }` );
	}

	throw new AssertionFailure(
		`login did not reach wp-admin after ${ LOGIN_ATTEMPTS } attempts, landed on ${ landed }` +
			`${ notice ? ` (${ notice })` : '' }`
	);
}

/**
 * One browser, one context, one page, logged in once for the whole run.
 */
export async function openSession( { dir, log } ) {
	const browser = await chromium.launch( { headless: process.env.SMOKE_HEADED !== '1' } );
	const context = await browser.newContext( { viewport: VIEWPORT } );

	context.setDefaultTimeout( TIMEOUTS.action );
	context.setDefaultNavigationTimeout( TIMEOUTS.navigation );

	const page = await context.newPage();

	let bucket = [];
	let counter = 0;

	page.on( 'console', ( message ) => {
		if ( message.type() === 'error' ) {
			bucket.push( { text: message.text(), url: message.location().url } );
		}
	} );
	page.on( 'pageerror', ( error ) => {
		bucket.push( { text: `pageerror: ${ error.message }`, url: page.url() } );
	} );

	try {
		await login( page, log );
	} catch ( error ) {
		await browser.close();
		throw error;
	}

	return {
		page,
		browser,
		context,
		// Journey fixtures memoise on the session, so a full run prepares the
		// editor once and a single-journey run prepares its own.
		editorReady: false,
		startJourney() {
			bucket = [];
			counter = 0;
		},
		consoleErrors() {
			return bucket.slice();
		},
		async shot( id, name ) {
			counter++;

			const file = `${ id.toLowerCase() }-${ String( counter ).padStart( 2, '0' ) }-${ name }.png`;

			await page.screenshot( { path: path.join( dir, file ) } );

			return file;
		},
		async close() {
			await context.close();
			await browser.close();
		},
	};
}
