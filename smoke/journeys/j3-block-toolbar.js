import { CANVAS, ensureEditorPage, ensureImageBlock, realClick } from '../lib/editor.js';
import { assertTrue, expectVisible } from '../lib/journey.js';

const SUGGEST_LABEL = /Suggérer une image pertinente|Suggest a relevant image/i;

export default {
	id: 'J3',
	name: 'Block toolbar integration',

	async run( ctx ) {
		const { page, log, shot } = ctx;

		await ensureEditorPage( ctx );
		await ensureImageBlock( ctx );

		// The label, not the block itself: the centre of an empty image block
		// is its media library button, and clicking that opens a modal instead
		// of selecting the block.
		const label = page
			.frameLocator( CANVAS )
			.locator( '[data-type="core/image"] .components-placeholder__label' )
			.first();

		await realClick( page, label, 'the image block' );

		const toolbar = page.locator( '.block-editor-block-contextual-toolbar' );

		await expectVisible(
			toolbar,
			'clicking the image block surfaced no .block-editor-block-contextual-toolbar'
		);

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
