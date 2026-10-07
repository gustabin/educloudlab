/**
 * Release 1.3 flow (M8, demo mode — the default of a development install): a student creates and saves a notebook;
 * running it is disabled with an explanation, and LAB-008 is listed as unavailable. Real container runs are covered by
 * the isolation suite (tests/Sandbox) and the notebook integration tests, not here (they need NOTEBOOKS_MODE=docker).
 */
import { expect, test } from '@playwright/test';
import { expectAccessible, login, register } from './helpers';

const run = Date.now().toString(36);
const student = `e2e-m8-${run}@test.example`;

test('notebooks in demo mode', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  await register(page, student, 'Alumna Notebooks');
  await login(page, student);
  await page.goto('app/workspaces');
  await page.getByRole('button', { name: 'Nuevo workspace' }).click();
  await page.fill('#ws-name', 'Notebooks E2E');
  await Promise.all([page.waitForURL(/\/app\/workspaces\/[0-9A-Z]{26}$/), page.locator('#ws-create-form [type=submit]').click()]);
  await page.getByRole('link', { name: 'Notebooks' }).click();
  await expect(page.locator('#nb-list')).toHaveAttribute('aria-busy', 'false');
  await expect(page.locator('.alert-info')).toContainText('Modo demostración');
  await expectAccessible(page, 'notebooks (empty, demo)');

  await page.locator('#nb-new').click();
  await page.fill('#nb-name', 'exploracion');
  await page.getByRole('button', { name: 'Código' }).click();
  await expect(page.locator('.ec-nb-cell')).toHaveCount(3);
  await page.locator('#nb-save').click();
  await expect(page.locator('#nb-list')).toContainText('exploracion');
  await expect(page.locator('#nb-run')).toBeDisabled();
  await expectAccessible(page, 'notebook editor (demo)');

  await page.goto('app/labs');
  await expect(page.locator('body')).toContainText('Necesita el entorno de notebooks');

  expect(consoleErrors).toEqual([]);
});
