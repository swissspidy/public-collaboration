/**
 * WordPress dependencies
 */
import { defineConfig } from '@playwright/test';
import baseConfig from '@wordpress/scripts/config/playwright.config';

export default defineConfig( {
	...baseConfig,
} );
