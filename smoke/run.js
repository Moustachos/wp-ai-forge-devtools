import { prepareLab, probeEnvironment, waitForSite } from './lib/environment.js';

const log = ( message ) => console.log( `[smoke] ${ message }` );

const site = await waitForSite( log );
log( `site responded ${ site.status } after ${ site.attempts } attempt(s)` );

const prepared = await prepareLab();
log( `admin_email_lifespan pushed to ${ new Date( prepared.adminEmailLifespan * 1000 ).toISOString() }` );

const environment = await probeEnvironment();
log( JSON.stringify( environment, null, 2 ) );
