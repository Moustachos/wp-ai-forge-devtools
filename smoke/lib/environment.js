import { BASE_URL, TIMEOUTS } from './config.js';
import { wpEval } from './wp-cli.js';

const sleep = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

/**
 * Wait for the site to answer with something other than maintenance mode.
 *
 * A core or translation update flips WordPress into a ten-minute 503, which is
 * a state to wait out and say so, not a failure to report.
 */
export async function waitForSite( log ) {
	const startedAt = Date.now();
	const deadline = startedAt + TIMEOUTS.siteWait;
	let attempts = 0;
	let backoff = 2000;

	while ( Date.now() < deadline ) {
		attempts++;

		try {
			const response = await fetch( BASE_URL, { redirect: 'manual' } );

			if ( response.status !== 503 ) {
				return { status: response.status, attempts, waitedMs: Date.now() - startedAt };
			}

			log( `site answered 503 (maintenance mode), retrying in ${ backoff }ms` );
		} catch ( error ) {
			log( `site unreachable (${ error.message }), retrying in ${ backoff }ms` );
		}

		await sleep( backoff );
		backoff = Math.min( backoff * 2, 20000 );
	}

	throw new Error(
		`${ BASE_URL } never left maintenance mode within ${ TIMEOUTS.siteWait / 1000 }s (${ attempts } attempts)`
	);
}

export async function probeEnvironment() {
	const raw = await wpEval( 'environment' );

	return {
		pluginActive: !! raw.plugin_active,
		wpVersion: raw.wp_version,
		locale: raw.locale,
		license: { status: raw.license.status, hasKey: !! raw.license.has_key },
		providers: raw.providers,
		mediaIndexed: Number( raw.media_indexed || 0 ),
		search: {
			available: !! raw.search.available,
			reason: raw.search.reason || null,
			provider: raw.search.provider || null,
		},
	};
}

/**
 * Neutralise the confirm_admin_email interstitial before the browser opens.
 */
export async function prepareLab() {
	const raw = await wpEval( 'prepare' );

	return { adminEmailLifespan: Number( raw.admin_email_lifespan || 0 ) };
}
