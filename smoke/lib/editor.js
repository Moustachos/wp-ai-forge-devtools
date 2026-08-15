import { BASE_URL, TIMEOUTS } from './config.js';
import { AssertionFailure, assertTrue, waitFor } from './journey.js';

export const CANVAS = 'iframe[name="editor-canvas"]';
export const MEDIA_LIBRARY_LABEL = /Médiathèque|Media Library/i;

/**
 * Click where the element actually is, after proving nothing covers it.
 *
 * The block toolbar only appears for a real pointer event inside the canvas;
 * neither selectBlock nor a synthetic click surfaces it. Playwright's own click
 * would qualify, except that the editor keeps invisible overlays over the
 * canvas which make its actionability check retry until it times out. So the
 * coordinates are driven directly, and the hit test replaces the check it
 * skips: a covered target fails here instead of clicking the cover.
 */
export async function realClick( page, locator, what ) {
	await locator.waitFor( { state: 'visible' } );
	await locator.scrollIntoViewIfNeeded();

	const box = await locator.boundingBox();

	assertTrue( box !== null, `${ what } has no bounding box` );

	const onTarget = await locator.evaluate( ( node ) => {
		const rect = node.getBoundingClientRect();
		const top = node.ownerDocument.elementFromPoint(
			rect.x + rect.width / 2,
			rect.y + rect.height / 2
		);

		return top === node || node.contains( top );
	} );

	if ( ! onTarget ) {
		throw new AssertionFailure( `${ what } is covered by another element at its own centre` );
	}

	await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
}

async function dismissBlockingModals( page ) {
	await page.evaluate( () => {
		const preferences = window.wp?.data?.dispatch( 'core/preferences' );

		if ( ! preferences ) {
			return;
		}

		for ( const scope of [ 'core', 'core/edit-post', 'core/edit-site' ] ) {
			try {
				preferences.set( scope, 'welcomeGuide', false );
				preferences.set( scope, 'welcomeGuideTemplate', false );
				preferences.set( scope, 'enableChoosePatternModal', false );
			} catch {
				// Scopes come and go between releases; the ones that exist are
				// enough.
			}
		}
	} );

	// A new page opens the start-pattern chooser, which is already mounted by
	// the time the preference lands and swallows every click on the canvas
	// until it is closed.
	for ( let attempt = 0; attempt < 6; attempt++ ) {
		if ( ( await page.locator( '.components-modal__frame' ).count() ) === 0 ) {
			return;
		}

		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 500 );
	}

	throw new AssertionFailure( 'a modal is still covering the editor after six Escape presses' );
}

/**
 * Show the sidebar's document tab.
 *
 * PluginDocumentSettingPanel only mounts while that tab is showing, and
 * selecting a block switches the sidebar to the block tab, which unmounts every
 * document panel. The id suffix is stable across locales; the label is not.
 */
export async function selectDocumentTab( page ) {
	const tab = page.locator( '[role="tab"][id$="edit-post/document"]' );

	await tab.waitFor( { state: 'visible' } );
	await tab.click();
	await page.waitForTimeout( 500 );
}

export async function ensureEditorPage( ctx ) {
	const { page, session, log } = ctx;

	if ( session.editorReady ) {
		return { canvas: page.frameLocator( CANVAS ), created: false };
	}

	await page.goto( `${ BASE_URL }/wp-admin/post-new.php?post_type=page`, {
		waitUntil: 'domcontentloaded',
	} );
	await page.waitForSelector( CANVAS, { timeout: TIMEOUTS.editor } );

	await dismissBlockingModals( page );

	const canvas = page.frameLocator( CANVAS );

	await canvas.locator( '.editor-post-title__input' ).first().click();
	await page.keyboard.type( 'AI Forge smoke page' );
	await page.keyboard.press( 'Enter' );
	await page.keyboard.type( 'A paragraph typed by the browser smoke harness.' );

	await waitFor(
		() =>
			page.evaluate( () => {
				const title = wp.data.select( 'core/editor' ).getEditedPostAttribute( 'title' );
				const blocks = wp.data.select( 'core/block-editor' ).getBlocks();

				return title && blocks.some( ( block ) => block.name === 'core/paragraph' )
					? { title }
					: null;
			} ),
		'the typed title and paragraph never reached the editor store'
	);

	session.editorReady = true;
	log( 'editor page prepared (title and paragraph typed in the canvas)' );

	return { canvas, created: true };
}

export async function ensureImageBlock( ctx ) {
	const { page } = ctx;

	const present = await page.evaluate( () =>
		wp.data
			.select( 'core/block-editor' )
			.getBlocks()
			.some( ( block ) => block.name === 'core/image' )
	);

	if ( ! present ) {
		await page.evaluate( () => {
			wp.data
				.dispatch( 'core/block-editor' )
				.insertBlocks( wp.blocks.createBlock( 'core/image' ) );
		} );
	}

	await page
		.frameLocator( CANVAS )
		.locator( '[data-type="core/image"]' )
		.first()
		.waitFor( { state: 'visible' } );
}
