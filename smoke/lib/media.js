import { TIMEOUTS } from './config.js';
import { AssertionFailure, skip, waitFor } from './journey.js';

const MODAL_TAG = 'data-aiforge-smoke-modal';

/** Above this many matches the library renders its first page and waits. */
const PAGED_RESULTS = 40;

export const GRID_SCOPE = '';
export const MODAL_SCOPE = `[${ MODAL_TAG }] `;

/**
 * Skip rather than fail when the lab cannot host an AI search.
 *
 * A bare lab has no service and no index, and a red suite for that would say
 * nothing about the product. A lab where the plugin is not loaded at all is a
 * different matter: those journeys must go red.
 */
export function requireSearch( env ) {
	if ( ! env.pluginActive ) {
		return;
	}

	if ( ! env.search.available ) {
		skip( `AI media search unavailable: ${ env.search.reason }` );
	}
}

export function requireIndex( env, what ) {
	if ( env.pluginActive && env.mediaIndexed === 0 ) {
		skip( `media index is empty: ${ what }` );
	}
}

/**
 * The switch is a stored per-user preference and may already be on, so its
 * state is read before it is touched and the caller can give it back.
 */
export async function setSearchEnabled( page, scope, enabled ) {
	const toggle = page.locator( `${ scope }.aiforge-search-toggle` ).first();

	await toggle.waitFor( { state: 'visible' } );

	const previous = ( await toggle.getAttribute( 'aria-checked' ) ) === 'true';

	if ( previous !== enabled ) {
		await toggle.click();
		await waitFor(
			async () => ( await toggle.getAttribute( 'aria-checked' ) ) === String( enabled ),
			`the AI search switch never reached aria-checked="${ enabled }"`
		);
	}

	return previous;
}

/**
 * The chip labels, without the remove glyph the chip carries in its own span.
 */
export async function chipLabels( page, scope ) {
	const texts = await page
		.locator( `${ scope }.aiforge-media-search__chips .aiforge-chip__label` )
		.allInnerTexts();

	return texts.map( ( text ) => text.replace( /\s+/g, ' ' ).trim() );
}

/**
 * Wait for the band to carry an answered search rather than a coverage or a
 * loading line, and return what it says.
 */
export async function waitForSearchSummary( page, scope, what ) {
	return waitFor(
		async () => {
			const band = page.locator( `${ scope }.aiforge-media-search__band` ).first();

			if ( ( await band.count() ) === 0 ) {
				return null;
			}

			const text = await page
				.locator( `${ scope }.aiforge-media-search__summary` )
				.first()
				.innerText();

			return /r(é|e)sultat|result/i.test( text ) ? text.trim() : null;
		},
		what,
		TIMEOUTS.search
	);
}

/**
 * Wait for the library to render as many tiles as the band says it matched.
 *
 * The band answers as soon as the search does, while the tiles arrive with the
 * collection refetch behind it, so a screenshot taken on the summary alone
 * shows an empty library under a result count.
 */
export async function waitForResults( page, scope, summary, what ) {
	const expected = Number( ( /\d+/.exec( summary ) || [ 0 ] )[ 0 ] );

	await waitFor(
		async () => {
			const rendered = await page.locator( `${ scope }.attachments .attachment` ).count();

			// The library pages its tiles in on scroll, so a large set is only
			// asked to have started rendering.
			return expected > PAGED_RESULTS ? rendered > 0 : rendered === expected;
		},
		`${ what } (the band reported ${ expected })`,
		TIMEOUTS.search
	);

	return expected;
}

export async function runSearch( page, scope, query ) {
	const input = page.locator( `${ scope }input#media-search-input` ).first();

	await input.fill( query );
	await input.press( 'Enter' );

	const summary = await waitForSearchSummary(
		page,
		scope,
		`the AI search never reported a result for "${ query }"`
	);

	const count = await waitForResults(
		page,
		scope,
		summary,
		`the library never rendered the results of "${ query }"`
	);

	return { summary, count, chips: await chipLabels( page, scope ) };
}

/**
 * Find the media modal the user is looking at, and mark it.
 *
 * wp.media.frame is the last frame created, not the visible one, so the walk
 * starts from the wp.media.frames registry and only adds wp.media.frame as one
 * more candidate. Both halves earn their place: the registry is what saves the
 * media grid once an attachment detail modal has been opened over it, and the
 * fallback is the only thing that resolves Gutenberg's image-block modal, which
 * registers no named frame at all.
 *
 * The resolved modal is tagged so every later selector is scoped to it by
 * something derived from the frame walk rather than from the DOM order.
 */
export async function tagActiveMediaModal( page ) {
	const resolved = await page.evaluate( ( tag ) => {
		document.querySelectorAll( `[${ tag }]` ).forEach( ( node ) => node.removeAttribute( tag ) );

		const media = window.wp && window.wp.media;

		if ( ! media ) {
			return { found: false, reason: 'wp.media is not loaded' };
		}

		const registry = media.frames || {};
		const candidates = Object.keys( registry )
			.map( ( key ) => registry[ key ] )
			.filter( Boolean );
		const fromRegistry = candidates.length;

		if ( media.frame && candidates.indexOf( media.frame ) === -1 ) {
			candidates.push( media.frame );
		}

		const visible = candidates.filter(
			( frame ) => frame && frame.el && frame.el.isConnected && frame.el.checkVisibility()
		);

		for ( const frame of visible ) {
			const modal = frame.el.closest( '.media-modal' );

			if ( modal && modal.checkVisibility() ) {
				modal.setAttribute( tag, '1' );

				return {
					found: true,
					frames: candidates.length,
					visible: visible.length,
					fromRegistry,
				};
			}
		}

		return {
			found: false,
			reason: 'no visible media frame',
			frames: candidates.length,
			fromRegistry,
		};
	}, MODAL_TAG );

	if ( ! resolved.found ) {
		throw new AssertionFailure( `could not resolve the open media modal: ${ resolved.reason }` );
	}

	return { selector: MODAL_SCOPE, ...resolved };
}
