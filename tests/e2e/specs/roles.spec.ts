/**
 * Internal dependencies
 */
import { expect, test } from '../fixtures';

/**
 * Every role that can share a post, and what each has to lend.
 *
 * A collaboration link is not a promotion. Whatever standing the person who
 * shared it has, what comes out of the link is the same roleless account with
 * the run of one post — which is what the second half of each test is for. What
 * does differ is what they are able to put into it, and whose post they are in
 * a position to share at all.
 */
const ROLES = [
	{
		name: 'contributor',
		// Cannot upload, so has no uploading to lend.
		canUpload: false,
		// The only post a contributor may edit is one of their own.
		sharesOwnPost: true,
	},
	{
		name: 'author',
		canUpload: true,
		sharesOwnPost: true,
	},
	{
		name: 'editor',
		canUpload: true,
		// Somebody else's post, which is the whole point of the role.
		sharesOwnPost: false,
	},
	{
		name: 'administrator',
		canUpload: true,
		sharesOwnPost: false,
	},
];

test.describe( 'Sharing from each role', () => {
	for ( const role of ROLES ) {
		test( `a ${ role.name } lends what they have, and no more`, async ( {
			asRole,
			createDraft,
			secondPage,
		} ) => {
			const session = await asRole( role.name );

			const postId = await createDraft(
				`Shared by a ${ role.name }`,
				role.sharesOwnPost ? session.userId : undefined
			);
			const otherPostId = await createDraft( 'Shared with nobody' );

			await session.admin.editPost( postId );

			const url = await session.collaboration.shareLink();
			const dialog = session.collaboration.getDialog();

			await expect(
				dialog.getByRole( 'checkbox', { name: 'Edit post content' } )
			).toBeChecked();

			// A switch for something the sharer cannot do themselves could only
			// ever come back from the endpoint as a refusal, so it is not there.
			await expect(
				dialog.getByRole( 'checkbox', { name: 'Upload media files' } )
			).toHaveCount( role.canUpload ? 1 : 0 );

			await dialog.getByRole( 'button', { name: 'Done' } ).click();

			// And now somebody with no account at all, in a browser of their own.
			await secondPage.goto( url );

			const welcome = secondPage.getByRole( 'dialog', {
				name: 'You have been invited to help',
			} );

			/*
			 * goto() resolves on the navigation, not on the editor being usable,
			 * and a cold boot in CI takes considerably longer than the default
			 * assertion timeout.
			 */
			await expect( welcome ).toBeVisible( { timeout: 60_000 } );
			await expect( welcome ).toContainText(
				'edit the content of this one post'
			);

			if ( role.canUpload ) {
				await expect( welcome ).toContainText( 'upload images' );
			} else {
				await expect( welcome ).not.toContainText( 'upload images' );
			}

			await welcome
				.getByRole( 'button', { name: 'Start editing' } )
				.click();

			await expect( welcome ).toBeHidden();

			/*
			 * The ceiling, and the reason this runs for every role rather than
			 * once: an administrator's invitation is not an administrator's
			 * account. Another post, and any screen in wp-admin that is not this
			 * post's editor, sends them back where they were invited.
			 */
			const backToThePost = new RegExp(
				`post\\.php\\?post=${ postId }&action=edit`
			);

			await secondPage.goto(
				`/wp-admin/post.php?post=${ otherPostId }&action=edit`
			);
			await expect( secondPage ).toHaveURL( backToThePost );

			await secondPage.goto( '/wp-admin/plugins.php' );
			await expect( secondPage ).toHaveURL( backToThePost );

			await secondPage.goto( '/wp-admin/users.php' );
			await expect( secondPage ).toHaveURL( backToThePost );
		} );
	}
} );
