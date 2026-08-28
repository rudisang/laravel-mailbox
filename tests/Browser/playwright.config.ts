import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './specs',
  outputDir: './test-results/artifacts',
  workers: 1,
  projects: [
    { name: 'chromium', use: { browserName: 'chromium' } },
    { name: 'webkit', use: { browserName: 'webkit' } },
  ],
  webServer: {
    command: 'cd ../.. && composer build && php vendor/bin/testbench serve --host=127.0.0.1 --port=8787',
    url: 'http://127.0.0.1:8787/_mailbox',
    reuseExistingServer: true,
    timeout: 120000,
  },
  use: {
    baseURL: 'http://127.0.0.1:8787',
  },
});
