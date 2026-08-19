/**
 * Internal dependencies
 */
import { expect, test } from '../fixtures';

test.describe( 'Sharing a post', () => {
	test.beforeEach( async ( { admin, createDraft } ) => {
		await admin.editPost( await createDraft( 'A post to share' ) );
	} );

	test( 'shows the link as a QR code and a copyable URL', async ( {
		collaboration,
	} ) => {
		const url = await collaboration.shareLink();

		expect( url ).toMatch( /\/collaborate\/[a-f0-9]{32}\/?$/ );

		const dialog = collaboration.getDialog();

		await expect( dialog ).toBeVisible();
		await expect(
			dialog.locator( '.public-collaboration-modal__qrcode svg' )
		).toBeVisible();
		await expect( dialog ).toContainText(
			'Nobody has opened the link yet'
		);
	} );

	test( 'offers both capabilities, granted by default', async ( {
		collaboration,
	} ) => {
		await collaboration.shareLink();

		const dialog = collaboration.getDialog();
		const edit = dialog.getByRole( 'checkbox', {
			name: 'Edit post content',
		} );
		const upload = dialog.getByRole( 'checkbox', {
			name: 'Upload media files',
		} );

		await expect( edit ).toBeChecked();
		await expect( upload ).toBeChecked();

		await upload.click();

		await expect( upload ).not.toBeChecked();
		await expect( edit ).toBeChecked();
	} );

	test( 'cannot share a post that has never been saved', async ( {
		admin,
		collaboration,
	} ) => {
		await admin.createNewPost();

		const panel = await collaboration.openPanel();

		await expect(
			panel.getByRole( 'button', { name: 'Save the post to share it' } )
		).toBeDisabled();
	} );

	test( 'revokes the link server-side when closed, not just in the UI', async ( {
		page,
		secondPage,
		collaboration,
	} ) => {
		const url = await collaboration.shareLink();
		const dialog = collaboration.getDialog();

		await dialog.getByRole( 'button', { name: 'Revoke link' } ).click();

		await expect( dialog ).toBeHidden();
		await expect(
			page
				.getByRole( 'button', { name: 'Dismiss this notice' } )
				.filter( { hasText: 'Collaboration link revoked' } )
		).toBeVisible();

		/*
		 * Revoking deletes the request outright rather than merely marking it
		 * expired, so there is nothing left for WordPress to match the token in
		 * the URL against and the request falls through to a genuine 404. The
		 * click above does not wait for the DELETE to land before closing the
		 * dialog, so this polls rather than asserting on a single navigation.
		 */
		await expect
			.poll( async () => ( await secondPage.goto( url ) )?.status(), {
				timeout: 10_000,
			} )
			.toBe( 404 );
	} );
} );
