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
		const button = panel.getByRole( 'button', {
			name: 'Save the post to share it',
		} );

		// aria-disabled, not the disabled attribute: the button stays focusable
		// so that the reason it is unavailable can be read out.
		await expect( button ).toBeVisible();
		await expect( button ).toHaveAttribute( 'aria-disabled', 'true' );
	} );

	test( 'keeps the link when the dialog is closed, and lists it', async ( {
		secondPage,
		collaboration,
	} ) => {
		const url = await collaboration.shareLink();
		const dialog = collaboration.getDialog();

		await dialog.getByRole( 'button', { name: 'Done' } ).click();

		await expect( dialog ).toBeHidden();

		// Being done with the dialog is not being done with the link: it is
		// still live, and the panel is where it can be found again.
		const links = collaboration.getLinks();

		await expect( links ).toHaveCount( 1 );
		await expect( links.first() ).toContainText( 'Not opened yet' );

		expect( ( await secondPage.goto( url ) )?.status() ).toBe( 200 );
	} );

	test( 'takes back a link that has only just been minted', async ( {
		page,
		secondPage,
		collaboration,
	} ) => {
		const url = await collaboration.shareLink();
		const dialog = collaboration.getDialog();

		await dialog.getByRole( 'button', { name: 'Cancel' } ).click();

		await expect( dialog ).toBeHidden();
		await expect( collaboration.getLinks() ).toHaveCount( 0 );

		// Nothing to announce: the link was never handed to anybody, and a
		// snackbar saying it had been revoked would only invite a second look.
		await expect(
			page
				.getByRole( 'button', { name: 'Dismiss this notice' } )
				.filter( { hasText: /revok/i } )
		).toHaveCount( 0 );

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

	test( 'revokes a listed link server-side, not just in the UI', async ( {
		secondPage,
		collaboration,
	} ) => {
		const url = await collaboration.shareLink();

		await collaboration
			.getDialog()
			.getByRole( 'button', { name: 'Done' } )
			.click();

		const links = collaboration.getLinks();

		await links
			.first()
			.getByRole( 'button', { name: 'Revoke link' } )
			.click();

		await expect( links ).toHaveCount( 0 );

		await expect
			.poll( async () => ( await secondPage.goto( url ) )?.status(), {
				timeout: 10_000,
			} )
			.toBe( 404 );
	} );

	test( 'revokes a handed-out link from its own dialog', async ( {
		secondPage,
		collaboration,
	} ) => {
		const url = await collaboration.shareLink();
		const dialog = collaboration.getDialog();

		await dialog.getByRole( 'button', { name: 'Done' } ).click();
		await collaboration.showLink();

		await dialog.getByRole( 'button', { name: 'Revoke link' } ).click();

		await expect( dialog ).toBeHidden();
		await expect( collaboration.getLinks() ).toHaveCount( 0 );

		await expect
			.poll( async () => ( await secondPage.goto( url ) )?.status(), {
				timeout: 10_000,
			} )
			.toBe( 404 );
	} );

	test( 'changes what a link grants after handing it out', async ( {
		page,
		collaboration,
	} ) => {
		await collaboration.shareLink();

		const dialog = collaboration.getDialog();

		await dialog.getByRole( 'button', { name: 'Done' } ).click();
		await expect( dialog ).toBeHidden();

		await collaboration.showLink();

		await expect( dialog ).toBeVisible();

		// Cancelling belongs to a link that has only just been minted. This one
		// has been handed out, so taking it back is called what it is.
		await expect(
			dialog.getByRole( 'button', { name: 'Cancel' } )
		).toHaveCount( 0 );
		await expect(
			dialog.getByRole( 'button', { name: 'Revoke link' } )
		).toBeVisible();

		const upload = dialog.getByRole( 'checkbox', {
			name: 'Upload media files',
		} );

		await expect( upload ).toBeChecked();

		/*
		 * The switch moves optimistically, so the assertion that it stuck has
		 * to wait for the server to have been told — otherwise reopening the
		 * dialog could be reading the browser's own guess back to itself.
		 */
		const saved = page.waitForResponse(
			( response ) =>
				response.url().includes( 'collaboration-requests' ) &&
				'GET' !== response.request().method() &&
				response.ok()
		);

		await upload.click();
		await saved;

		await dialog.getByRole( 'button', { name: 'Done' } ).click();
		await expect( dialog ).toBeHidden();

		await collaboration.showLink();

		await expect( dialog ).toBeVisible();
		await expect(
			dialog.getByRole( 'checkbox', { name: 'Upload media files' } )
		).not.toBeChecked();
		await expect(
			dialog.getByRole( 'checkbox', { name: 'Edit post content' } )
		).toBeChecked();
	} );
} );
