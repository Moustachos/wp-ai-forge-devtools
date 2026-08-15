import { BASE_URL } from '../lib/config.js';
import { assertTrue, expectVisible, waitFor } from '../lib/journey.js';

const MINIMUM_TEXT = 200;

export default {
	id: 'J1',
	name: 'Admin SPA renders',

	async run( { page, log, shot } ) {
		await page.goto( `${ BASE_URL }/wp-admin/admin.php?page=ai-forge`, {
			waitUntil: 'domcontentloaded',
		} );

		const roots = page.locator( '#aiforge-root' );

		await expectVisible(
			roots.first(),
			'admin.php?page=ai-forge rendered no #aiforge-root container'
		);

		// A mounted-but-empty root is what a broken bundle leaves behind, so the
		// assertion is on rendered content rather than on the element.
		const rendered = await waitFor(
			() =>
				page.evaluate( ( minimum ) => {
					const root = document.getElementById( 'aiforge-root' );

					if ( ! root || root.children.length === 0 ) {
						return null;
					}

					const text = root.innerText.trim();

					return text.length >= minimum
						? { children: root.children.length, length: text.length }
						: null;
				}, MINIMUM_TEXT ),
			`#aiforge-root never rendered ${ MINIMUM_TEXT } characters of content`,
			30000
		);

		log( `SPA rendered ${ rendered.length } characters in ${ rendered.children } root child(ren)` );

		assertTrue( rendered.children > 0, '#aiforge-root has no child elements' );

		await shot( 'dashboard' );

		const count = await roots.count();

		assertTrue(
			count === 1,
			`the admin page ships ${ count } elements carrying id="aiforge-root"; the id must be unique, ` +
				'and everything that resolves it by id only ever reaches the first'
		);
	},
};
