/**
 * Release 1.3 flow (M8). The spec follows the install's NOTEBOOKS_MODE:
 * - demo (default of a fresh install): a student creates and saves a notebook; running it is disabled with an
 *   explanation, and LAB-008 is listed as unavailable;
 * - docker: the same notebook runs in the sandbox through the dedicated notebook worker (started by global-setup),
 *   and the outputs of every code cell are shown. Isolation itself is covered by tests/Sandbox.
 */
import { expect, test } from '@playwright/test';
import { env, expectAccessible, login, register } from './helpers';

const run = Date.now().toString(36);
const student = `e2e-m8-${run}@test.example`;
const docker = env('NOTEBOOKS_MODE') === 'docker';

test(docker ? 'notebooks run in the Docker sandbox' : 'notebooks in demo mode', async ({ page }) => {
  test.setTimeout(180_000);
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
  if (docker) {
    await expect(page.locator('.alert-info')).toHaveCount(0);
  } else {
    await expect(page.locator('.alert-info')).toContainText('Modo demostración');
  }
  await expectAccessible(page, `notebooks (empty, ${docker ? 'docker' : 'demo'})`);

  await page.locator('#nb-new').click();
  await page.fill('#nb-name', 'exploracion');
  await page.getByRole('button', { name: 'Código' }).click();
  await expect(page.locator('.ec-nb-cell')).toHaveCount(3);
  await page.locator('.ec-nb-cell').nth(2).locator('.CodeMirror').click();
  await page.keyboard.type("print('hola desde el sandbox'); 6 * 7");
  await page.locator('#nb-save').click();
  await expect(page.locator('#nb-list')).toContainText('exploracion');

  if (docker) {
    await expect(page.locator('#nb-run')).toBeEnabled();
    await page.locator('#nb-run').click();
    // The workspace is new: the template cell's lakehouse() explains what to do and stops the run.
    await expect(page.locator('.ec-nb-error')).toContainText('aún no tiene lakehouse', { timeout: 120_000 });
    await expect(page.locator('#nb-run')).toBeEnabled();

    // Replace the template cell with pandas code and run again.
    await page.locator('.ec-nb-cell').nth(1).locator('.CodeMirror').click();
    await page.keyboard.press('Control+A');
    await page.keyboard.type("import pandas as pd; pd.DataFrame({'region': ['Norte', 'Sur'], 'pedidos': [3, 5]})");
    await page.locator('#nb-save').click();
    await expect(page.locator('#nb-save')).toBeEnabled();
    await page.locator('#nb-run').click();
    // Template cell: a DataFrame rendered as a table; typed cell: stdout + value.
    await expect(page.locator('.ec-nb-value')).toHaveText('42', { timeout: 120_000 });
    await expect(page.locator('.ec-nb-stdout')).toContainText('hola desde el sandbox');
    await expect(page.locator('.ec-nb-cell').nth(1).locator('table')).toBeVisible();
    await expect(page.locator('#nb-status')).not.toBeEmpty();
    await expectAccessible(page, 'notebook editor (after a run)');
  } else {
    await expect(page.locator('#nb-run')).toBeDisabled();
    await expectAccessible(page, 'notebook editor (demo)');
  }

  await page.goto('app/labs');
  if (docker) {
    await expect(page.locator('body')).not.toContainText('Necesita el entorno de notebooks');
  } else {
    await expect(page.locator('body')).toContainText('Necesita el entorno de notebooks');
  }

  expect(consoleErrors).toEqual([]);
});
