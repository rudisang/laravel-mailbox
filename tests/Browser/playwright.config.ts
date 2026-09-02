import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './specs',
  outputDir: './test-results/artifacts',
  // Load-bearing: every spec shares one Testbench demo server and its one demo mailbox
  // (see `webServer` below and `specs/helpers.ts` `resetAndSeed`). Each spec resets the
  // mailbox before seeding it, but that reset/seed sequence is not atomic against a
  // concurrently-running spec, so a second worker could interleave one spec's clear
  // with another spec's assertions (message counts, "the newest message", a specific
  // subject match). Keep this at 1 unless the specs stop sharing backend state.
  workers: 1,
  retries: process.env.CI ? 2 : 0,
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
