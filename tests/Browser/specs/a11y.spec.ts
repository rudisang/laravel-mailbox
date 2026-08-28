import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function expectNoSeriousAccessibilityViolations(page: Page, context: string): Promise<void> {
  // The message preview renders inside an `<iframe sandbox>` with scripts disallowed
  // (see PreviewController's CSP), so axe's injected script can never run there and
  // `analyze()` hangs until the test times out. Excluding iframes keeps axe scoped to
  // the surrounding mailbox UI, which is the only thing this suite is asserting on.
  const results = await new AxeBuilder({ page }).options({ iframes: false }).exclude('iframe').analyze();
  const violations = results.violations.filter(({ impact }) => impact === 'serious' || impact === 'critical');

  expect(violations, `${context}:\n${JSON.stringify(violations, null, 2)}`).toEqual([]);
}

async function settleThemeTransitions(page: Page): Promise<void> {
  // Buttons, chips, segmented tabs and rows all declare `transition: color …,
  // background-color …` (see mailbox.css §4/§8/§10) so a real theme switch fades
  // between palettes over ~160ms. `page.emulateMedia` flips the underlying
  // `prefers-color-scheme` match synchronously, but the CSS transition it triggers
  // does not — axe-core sampling immediately afterwards was catching interpolated
  // mid-fade colors (e.g. light `--mb-muted` #6b6b6b still on screen against an
  // already-dark canvas) and reporting those as contrast failures. This was the
  // real source of this suite's non-determinism, not just "which message opened".
  // Forcing every finite CSS transition to its end state removes the race without
  // an arbitrary sleep; infinite keyframe animations (e.g. the loading bar) can't
  // be finished and are skipped.
  await page.evaluate(() => {
    for (const animation of document.getAnimations({ subtree: true })) {
      try {
        animation.finish();
      } catch {
        // Infinite-duration animation (e.g. `.mb-progress`'s indeterminate bar) — ignore.
      }
    }
  });
}

async function checkBothThemes(page: Page, label: string): Promise<void> {
  // The app follows `prefers-color-scheme` while `data-theme="system"` (the default,
  // untouched by a fresh browser context with no `mailbox-theme` in localStorage), so
  // toggling the emulated color scheme is enough to exercise both palettes without a
  // reload — the same technique `responsive.spec.ts` already relies on.
  for (const colorScheme of ['light', 'dark'] as const) {
    await page.emulateMedia({ colorScheme });
    await settleThemeTransitions(page);
    await expectNoSeriousAccessibilityViolations(page, `${label} (${colorScheme})`);
  }
}

async function openMessageBySubject(page: Page, subject: string): Promise<void> {
  await page.locator('#mailbox-list a[data-message]', { hasText: subject }).click();
  await expect(page.locator('#mailbox-detail [data-detail-title]')).toHaveText(subject);
}

test('inbox and message detail have no serious or critical accessibility violations in light and dark', async ({ page }) => {
  test.slow();

  // /demo/send-all seeds a fixed set of demo messages, including a deliberately hostile
  // mail that exercises XSS/sanitisation edge cases and previously tripped contrast
  // failures in its own detail chrome. This suite used to open "the newest message" by
  // list position, so the actual markup under test changed with send order: it passed
  // whenever an innocuous mail happened to land there and silently skipped the hostile
  // mail otherwise. Locating messages by their visible subject text below fixes which
  // messages are exercised regardless of order.
  const invoiceSubject = 'Your invoice #123';
  const hostileSubject = '<script>alert(1)</script> "Quoted" & hostile subject';

  // The demo server's mailbox storage persists across test runs (the webServer reuses
  // an existing server, per playwright.config.ts), so repeated runs of `/demo/send-all`
  // otherwise pile up duplicate copies of every scenario and the subject locators below
  // stop resolving to a single element. Clear the mailbox first so this test always
  // starts from exactly one copy of each scenario, independent of anything earlier runs
  // (or other spec files in this suite) left behind.
  await page.goto('/_mailbox');
  const clearForm = page.locator('form[data-action="clear"]');
  const clearUrl = await clearForm.getAttribute('action');
  const csrfToken = await clearForm.locator('input[name="_token"]').getAttribute('value');
  const clearResponse = await page.request.post(clearUrl ?? '', { headers: { 'X-CSRF-TOKEN': csrfToken ?? '' } });
  expect(clearResponse.ok()).toBeTruthy();

  await page.goto('/demo/send-all');
  await expect(page.locator('#mailbox-list')).toBeVisible();
  await expect(page.locator('#mailbox-list a[data-message]', { hasText: invoiceSubject })).toHaveCount(1);
  await expect(page.locator('#mailbox-list a[data-message]', { hasText: hostileSubject })).toHaveCount(1);

  await checkBothThemes(page, 'inbox');

  await openMessageBySubject(page, invoiceSubject);
  await checkBothThemes(page, 'invoice message detail');

  await openMessageBySubject(page, hostileSubject);
  await checkBothThemes(page, 'hostile message detail');
});
