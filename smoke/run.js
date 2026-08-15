import fs from 'node:fs';
import path from 'node:path';
import { createRunDirectory } from './lib/artifacts.js';
import { isAllowedConsoleError } from './lib/console-allowlist.js';
import { prepareLab, probeEnvironment, waitForSite } from './lib/environment.js';
import { SkipError } from './lib/journey.js';
import { ensureLicense, teardownLicense } from './lib/license.js';
import { report } from './lib/reporter.js';
import { openSession } from './lib/session.js';
import { JOURNEYS } from './journeys/index.js';

function parseArguments( argv ) {
	const wanted = [];

	for ( const argument of argv ) {
		const match = /^--journey=(.+)$/.exec( argument );

		if ( match ) {
			wanted.push(
				...match[ 1 ]
					.split( ',' )
					.map( ( id ) => id.trim().toUpperCase() )
					.filter( Boolean )
			);
		}
	}

	return { wanted };
}

const { wanted } = parseArguments( process.argv.slice( 2 ) );
const known = JOURNEYS.map( ( journey ) => journey.id );
const unknown = wanted.filter( ( id ) => ! known.includes( id ) );

if ( unknown.length ) {
	console.error(
		`[smoke] unknown journey ${ unknown.join( ', ' ) } (known: ${ known.join( ', ' ) })`
	);
	process.exit( 2 );
}

const selected = wanted.length
	? JOURNEYS.filter( ( journey ) => wanted.includes( journey.id ) )
	: JOURNEYS;

const { stamp, dir } = createRunDirectory();
const log = ( message ) => console.log( `[smoke] ${ message }` );
const startedAt = Date.now();

const summary = {
	startedAt: new Date( startedAt ).toISOString(),
	artifacts: dir,
	selected: selected.map( ( journey ) => journey.id ),
	preflight: {},
	license: {},
	journeys: [],
};

let session = null;
let license = { managed: false };
let failed = false;

try {
	const site = await waitForSite( log );
	log( `site responded ${ site.status } after ${ site.attempts } attempt(s)` );

	await prepareLab();

	let env = await probeEnvironment();

	const describe = ( probed ) =>
		`WordPress ${ probed.wpVersion } (${ probed.locale }), ` +
		`AI Forge ${ probed.pluginActive ? 'active' : 'INACTIVE' }, ` +
		`license ${ probed.license.status }, media index ${ probed.mediaIndexed }, ` +
		`AI search ${
			probed.search.available ? 'available' : `unavailable (${ probed.search.reason })`
		}`;

	log( describe( env ) );

	license = await ensureLicense( log );
	summary.license = license;

	// A license the harness just installed changes what the journeys can
	// reach, so the skip decisions are taken on the state the browser will
	// actually meet rather than on the one the run started from.
	if ( license.managed ) {
		env = await probeEnvironment();
		log( describe( env ) );
	}

	summary.preflight = { site, environment: env };

	session = await openSession( { dir, log } );

	for ( const journey of selected ) {
		const journeyStart = Date.now();
		const shots = [];
		const record = {
			id: journey.id,
			name: journey.name,
			status: 'passed',
			reason: null,
			error: null,
		};

		session.startJourney();
		log( `--- ${ journey.id } ${ journey.name }` );

		try {
			await journey.run( {
				page: session.page,
				session,
				env,
				log: ( message ) => log( `    ${ message }` ),
				shot: async ( name ) => {
					const file = await session.shot( journey.id, name );
					shots.push( file );

					return file;
				},
			} );
		} catch ( error ) {
			if ( error instanceof SkipError ) {
				record.status = 'skipped';
				record.reason = error.message;
			} else {
				record.status = 'failed';
				record.error = error.message;

				try {
					shots.push( await session.shot( journey.id, 'failure' ) );
				} catch {
					// A screenshot of a dead page is not worth a second failure.
				}
			}
		}

		const errors = session.consoleErrors();
		const blocking = errors.filter( ( entry ) => ! isAllowedConsoleError( entry ) );

		if ( record.status === 'passed' && blocking.length ) {
			record.status = 'failed';
			record.error =
				`${ blocking.length } console error(s): ` +
				blocking.map( ( entry ) => entry.text ).join( ' | ' );
		}

		record.consoleErrors = errors;
		record.blockingConsoleErrors = blocking;
		record.screenshots = shots;
		record.durationMs = Date.now() - journeyStart;

		summary.journeys.push( record );
		log(
			`${ journey.id } ${ record.status }` +
				`${ record.reason ? `: ${ record.reason }` : '' }` +
				`${ record.error ? `: ${ record.error }` : '' }`
		);
	}
} catch ( error ) {
	summary.preflight.error = error.message;
	log( `preflight failed: ${ error.message }` );
	failed = true;
} finally {
	if ( session ) {
		await session.close().catch( () => {} );
	}

	try {
		summary.license.teardown = await teardownLicense( license.managed, log );
	} catch ( error ) {
		summary.license.teardown = { error: error.message };
		log( `license teardown failed: ${ error.message }` );
		failed = true;
	}

	summary.finishedAt = new Date().toISOString();
	summary.durationMs = Date.now() - startedAt;
	summary.status =
		failed || summary.journeys.some( ( journey ) => journey.status === 'failed' )
			? 'failed'
			: 'passed';

	fs.writeFileSync( path.join( dir, 'summary.json' ), JSON.stringify( summary, null, 2 ) );
	report( summary, stamp, log );

	process.exit( summary.status === 'failed' ? 1 : 0 );
}
