/**
 * Shims for the WordPress packages that do not ship type declarations.
 *
 * Only the bits this plugin actually touches are described; everything else
 * stays untyped rather than pretending to a fidelity these packages do not
 * have.
 */

declare module '@wordpress/scripts/config/playwright.config' {
	const config: import('@playwright/test').PlaywrightTestConfig;
	export default config;
}
