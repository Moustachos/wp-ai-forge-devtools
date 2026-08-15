import { spawn } from 'node:child_process';
import { HARNESS_PHP, LAB_DIR } from './config.js';

const MARKER = 'XRESULT:';

function run( args ) {
	return new Promise( ( resolve, reject ) => {
		const child = spawn( 'npx', args, {
			cwd: LAB_DIR,
			// .cmd shims cannot be spawned without a shell on Windows; the
			// arguments below carry nothing for that shell to mangle.
			shell: true,
			env: { ...process.env, MSYS_NO_PATHCONV: '1' },
		} );

		let stdout = '';
		let stderr = '';

		child.stdout.on( 'data', ( chunk ) => ( stdout += chunk ) );
		child.stderr.on( 'data', ( chunk ) => ( stderr += chunk ) );
		child.on( 'error', reject );
		child.on( 'close', ( code ) => resolve( { code, stdout, stderr } ) );
	} );
}

/**
 * Run one harness command inside the wp-env cli container.
 *
 * The script path is WordPress-root-relative, because an absolute container
 * path reaches the container rewritten when the caller is Git Bash. The answer
 * is found by its marker rather than by taking stdout whole, because wp-env
 * prints the command text around it.
 */
export async function wpEval( command, args = [] ) {
	const { code, stdout, stderr } = await run( [
		'wp-env',
		'run',
		'cli',
		'--',
		'wp',
		'eval-file',
		HARNESS_PHP,
		command,
		...args,
	] );

	const line = ( stdout + '\n' + stderr )
		.split( /\r?\n/ )
		.reverse()
		.find( ( candidate ) => candidate.trim().startsWith( MARKER ) );

	if ( ! line ) {
		throw new Error(
			`wp eval-file ${ command } ${ args.join( ' ' ) } produced no ${ MARKER } line (exit ${ code }).\n` +
				`${ stdout }\n${ stderr }`
		);
	}

	return JSON.parse( line.trim().slice( MARKER.length ) );
}
