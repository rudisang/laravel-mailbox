import { expect, test } from '@playwright/test';
import { resetAndSeed } from './helpers';

test('mailbox workflows are keyboard-complete', async ({ page }) => {
  await resetAndSeed(page);
  await expect(page.locator('[data-theme-toggle]')).toBeVisible();
  await expect(page.locator('[data-shortcuts-help]')).toBeVisible();
  await expect(page.locator('form[data-action=clear]')).toBeVisible();

  const cursorRow = page.locator('#mailbox-list a[data-message].is-cursor');

  await page.keyboard.press('j');
  const firstRowId = await cursorRow.getAttribute('data-message');
  expect(firstRowId).not.toBeNull();

  await page.keyboard.press('j');
  const secondRowId = await cursorRow.getAttribute('data-message');
  expect(secondRowId).not.toBeNull();
  expect(secondRowId).not.toBe(firstRowId);

  // `k` (move-up) coverage: from the second row, `k` must move the cursor back to the
  // first row (the row j/k operate on, via the `.is-cursor` class and focus — see
  // `moveCursor()` in resources/dist/mailbox.js), not just decrement some hidden index.
  await page.keyboard.press('k');
  await expect(cursorRow).toHaveAttribute('data-message', firstRowId!);
  await expect(cursorRow).toBeFocused();

  // Move back down to the second row so the rest of this test (Enter opening the
  // currently-focused row) is unaffected by the k assertion above.
  await page.keyboard.press('j');
  await expect(cursorRow).toHaveAttribute('data-message', secondRowId!);

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
