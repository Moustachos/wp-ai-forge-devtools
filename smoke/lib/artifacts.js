import fs from 'node:fs';
import path from 'node:path';
import { SMOKE_DIR } from './config.js';

export function createRunDirectory() {
	const stamp = new Date().toISOString().replace( /[:.]/g, '-' ).slice( 0, 19 );
	const dir = path.join( SMOKE_DIR, 'artifacts', stamp );

	fs.mkdirSync( dir, { recursive: true } );

	return { stamp, dir };
}
