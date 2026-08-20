/**
 * Internal dependencies
 */
import { expect, test } from '../fixtures';

/*
 * On a network an account belongs to the network, not to the site it was made
 * on, and the two are removed by different functions — so a temporary account
 * can look gone from the site that lent it while still existing everywhere
 * else. Nothing on a single-site install can tell those apart, which is why
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

		/*
		 * A second tab rather than this one: revoking is done from the panel in
		 * the editor, and this needs the network's list of users as well.
		 */
		const network = await page.context().newPage();

		// Every temporary account on the network, by the login they are named
		// with. The network screen rather than the site's own list of users:
		// the site's would stop showing the account either way.
		const collaborators = async (): Promise< string[] > =>
			network.goto( '/wp-admin/network/users.php?s=pubcol' ).then( () =>
				network
					.locator( '#the-list' )
					.getByRole( 'link', { name: /^pubcol_/ } )
					.allInnerTexts()
			);

		/*
		 * Which accounts are already there, rather than how many. Every test
		 * before this one leaves its own behind — their links are left to
		 * expire rather than revoked — and none of them is the one this is
		 * about.
		 */
		const before = await collaborators();

		await secondPage.goto( url );

		// Getting this far is the account being made, added to the site, and
		// given what the link lends it.
		await expect(
			secondPage.getByRole( 'dialog', {
				name: 'You have been invited to help',
			} )
		).toBeVisible( { timeout: 60_000 } );

		const [ collaborator ] = ( await collaborators() ).filter(
			( login ) => ! before.includes( login )
		);

		expect( collaborator ).toBeTruthy();

		await collaboration
			.getDialog()
			.getByRole( 'button', { name: 'Done' } )
			.click();

		await collaboration
			.getLinks()
			.first()
			.getByRole( 'button', { name: /^Revoke (the )?link/ } )
			.click();

		// The account is deleted while the request is in flight, so this asks
		// again rather than asserting on a single load.
		await expect
			.poll( collaborators, { timeout: 15_000 } )
			.not.toContain( collaborator );

		await network.close();
	} );
} );
