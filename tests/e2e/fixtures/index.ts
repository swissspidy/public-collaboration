/**
 * External dependencies
 */
import { existsSync, readFileSync } from 'node:fs';
import { resolve } from 'node:path';

import type { BrowserContext, Locator, Page } from '@playwright/test';
import { addCoverageReport } from 'monocart-reporter';
import type { V8CoverageEntry } from 'monocart-coverage-reports';

/**
 * WordPress dependencies
 */
import {
	Admin,
	Editor,
	PageUtils,
	test as base,
} from '@wordpress/e2e-test-utils-playwright';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

/**
 * Whether this run is measuring what of the plugin the tests reach.
 *
 * V8's coverage is Chromium's, and asking for it costs a little on every page,
 * so it is off unless the run was started to collect it.
 * @param browserName
 */
function collectsCoverage( browserName: string ): boolean {
	return (
		'chromium' === browserName && 'true' === process.env.COLLECT_COVERAGE
	);
}

/**
 * Attaches the source map webpack wrote beside a bundle, if it is missing.
 *
 * Without one, what gets reported is coverage of the bundle rather than of the
 * files it was built from. Monocart resolves most of them itself; the ones it
 * does not are the ones WordPress serves with a `?ver=` query on the end.
 * @param entry
 */
function withSourceMap( entry: V8CoverageEntry ): V8CoverageEntry {
	if ( entry.sourceMap || ! entry.url.includes( '/build/' ) ) {
		return entry;
	}

	const bundle = entry.url
		.slice( entry.url.indexOf( 'build/' ) )
		.split( '?' )[ 0 ];
	const map = resolve( __dirname, '../../..', `${ bundle }.map` );

	if ( existsSync( map ) ) {
		entry.sourceMap = JSON.parse( readFileSync( map, 'utf-8' ) );
	}

	return entry;
}

/**
 * Records what of the plugin's own bundles a page ran, for the report.
 *
 * Coverage is per-page, and the collaborator browses in a page of their own, so
 * every page a test opens has to be asked separately.
 * @param page
 */
async function startCoverage( page: Page ): Promise< void > {
	await Promise.all( [
		page.coverage.startJSCoverage( { resetOnNavigation: false } ),
		page.coverage.startCSSCoverage( { resetOnNavigation: false } ),
	] );
}

/**
 * Hands what a page ran to the reporter.
 * @param page
 */
async function stopCoverage( page: Page ): Promise< void > {
	const [ js, css ] = await Promise.all( [
		page.coverage.stopJSCoverage(),
		page.coverage.stopCSSCoverage(),
	] );

	await addCoverageReport(
		( [ ...js, ...css ] as V8CoverageEntry[] ).map( withSourceMap ),
		test.info()
	);
}

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

		// The sidebar shows the block's settings instead whenever a block is
		// selected, and which tab is showing depends on what the test did
		// before this. Some editor versions have no tabs at all.
		const tab = region.getByRole( 'tab', { name: 'Post' } ).first();

		if ( await tab.count() ) {
			await tab.click();
		}

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
	 *
	 * The button says so itself once the post has links already, since
	 * clicking it again hands out another one rather than showing the first.
	 */
	async shareLink(): Promise< string > {
		const panel = await this.openPanel();

		await panel
			.getByRole( 'button', { name: /^Share (another )?link$/ } )
			.click();

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

	/**
	 * The links this post has been shared with, as the panel lists them.
	 *
	 * Located by the list itself rather than by role alone: the settings
	 * sidebar is full of other people's lists.
	 */
	getLinks(): Locator {
		return this.page
			.locator( '.public-collaboration-links' )
			.getByRole( 'listitem' );
	}

	/**
	 * Opens the dialog for a link the panel is listing.
	 *
	 * What names that button is the link's own description — who has it, and
	 * how long it has left — which is no way for a test to ask for the second
	 * one, so this counts instead.
	 *
	 * @param index Which of the listed links to open. Defaults to the first.
	 */
	async showLink( index = 0 ): Promise< void > {
		await this.getLinks()
			.nth( index )
			.locator( '.public-collaboration-links__link' )
			.click();
	}
}

/** A browser signed in as an account of its own, and the tools to drive it. */
type RoleSession = {
	/** ID of the account, for making it something to share. */
	userId: number;
	page: Page;
	admin: Admin;
	editor: Editor;
	collaboration: Collaboration;
};

type E2EFixture = {
	/**
	 * Signs in as a fresh account with a given role, in a browser of its own.
	 *
	 * A role is only interesting here for what it can and cannot lend, and the
	 * suite's own session is an administrator — so the account is made for the
	 * test, signed in through the login form like anybody else, and deleted
	 * afterwards.
	 */
	asRole: ( role: string ) => Promise< RoleSession >;
	/** Helpers for the sharing side of the feature. */
	collaboration: Collaboration;
	/**
	 * Creates a saved draft to share, and returns its ID.
	 *
	 * Not requestUtils.createPost(): its payload type insists on `date_gmt`,
	 * which the REST API is perfectly happy to work out for itself.
	 *
	 * @param title  Post title.
	 * @param author Optional. Whose post it is. Defaults to the administrator
	 *               the suite itself is signed in as.
	 */
	createDraft: ( title: string, author?: number ) => Promise< number >;
	/**
	 * A second, independent browser context and page — standing in for the
	 * person the link was sent to. Logged out, same as they would be.
	 *
	 * Emphatically logged out: the suite signs every context in as the
	 * administrator by default, and a "stranger" who can already edit the post
	 * is not a stranger at all.
	 */
	secondPage: Page;
	/** Editor utilities bound to {@link E2EFixture.secondPage}. */
	secondEditor: Editor;
};

