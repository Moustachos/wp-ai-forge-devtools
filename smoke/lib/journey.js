export class SkipError extends Error {
	constructor( reason ) {
		super( reason );
		this.name = 'SkipError';
	}
}

export class AssertionFailure extends Error {
	constructor( message ) {
		super( message );
		this.name = 'AssertionFailure';
	}
}

/**
 * Leave the journey without failing it.
 *
 * A lab that carries no provider or no index cannot host the journeys that
 * need them, and a red suite for that would say nothing about the product.
 */
export function skip( reason ) {
	throw new SkipError( reason );
}

export function assertTrue( condition, message ) {
	if ( ! condition ) {
		throw new AssertionFailure( message );
	}
}

/**
 * Poll until the probe returns something truthy.
 *
 * Playwright's own waits cover elements; this covers page state only a script
 * can read, such as a store value or a rendered result count.
 */
export async function waitFor( probe, message, timeout = 20000 ) {
	const deadline = Date.now() + timeout;
	let last;

	while ( Date.now() < deadline ) {
		last = await probe();

		if ( last ) {
			return last;
		}

		await new Promise( ( resolve ) => setTimeout( resolve, 250 ) );
	}

	throw new AssertionFailure(
		`${ message } (timed out after ${ timeout }ms, last value ${ JSON.stringify( last ) })`
	);
}
