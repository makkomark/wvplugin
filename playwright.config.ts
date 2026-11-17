import { defineConfig, devices } from '@playwright/test';

/**
 * E2E for the join flow against a running site (local or staging) in sandbox mode with the Test gateway.
 *   WVM_BASE_URL=https://staging.witsvalley.com npx playwright test --trace off
 */
export default defineConfig( {
	testDir: './tests/e2e',
	timeout: 120_000,
	retries: process.env.CI ? 1 : 0,
	reporter: [ [ 'list' ] ],
	use: {
		baseURL: process.env.WVM_BASE_URL || 'http://localhost:8080',
		trace: 'off',
		screenshot: 'only-on-failure',
		launchOptions: process.env.PW_CHROMIUM_PATH ? { executablePath: process.env.PW_CHROMIUM_PATH } : {},
	},
	projects: [
		{ name: 'mobile', use: { ...devices[ 'Pixel 7' ] } },
		{ name: 'desktop', use: { viewport: { width: 1280, height: 900 } } },
	],
} );
