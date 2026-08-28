import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

async function expectNoSeriousAccessibilityViolations(page: Page): Promise<void> {
  // The message preview renders inside a `<iframe sandbox>` with scripts disallowed
  // (see PreviewController's CSP), so axe's injected script can never run there and
  // `analyze()` hangs until the test times out. Excluding iframes keeps axe scoped to
  // the surrounding mailbox UI, which is the only thing this suite is asserting on.
  const results = await new AxeBuilder({ page }).options({ iframes: false }).exclude('iframe').analyze();
  const violations = results.violations.filter(({ impact }) => impact === 'serious' || impact === 'critical');

  expect(violations).toEqual([]);
}

test('inbox and detail have no serious or critical accessibility violations', async ({ page }) => {
  await page.goto('/demo/send-all');
  await expect(page.locator('#mailbox-list')).toBeVisible();
  await expectNoSeriousAccessibilityViolations(page);

  await page.locator('#mailbox-list a[data-message]').first().click();
  await expect(page.locator('#mailbox-detail [data-detail-title]')).toBeVisible();
  await expectNoSeriousAccessibilityViolations(page);
});
