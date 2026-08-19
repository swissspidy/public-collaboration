/**
 * External dependencies
 */
import type { Locator, Page } from '@playwright/test';

/**
 * WordPress dependencies
 */
import { Editor, test as base } from '@wordpress/e2e-test-utils-playwright';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Helpers for the sharing side of the feature.
 */
class Collaboration {
	private readonly page: Page;
	private readonly editor: Editor;

	constructor( { page, editor }: { page: Page; editor: Editor } ) {
		this.page = page;
		this.editor = editor;
	}

	/**
	 * Opens the plugin's panel in the post settings sidebar.
	 *
	 * The panel remembers whether it was left open, so whether it needs a click
	 * depends on what an earlier test did. That check lives here rather than in
	 * a test body, where a conditional would be hiding a race.
	 */
	async openPanel(): Promise< Locator > {
		await this.editor.openDocumentSettingsSidebar();

		const region = this.page.getByRole( 'region', {
			name: 'Editor settings',
		} );

		const toggle = region.getByRole( 'button', {
			name: 'Public collaboration',
		} );

		await toggle.waitFor();

		if ( 'true' !== ( await toggle.getAttribute( 'aria-expanded' ) ) ) {
			await toggle.click();
		}

		return region;
	}

	/**
	 * Shares the post being edited, and returns the link to hand out.
	 */
	async shareLink(): Promise< string > {
		const panel = await this.openPanel();

		await panel.getByRole( 'button', { name: 'Share link' } ).click();

		const dialog = this.getDialog();

		await dialog.waitFor();

		return dialog.getByLabel( 'Collaboration link' ).inputValue();
	}

	/**
	 * The dialog showing the QR code and the link.
	 */
	getDialog(): Locator {
		return this.page.getByRole( 'dialog', {
			name: 'Share for collaboration',
		} );
	}
}

type E2EFixture = {
	/** Helpers for the sharing side of the feature. */
	collaboration: Collaboration;
	/**
	 * Creates a saved draft to share, and returns its ID.
	 *
	 * Not requestUtils.createPost(): its payload type insists on `date_gmt`,
	 * which the REST API is perfectly happy to work out for itself.
	 */
	createDraft: ( title: string ) => Promise< number >;
	/**
	 * A second, independent browser context and page — standing in for the
	 * person the link was sent to. Logged out, same as they would be.
	 */
	secondPage: Page;
	/** Editor utilities bound to {@link E2EFixture.secondPage}. */
	secondEditor: Editor;
};

export const test = base.extend< E2EFixture, {} >( {
	collaboration: async ( { page, editor }, use ) => {
		// Playwright's own fixture convention, not a React hook — the callback
		// just happens to be named `use`, which is enough to trip a lint rule
		// that assumes otherwise.
		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( new Collaboration( { page, editor } ) );
	},

	createDraft: async ( { requestUtils }, use ) => {
		const createDraft = async ( title: string ): Promise< number > => {
			const post = await ( requestUtils as RequestUtils ).rest< {
				id: number;
			} >( {
				method: 'POST',
				path: '/wp/v2/posts',
				data: { title, status: 'draft' },
			} );

			return post.id;
		};

		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( createDraft );
	},

	secondPage: async ( { browser }, use ) => {
		const context = await browser.newContext();
		const secondPage = await context.newPage();

		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( secondPage );

		await context.close();
	},

	secondEditor: async ( { secondPage }, use ) => {
		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( new Editor( { page: secondPage } ) );
	},
} );

export { expect } from '@wordpress/e2e-test-utils-playwright';
