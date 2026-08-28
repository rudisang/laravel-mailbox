import { expect, test } from '@playwright/test';

test('mailbox workflows are keyboard-complete', async ({ page }) => {
  await page.goto('/demo/send-all');
  await expect(page.locator('[data-theme-toggle]')).toBeVisible();
  await expect(page.locator('[data-shortcuts-help]')).toBeVisible();
  await expect(page.locator('form[data-action=clear]')).toBeVisible();

  await page.keyboard.press('j');
  await page.keyboard.press('j');
  await page.keyboard.press('Enter');

  const heading = page.locator('#mailbox-detail [data-detail-title]');
  await expect(heading).toBeFocused();
  await expect(page.locator('#mailbox-detail [data-viewport]')).toHaveCount(3);

  const selectedTab = page.locator('[role=tablist] button[data-tab][aria-selected=true]');
  const initialTab = await selectedTab.getAttribute('data-tab');
  await page.keyboard.press(']');
  await expect(selectedTab).not.toHaveAttribute('data-tab', initialTab!);

  await page.keyboard.press('?');
  const help = page.locator('dialog:has([data-dialog-close])');
  await expect(help).toHaveAttribute('open', '');
  await page.keyboard.press('Escape');
  await expect(help).not.toHaveAttribute('open', '');

  const readForm = page.locator('#mailbox-detail form[data-action=read]');
  await expect(readForm.locator('input[name=read]')).toHaveValue('0');
  await page.keyboard.press('u');
  await expect(readForm.locator('input[name=read]')).toHaveValue('1');
  await expect(page.locator('#mailbox-live')).toHaveText('Marked as unread');

  const currentRow = page.locator('#mailbox-list a[data-message][aria-current=true]');
  const deletedId = await currentRow.getAttribute('data-message');
  await page.keyboard.press('e');
  await expect(page.locator(`#mailbox-list a[data-message="${deletedId}"]`)).toHaveCount(0);
  await expect(page.locator('#mailbox-list a[data-message][aria-current=true]')).toHaveCount(1);
  await expect(heading).toBeFocused();

  await page.keyboard.press('/');
  await expect(page.locator('#mailbox-search')).toBeFocused();
});
