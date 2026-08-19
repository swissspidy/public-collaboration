/**
 * Internal dependencies
 */
import { expect, test } from '../fixtures';

/*
 * On a network an account belongs to the network, not to the site it was made
 * on, and the two are removed by different functions — so a temporary account
 * can look gone from the site that lent it while still existing everywhere
 * else. Nothing on a single-site install can tell the difference, which is why
 * this runs against a network of its own.
 */
test.describe( 'On a network', () => {
	test.skip(
		'true' !== process.env.WP_MULTISITE,
		'Only means anything on multisite.'
	);

	test( 'takes the temporary account off the network when the link is revoked', async ( {
		admin,
		page,
		secondPage,
		collaboration,
		createDraft,
	} ) => {
		await admin.editPost( await createDraft( 'Shared across a network' ) );

		const url = await collaboration.shareLink();

		await secondPage.goto( url );

		/*
		 * Getting this far is the account being made, added to the site, and
		 * given what the link lends it — all of which has to happen before its
		 * absence below means anything.
		 */
		await expect(
			secondPage.getByRole( 'dialog', {
				name: 'You have been invited to help',
			} )
		).toBeVisible( { timeout: 60_000 } );

		/*
		 * A second tab rather than this one: revoking is done from the dialog
		 * the editor is still showing, and navigating away would close it.
		 */
		const network = await page.context().newPage();
		const users = network.locator( '#the-list' );

		// Every temporary account this plugin makes is named this way.
		await network.goto( '/wp-admin/network/users.php?s=pubcol' );

		await expect( users ).toContainText( 'pubcol_' );

		await collaboration
			.getDialog()
			.getByRole( 'button', { name: 'Revoke link' } )
			.click();

		// Revoking does not wait for the server before closing the dialog, so
		// this asks again rather than asserting on a single load.
		await expect
			.poll(
				async () => {
					await network.reload();

					return users.innerText();
				},
				{ timeout: 15_000 }
			)
			.toContain( 'No users found.' );

		await network.close();
	} );
} );
