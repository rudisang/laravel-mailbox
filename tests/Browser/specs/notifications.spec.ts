import { expect, test } from '@playwright/test';
import { resetAndSeed } from './helpers';

declare global {
  interface Window {
    __notifications: { title: string; options: NotificationOptions }[];
    __permissionRequests: number;
  }
}

// Real OS notifications cannot be observed from Playwright, so the constructor is replaced
// with a recorder before any page script runs. `document.hasFocus` is stubbed because the
// UI deliberately skips the OS banner while the mailbox tab itself is in the foreground.
test.beforeEach(async ({ page }) => {
  await page.addInitScript(() => {
    window.__notifications = [];
    window.__permissionRequests = 0;
    // A real browser remembers a grant across reloads; the stub keeps it in sessionStorage.
    class FakeNotification {
      static permission = sessionStorage.getItem('__fake-permission') || 'default';
      static requestPermission = async () => {
        window.__permissionRequests += 1;
        FakeNotification.permission = 'granted';
        sessionStorage.setItem('__fake-permission', 'granted');
        return 'granted';
      };
      onclick: (() => void) | null = null;
      constructor(title: string, options: NotificationOptions = {}) {
        window.__notifications.push({ title, options });
      }
      close() {}
    }
    Object.defineProperty(window, 'Notification', { value: FakeNotification, configurable: true, writable: true });
    document.hasFocus = () => false;
  });
});

test('opt-in notifications announce new mail and remember the choice', async ({ page }) => {
  await resetAndSeed(page);

  const bell = page.locator('[data-notify]');
  await expect(bell).toHaveAttribute('data-state', 'off');
  await bell.click();
  await expect(bell).toHaveAttribute('aria-pressed', 'true');
  expect(await page.evaluate(() => window.__permissionRequests)).toBe(1);
  await expect.poll(() => page.evaluate(() => window.__notifications.length)).toBe(1);
  expect(await page.evaluate(() => window.__notifications[0].title)).toBe('Notifications are on');

  // The `plain` demo scenario sends a single message titled "Your one-time code".
  await page.request.get('/demo/send/plain');
  await expect.poll(() => page.evaluate(() => window.__notifications.length), { timeout: 15000 }).toBe(2);
  expect(await page.evaluate(() => window.__notifications[1])).toMatchObject({
    title: 'Your one-time code',
    options: { tag: 'mailbox-new' },
  });

  await page.reload();
  await expect(bell).toHaveAttribute('aria-pressed', 'true');
  await bell.click();
  await expect(bell).toHaveAttribute('aria-pressed', 'false');
});

test('the Message-ID chip copies the full value', async ({ page, context, browserName }) => {
  await resetAndSeed(page, '/demo/send/plain');
  if (browserName === 'chromium') {
    await context.grantPermissions(['clipboard-read', 'clipboard-write']);
  }

  await page.locator('#mailbox-list a[data-message]').first().click();
  const chip = page.locator('#mailbox-detail [data-copy]');
  await expect(chip).toBeVisible();
  const value = await chip.getAttribute('data-copy');
  expect(value).toMatch(/@/);

  await chip.click();
  await expect(page.locator('[data-toast]')).toContainText('Message-ID copied');

  if (browserName === 'chromium') {
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(value);
  }
});
