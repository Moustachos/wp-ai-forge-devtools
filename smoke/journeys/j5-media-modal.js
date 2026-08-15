import { SEARCH_QUERY } from '../lib/config.js';
import {
	CANVAS,
	MEDIA_LIBRARY_LABEL,
	ensureEditorPage,
	ensureImageBlock,
	realClick,
} from '../lib/editor.js';
import { assertTrue, expectVisible } from '../lib/journey.js';
import {
	MODAL_SCOPE,
	requireIndex,
	requireSearch,
	runSearch,
	setSearchEnabled,
	tagActiveMediaModal,
} from '../lib/media.js';

export default {
	id: 'J5',
	name: 'Gutenberg media modal search',

	async run( ctx ) {
		const { page, env, log, shot } = ctx;

		requireIndex( env, 'the modal has no AI search to exercise' );
		requireSearch( env );

		await ensureEditorPage( ctx );
		await ensureImageBlock( ctx );

		const button = page
			.frameLocator( CANVAS )
			.locator( '[data-type="core/image"] button' )
			.filter( { hasText: MEDIA_LIBRARY_LABEL } )
			.first();

		await realClick( page, button, 'the image block media library button' );

		await expectVisible(
			page.locator( '.media-modal' ).first(),
			'the image block media library button opened no .media-modal'
		);

		const modal = await tagActiveMediaModal( page );

		log(
			`resolved the open modal from ${ modal.frames } frame(s), ` +
				`${ modal.fromRegistry } of them registered in wp.media.frames`
		);

		await shot( 'modal-open' );

		const toggles = await page.locator( `${ MODAL_SCOPE }.aiforge-search-toggle` ).count();

		assertTrue( toggles === 1, `expected one AI search switch inside the modal, found ${ toggles }` );

		const wasEnabled = await setSearchEnabled( page, MODAL_SCOPE, true );

		try {
			const { summary, count, chips } = await runSearch( page, MODAL_SCOPE, SEARCH_QUERY );

			log( `modal search summary: ${ summary } (${ count } tile(s) rendered)` );
			log( `modal facet chips: ${ chips.join( ' / ' ) || 'none' }` );

			await shot( 'modal-ai-search' );
		} finally {
			await setSearchEnabled( page, MODAL_SCOPE, wasEnabled ).catch( () => {} );
		}
	},
};