export const test = base.extend< E2EFixture, {} >( {
	page: async ( { page, browserName }, use ) => {
		if ( ! collectsCoverage( browserName ) ) {
			// eslint-disable-next-line react-hooks/rules-of-hooks
			await use( page );

			return;
		}

		await startCoverage( page );

		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( page );

		await stopCoverage( page );
	},

	collaboration: async ( { page, editor }, use ) => {
		// Playwright's own fixture convention, not a React hook — the callback
		// just happens to be named `use`, which is enough to trip a lint rule
		// that assumes otherwise.
		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( new Collaboration( { page, editor } ) );
	},

	asRole: async ( { browser, browserName, baseURL, requestUtils }, use ) => {
		const contexts: BrowserContext[] = [];
		const pages: Page[] = [];
		const created: number[] = [];

		const asRole = async ( role: string ): Promise< RoleSession > => {
			/*
			 * Unique per account rather than per run: two of these can be alive
			 * at once, and a login is only ever as good as its own name. Letters
			 * and digits only, with no underscore in sight — a network holds
			 * signups to that and refuses anything else outright.
			 */
			const username = `pubcol${ role }${
				created.length
			}${ Date.now() }`;
			const password = 'a password nobody has to remember';

			const user = await ( requestUtils as RequestUtils ).createUser( {
				username,
				email: `${ username }@public-collaboration.invalid`,
				password,
				roles: [ role ],
			} );

			created.push( user.id );

			/*
			 * `storageState` in the shared Playwright config points at the
			 * administrator's saved session, and a context made here picks it
			 * up. An empty one is what makes this somebody else's browser.
			 */
			const context = await browser.newContext( {
				baseURL,
				storageState: { cookies: [], origins: [] },
			} );

			contexts.push( context );

			const page = await context.newPage();
			const measured = collectsCoverage( browserName );

			if ( measured ) {
				await startCoverage( page );
				pages.push( page );
			}

			await page.goto( '/wp-login.php' );
			await page.locator( '#user_login' ).fill( username );
			await page.locator( '#user_pass' ).fill( password );
			await page.locator( '#wp-submit' ).click();
			await page.waitForURL( /wp-admin/ );

			const editor = new Editor( { page } );

			return {
				userId: user.id,
				page,
				editor,
				admin: new Admin( {
					page,
					pageUtils: new PageUtils( { page, browserName } ),
					editor,
				} ),
				collaboration: new Collaboration( { page, editor } ),
			};
		};

		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( asRole );

		// Coverage first: it has to be asked for while the page is still open.
		for ( const page of pages ) {
			await stopCoverage( page );
		}

		for ( const context of contexts ) {
			await context.close();
		}

		for ( const id of created ) {
			try {
				// Anything they authored goes back to the site's first account,
				// so that deleting the borrowed role does not take the posts
				// with it.
				await ( requestUtils as RequestUtils ).rest( {
					method: 'DELETE',
					path: `/wp/v2/users/${ id }`,
					params: { force: true, reassign: 1 },
				} );
			} catch {
				// Core refuses to delete a user over REST on a network at all,
				// and these accounts outlive nothing but the container they
				// were made in.
			}
		}
	},

	createDraft: async ( { requestUtils }, use ) => {
		const created: number[] = [];

		const createDraft = async (
			title: string,
			author?: number
		): Promise< number > => {
			const post = await ( requestUtils as RequestUtils ).rest< {
				id: number;
			} >( {
				method: 'POST',
				path: '/wp/v2/posts',
				data: {
					title,
					status: 'draft',
					...( author ? { author } : {} ),
				},
			} );

			created.push( post.id );

			return post.id;
		};

		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( createDraft );

		// Otherwise every run leaves its drafts behind, and later runs assert
		// against a list that keeps growing.
		for ( const id of created ) {
			await ( requestUtils as RequestUtils ).rest( {
				method: 'DELETE',
				path: `/wp/v2/posts/${ id }`,
				params: { force: true },
			} );
		}
	},

	secondPage: async ( { browser, browserName, baseURL }, use ) => {
		/*
		 * `storageState` in the shared Playwright config points at the
		 * administrator's saved session, and a context made here picks it up.
		 * Handing it an empty one instead is what makes this a stranger's
		 * browser rather than a second window onto the sharer's own account —
		 * without it the link recognises whoever follows it as the person who
		 * shared it, and sends them to the post with no invitation at all.
		 */
		const context = await browser.newContext( {
			// So that a test can ask this browser for an admin screen by path,
			// the same way it would ask the sharer's.
			baseURL,
			storageState: { cookies: [], origins: [] },
		} );
		const secondPage = await context.newPage();
		const measured = collectsCoverage( browserName );

		if ( measured ) {
			await startCoverage( secondPage );
		}

		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( secondPage );

		if ( measured ) {
			await stopCoverage( secondPage );
		}

		await context.close();
	},

	secondEditor: async ( { secondPage }, use ) => {
		// eslint-disable-next-line react-hooks/rules-of-hooks
		await use( new Editor( { page: secondPage } ) );
	},
} );

export { expect } from '@wordpress/e2e-test-utils-playwright';
