import { CANVAS, ensureEditorPage, ensureImageBlock, realClick } from '../lib/editor.js';
import { assertTrue } from '../lib/journey.js';

const SUGGEST_LABEL = /Suggérer une image pertinente|Suggest a relevant image/i;

export default {
	id: 'J3',
	name: 'Block toolbar integration',

	async run( ctx ) {
		const { page, log, shot } = ctx;

		await ensureEditorPage( ctx );
		await ensureImageBlock( ctx );

		const block = page.frameLocator( CANVAS ).locator( '[data-type="core/image"]' ).first();

		await realClick( page, block, 'the image block' );

		const toolbar = page.locator( '.block-editor-block-contextual-toolbar' );

		await toolbar.waitFor( { state: 'visible' } );

		const labels = await toolbar
			.locator( 'button[aria-label]' )
			.evaluateAll( ( buttons ) => buttons.map( ( button ) => button.getAttribute( 'aria-label' ) ) );

		log( `toolbar buttons: ${ labels.join( ', ' ) }` );

		assertTrue(
			labels.some( ( label ) => SUGGEST_LABEL.test( label || '' ) ),
			`no media suggestion button in the block toolbar (found: ${ labels.join( ', ' ) || 'none' })`
		);

		await shot( 'block-toolbar' );
	},
};
