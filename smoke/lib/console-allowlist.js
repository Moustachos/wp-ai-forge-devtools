/**
 * Known browser noise, allowed on every journey.
 *
 * This is the only allowlist in the harness. Anything a journey logs that does
 * not match an entry here fails that journey.
 */
const ALLOWED = [
	{
		// The gutenberg-blocks lab theme pulls assets from a remote sandbox
		// that answers without CORS headers. Lab-only, and not AI Forge's.
		pattern: /nemasandbox-rec\.vs20\.nematis\.net/,
		reason: 'gutenberg-blocks lab theme: CORS on remote sandbox assets',
	},
];

export function isAllowedConsoleError( { text, url } ) {
	const subject = `${ text || '' } ${ url || '' }`;

	return ALLOWED.some( ( entry ) => entry.pattern.test( subject ) );
}

export function allowlistReasons() {
	return ALLOWED.map( ( entry ) => entry.reason );
}
