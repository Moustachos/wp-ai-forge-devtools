import { wpEval } from './wp-cli.js';

/**
 * Give the run a license without ever touching a real one.
 *
 * The plugin gates its editor integration on an active license, so a bare lab
 * needs a fake key to have anything to assert. A lab that already carries a
 * license is left exactly as it is, and so is a lab where the plugin is not
 * loaded at all.
 */
export async function ensureLicense( log ) {
	const probed = await wpEval( 'license', [ 'probe' ] );

	if ( ! probed.plugin_active ) {
		log( 'license unmanaged: AI Forge is not active' );

		return { before: probed.status, managed: false, note: 'AI Forge is not active' };
	}

	if ( probed.status !== 'not_activated' ) {
		log( `license left alone: already ${ probed.status }` );

		return {
			before: probed.status,
			managed: false,
			note: `existing license (${ probed.status })`,
		};
	}

	const installed = await wpEval( 'license', [ 'install' ] );
	log( `fake license installed (state ${ installed.status }), removed again in teardown` );

	return {
		before: probed.status,
		managed: true,
		note: 'fake license installed by the harness',
		state: installed.status,
	};
}

/**
 * Always called, including after a crash.
 *
 * The container side removes the key only when it is the harness's own, so a
 * real license activated while a run was in flight cannot be destroyed here.
 */
export async function teardownLicense( managed, log ) {
	if ( ! managed ) {
		return { removed: false, state: 'untouched' };
	}

	const result = await wpEval( 'license', [ 'teardown' ] );
	log( `fake license removed (state now ${ result.status })` );

	return { removed: true, state: result.status, hasKey: !! result.has_key };
}
