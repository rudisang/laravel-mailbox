import { expect, type Page } from '@playwright/test';

/**
 * Clears the demo mailbox through the CSRF-protected clear endpoint, then seeds it via
 * the given demo route (defaults to `/demo/send-all`).
 *
 * The workbench demo routes are additive by design: `/demo/send-all` and
 * `/demo/send/{scenario}` never clear anything, they only append. Because
 * `playwright.config.ts` reuses one Testbench server for the whole suite (and runs
 * every spec with `workers: 1` against that one shared mailbox), specs that just
 * seeded without resetting first were accumulating duplicate messages across runs —
 * which quietly broke any assertion that assumed a specific message count, "the
 * newest message" by list position, or a unique subject/text match. `specs/a11y.spec.ts`
 * fixed exactly this non-determinism for itself by clearing the mailbox through the
 * clear form's CSRF-protected POST before seeding; this helper is that same mechanism,
 * extracted so every other spec that touches mailbox content resets the same way
 * instead of relying on `workers: 1` plus assumptions about what earlier specs left
 * behind.
 */
export async function resetAndSeed(page: Page, seedPath = '/demo/send-all'): Promise<void> {
  await page.goto('/_mailbox');
  const clearForm = page.locator('form[data-action="clear"]');
  const clearUrl = await clearForm.getAttribute('action');
  const csrfToken = await clearForm.locator('input[name="_token"]').getAttribute('value');
  const clearResponse = await page.request.post(clearUrl ?? '', { headers: { 'X-CSRF-TOKEN': csrfToken ?? '' } });
  expect(clearResponse.ok()).toBeTruthy();

  await page.goto(seedPath);
  await expect(page.locator('#mailbox-list')).toBeVisible();
}
