import { ensureEditorPage, selectDocumentTab } from '../lib/editor.js';
import { assertTrue } from '../lib/journey.js';

export default {
	id: 'J2',
	name: 'Editor document panels',

	async run( ctx ) {
		const { page, log, shot } = ctx;

		await ensureEditorPage( ctx );
		await shot( 'canvas' );

		await selectDocumentTab( page );

		const panels = page.locator( '.aiforge-doc-panel' );

		await panels.first().waitFor( { state: 'visible' } );

		const titles = await page.locator( '.aiforge-doc-panel__title' ).allInnerTexts();

		log( `document panels: ${ titles.join( ', ' ) }` );

		assertTrue(
			titles.some( ( title ) => /Content Integrator/i.test( title ) ),
			`no Content Integrator document panel in the sidebar (found: ${
				titles.join( ', ' ) || 'none'
			})`
		);

		await shot( 'doc-panels' );
	},
};
