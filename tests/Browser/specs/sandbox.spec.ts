import { expect, test } from '@playwright/test';
import { resetAndSeed } from './helpers';

const mailboxPrefix = 'http://127.0.0.1:8787/_mailbox/';

// The hostile fixture's <style> declares `background: url("https://evil.example.com/css-track.png")`.
// The preview iframe's response sets `Content-Security-Policy: img-src data: {partsPrefix}`
// (see PreviewController), so both Chromium and WebKit block that background-image fetch at the
// network-stack level before any bytes leave the browser: Playwright still emits a `request` event
// for the attempt, followed by `requestfailed` with a CSP/client-block error text (never a
// `requestfinished`/`response`). Asserting on `request` alone can't distinguish "blocked" from
// "actually sent", so this test instead asserts that (a) nothing outside the mailbox prefix ever
// *completes* a request, and (b) every out-of-prefix attempt was specifically blocked as CSP/client
// policy — which is the actual containment guarantee the sandbox is supposed to provide.
const cspBlockPattern = /csp|blocked_by_client|blocked_by_csp/i;

test('the hostile preview executes nothing and makes no request outside the package', async ({ page }) => {
  // Reset first: this spec asserts on "the first message in the list" being the hostile
  // fixture, which only holds if the mailbox is empty before seeding it — the demo seed
  // routes are additive, and other specs in this suite seed the shared mailbox too.
  await resetAndSeed(page, '/demo/send/hostile');

  const dialogMessages: string[] = [];
  const finishedUrls: string[] = [];
  const failedOutsidePrefix: { url: string; errorText: string }[] = [];

  page.on('dialog', async (dialog) => {
    dialogMessages.push(dialog.message());
    await dialog.dismiss();
  });
  page.on('requestfinished', (browserRequest) => finishedUrls.push(browserRequest.url()));
  page.on('requestfailed', (browserRequest) => {
    const url = browserRequest.url();
    if (!url.startsWith(mailboxPrefix)) {
      failedOutsidePrefix.push({ url, errorText: browserRequest.failure()?.errorText ?? '' });
    }
  });

  await page.goto('/_mailbox/');
  await page.locator('#mailbox-list a[data-message]').first().click();

  const heading = page.locator('#mailbox-detail [data-detail-title]');
  await expect(heading).toContainText('<script>alert(1)</script>');

  const preview = page.locator('#mailbox-detail iframe[data-preview][sandbox]');
  await expect(preview).toHaveCount(1);
  const previewUrl = await preview.getAttribute('src');
  expect(previewUrl).not.toBeNull();

  await expect(preview).toBeVisible();
  const resolvedPreviewUrl = new URL(previewUrl!, page.url()).href;
  // Resolve the frame from the iframe element itself instead of scanning
  // page.frames() for a URL match: WebKit updates the child frame's URL
  // lazily after the src navigation commits, so the URL lookup races the
  // iframe load (it flaked in CI). contentFrame() is race-free, and
  // waitForURL pins down that the frame really navigated to the preview.
  const frameHandle = await preview.elementHandle();
  expect(frameHandle).not.toBeNull();
  const frame = await frameHandle!.contentFrame();
  expect(frame).not.toBeNull();
  await frame!.waitForURL(resolvedPreviewUrl);
  await expect(frame!.locator('h1')).toHaveText('Hostile content test');
  expect(await frame!.evaluate(() => typeof window.alert)).toBe('function');
  expect(await frame!.evaluate(() => document.scripts.length === 0)).toBe(true);
  await expect(frame!.locator('form')).toHaveCount(0);
  await expect(frame!.locator('iframe')).toHaveCount(0);

  const parentUrl = page.url();
  await frame!.locator('a').first().click();
  await expect(page).toHaveURL(parentUrl);
  expect(frame!.url()).toBe(resolvedPreviewUrl);

  expect(dialogMessages, `Unexpected preview dialogs: ${dialogMessages.join(', ')}`).toEqual([]);

  // Any request that actually completed must have stayed inside the mailbox package.
  for (const url of finishedUrls) {
    expect(url.startsWith(mailboxPrefix), `Unexpected completed request outside the mailbox package: ${url}`).toBe(
      true,
    );
  }

  // Any out-of-prefix attempt (e.g. the hostile CSS background image) must have been blocked by
  // the preview's CSP/client policy, not merely unobserved.
  for (const failure of failedOutsidePrefix) {
    expect(
      cspBlockPattern.test(failure.errorText),
      `Expected a CSP/client block for ${failure.url}, got error text: "${failure.errorText}"`,
    ).toBe(true);
  }
});
