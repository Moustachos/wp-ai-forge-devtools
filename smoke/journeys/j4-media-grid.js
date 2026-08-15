import { BASE_URL, SEARCH_QUERY, TIMEOUTS } from '../lib/config.js';
import { assertTrue, waitFor } from '../lib/journey.js';
import {
	GRID_SCOPE,
	chipLabels,
	requireIndex,
	requireSearch,
	runSearch,
	setSearchEnabled,
	waitForResults,
	waitForSearchSummary,
} from '../lib/media.js';

export default {
	id: 'J4',
	name: 'Media grid and AI search',

	async run( { page, env, log, shot } ) {
		requireIndex( env, 'no badges and no AI search to exercise' );

		// The full path: /upload.php alone lands on the front end.
		await page.goto( `${ BASE_URL }/wp-admin/upload.php?mode=grid`, {
			waitUntil: 'domcontentloaded',
		} );
		await page.waitForSelector( '.attachments-browser', { timeout: TIMEOUTS.navigation } );

		const badges = await waitFor( async () => {
			const count = await page.locator( '.aiforge-grid-badge' ).count();

			return count > 0 ? count : null;
		}, 'no .aiforge-grid-badge rendered in the media grid' );

		log( `${ badges } index badge(s) on the grid` );
		await shot( 'grid-badges' );

		requireSearch( env );

		const wasEnabled = await setSearchEnabled( page, GRID_SCOPE, true );

		try {
			const { summary, count, chips } = await runSearch( page, GRID_SCOPE, SEARCH_QUERY );

			log( `search summary: ${ summary } (${ count } tile(s) rendered)` );
			log( `facet chips: ${ chips.join( ' / ' ) || 'none' }` );
			await shot( 'ai-search-results' );

			assertTrue(
				chips.length > 0,
				`the AI search returned no interpreted-facet chips for "${ SEARCH_QUERY }"`
			);

			const removed = chips[ 0 ];

			await page
				.locator( '.aiforge-media-search__chips .aiforge-chip--removable' )
				.first()
				.click();

			// The filter has to be gone and the search has to have answered
			// again: an empty chip list alone would also describe a search
			// that broke on the way back.
			const after = await waitFor(
				async () => {
					const current = await chipLabels( page, GRID_SCOPE );

					return current.includes( removed ) ? null : current;
				},
				`removing the chip "${ removed }" never dropped it from the filters`,
				TIMEOUTS.search
			);

			const refreshed = await waitForSearchSummary(
				page,
				GRID_SCOPE,
				`removing the chip "${ removed }" never produced a new result line`
			);
			const refreshedCount = await waitForResults(
				page,
				GRID_SCOPE,
				refreshed,
				`the grid never re-rendered after removing "${ removed }"`
			);

			log( `chips after removing "${ removed }": ${ after.join( ' / ' ) || 'none' }` );
			log( `summary after removal: ${ refreshed } (${ refreshedCount } tile(s) rendered)` );
			await shot( 'ai-search-chip-removed' );
		} finally {
			await setSearchEnabled( page, GRID_SCOPE, wasEnabled ).catch( () => {} );
		}
	},
};
