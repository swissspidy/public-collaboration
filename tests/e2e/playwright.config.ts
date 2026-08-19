/**
 * External dependencies
 */
import { defineConfig } from '@playwright/test';
import type { ReporterDescription } from '@playwright/test';
import type {
	CoverageReportOptions,
	V8CoverageEntry,
} from 'monocart-coverage-reports';

/**
 * WordPress dependencies
 */
import baseConfig from '@wordpress/scripts/config/playwright.config';

/*
 * Coverage comes from V8 rather than from instrumenting the build, so what it
 * reports is the bundle the browser actually ran. The source maps built
 * alongside it are what turn that back into the files in src/, and the filters
 * are what keep WordPress's own scripts — every one of which the editor loads —
 * out of this plugin's figures.
 */
const coverage: CoverageReportOptions = {
	reports: [ [ 'codecov' ], [ 'v8' ], [ 'console-summary' ] ],
	entryFilter: ( entry: V8CoverageEntry ) =>
		entry.url.includes( 'plugins/public-collaboration/build/' ),
	sourceFilter: ( sourcePath: string ) =>
		sourcePath.includes( 'src/' ) &&
		! sourcePath.includes( 'node_modules/' ) &&
		! sourcePath.includes( 'webpack/' ),
	// So that the paths codecov is given line up with the repository.
	sourcePath: ( filePath: string ) =>
		filePath.replace( 'public-collaboration/', '' ),
};

// The base config always sets an array; the type it is declared with allows a
// bare reporter name too, which is all this narrowing is for.
const reporters: ReporterDescription[] = Array.isArray( baseConfig.reporter )
	? baseConfig.reporter
	: [ [ baseConfig.reporter ?? 'list' ] ];

export default defineConfig( {
	...baseConfig,
	reporter:
		'true' === process.env.COLLECT_COVERAGE
			? [
					...reporters,
					[
						'monocart-reporter',
						{
							outputFile: './artifacts/e2e-coverage/report.html',
							coverage,
						},
					],
			  ]
			: reporters,
} );
