import { expect, test } from '@playwright/test';

const viewports = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'tablet', width: 1024, height: 768 },
  { name: 'phone', width: 390, height: 844 },
];

const colorSchemes = ['light', 'dark'] as const;

test('inbox and detail remain responsive in light and dark modes', async ({ page }, testInfo) => {
  await page.goto('/demo/send-all');
  await page.locator('#mailbox-list a[data-message]').first().click();
  await expect(page.locator('#mailbox-detail [data-detail-title]')).toBeVisible();

  for (const colorScheme of colorSchemes) {
    await page.emulateMedia({ colorScheme });

    for (const viewport of viewports) {
      await page.setViewportSize({ width: viewport.width, height: viewport.height });
      await expect(page.locator('body')).toBeVisible();

      const dimensions = await page.locator('body').evaluate((body) => ({
        clientWidth: body.clientWidth,
        scrollWidth: body.scrollWidth,
      }));
      expect(dimensions.scrollWidth).toBeLessThanOrEqual(dimensions.clientWidth);

      await page.screenshot({
        path: `test-results/${testInfo.project.name}-${viewport.name}-${viewport.width}x${viewport.height}-${colorScheme}.png`,
      });
    }
  }
});
