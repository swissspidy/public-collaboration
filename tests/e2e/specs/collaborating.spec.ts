/**
 * Internal dependencies
 */
import { expect, test } from '../fixtures';

test.describe( 'Following a collaboration link', () => {
	test( 'lets somebody edit the post without an account', async ( {
		admin,
		secondPage,
		secondEditor,
		collaboration,
		createDraft,
		requestUtils,
	} ) => {
		const postId = await createDraft( 'Shared with a stranger' );

		await admin.editPost( postId );

		const url = await collaboration.shareLink();

		// "The collaborator": a separate, logged-out context following the link.
		await secondPage.goto( url );

		await expect( secondPage ).toHaveURL(
			new RegExp( `[?&]post=${ postId }(&|$)` )
		);

		const welcome = secondPage.getByRole( 'dialog', {
			name: 'You have been invited to help',
		} );

		/*
		 * goto() resolves on the navigation, not on the editor being usable, and
		 * a cold boot in CI takes considerably longer than the default assertion
		 * timeout. Waiting on something of the editor's first would not help:
		 * an open modal marks the rest of the page `aria-hidden`, so while this
		 * dialog is up there is nothing else here to wait for.
		 */
		await expect( welcome ).toBeVisible( { timeout: 60_000 } );
		await expect( welcome ).toContainText( 'Shared with a stranger' );
		await expect( welcome ).toContainText(
			'edit the content of this one post'
		);

		await welcome.getByLabel( 'Your name (optional)' ).fill( 'Sam' );
		await welcome.getByRole( 'button', { name: 'Start editing' } ).click();

		await expect( welcome ).toBeHidden();

		// The editor is theirs to use, and what they write reaches the post.
		await secondEditor.canvas
			.getByRole( 'textbox', { name: 'Add title' } )
			.fill( 'Edited by a guest' );

		await secondEditor.saveDraft();

		await expect
			.poll( async () => {
				const updated = await requestUtils.rest< {
					title: { raw: string };
				} >( {
					path: `/wp/v2/posts/${ postId }`,
					params: { context: 'edit' },
				} );

				return updated.title.raw;
			} )
			.toBe( 'Edited by a guest' );

		// And whoever shared it sees who turned up.
		await expect( collaboration.getDialog() ).toContainText(
			'Sam is working on this post with you.',
			{ timeout: 30_000 }
		);
	} );

	test( 'only offers what the link was shared with', async ( {
		admin,
		page,
		secondPage,
		collaboration,
		createDraft,
	} ) => {
		await admin.editPost( await createDraft( 'Read it, do not fill it' ) );

		const url = await collaboration.shareLink();
		const upload = collaboration
			.getDialog()
			.getByRole( 'checkbox', { name: 'Upload media files' } );

		/*
		 * The switch moves optimistically — the request that takes the
		 * capability away is still in flight. Following the link before it
		 * lands would greet the collaborator with the capability they were not
		 * meant to have, and the assertion below would pass only because the
		 * dialog rendered late.
		 */
		const saved = page.waitForResponse(
			( response ) =>
				response.url().includes( 'collaboration-requests' ) &&
				'GET' !== response.request().method() &&
				response.ok()
		);

		await upload.click();
		await expect( upload ).not.toBeChecked();
		await saved;

		await secondPage.goto( url );

		const welcome = secondPage.getByRole( 'dialog', {
			name: 'You have been invited to help',
		} );

		/*
		 * goto() resolves on the navigation, not on the editor being usable, and
		 * a cold boot in CI takes considerably longer than the default assertion
		 * timeout. Waiting on something of the editor's first would not help:
		 * an open modal marks the rest of the page `aria-hidden`, so while this
		 * dialog is up there is nothing else here to wait for.
		 */
		await expect( welcome ).toBeVisible( { timeout: 60_000 } );
		await expect( welcome ).toContainText(
			'edit the content of this one post'
		);
		await expect( welcome ).not.toContainText( 'upload images' );
	} );

	test( 'sends whoever shared it straight to their own post', async ( {
		admin,
		page,
		collaboration,
		createDraft,
	} ) => {
		const postId = await createDraft( 'My own post' );

		await admin.editPost( postId );

		const url = await collaboration.shareLink();

		// No temporary account for somebody who can already edit the post.
		await page.goto( url );

		await expect( page ).toHaveURL(
			new RegExp( `post\\.php\\?post=${ postId }&action=edit` )
		);
	} );
} );
