/**
 * Release 1.1 flow (M7): ingest → pipeline (template, save, run, per-step report) → lineage → object storage
 * (container, upload with key and metadata, archive tier blocks downloads, lifecycle policy). Every screen is checked
 * with axe-core (WCAG 2.1 A/AA, serious+critical).
 */
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { ROOT, closeDialog, expectAccessible, login, register } from './helpers';

const run = Date.now().toString(36);
const student = `e2e-m7-${run}@test.example`;

test('pipelines, lineage and object storage', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  let workspaceUrl = '';
  await test.step('workspace with a lakehouse, a storage and bronze.customers', async () => {
    await register(page, student, 'Alumna M7');
    await login(page, student);
    await page.goto('app/workspaces');
    await page.getByRole('button', { name: 'Nuevo workspace' }).click();
    await page.fill('#ws-name', 'Pipelines E2E');
    await Promise.all([page.waitForURL(/\/app\/workspaces\/[0-9A-Z]{26}$/), page.locator('#ws-create-form [type=submit]').click()]);
    workspaceUrl = page.url();
    for (const [type, name] of [['lakehouse', 'lago'], ['storage', 'almacen']]) {
      await page.getByRole('button', { name: 'Nuevo recurso' }).click();
      await page.locator(`#res-type-${type}`).check();
      await page.fill('#res-name', name);
      await page.locator('#res-create-form [type=submit]').click();
      await expect(page.locator('#res-list')).toContainText(name);
    }
    await page.fill('#ds-name', 'customers');
    await page.setInputFiles('#ds-file', join(ROOT, 'public/assets/datasets/retail/customers.csv'));
    await page.locator('#ds-upload-form [type=submit]').click();
    await expect(page.locator('[data-ec-ingest]')).toBeVisible({ timeout: 60_000 });
    await page.locator('[data-ec-ingest]').first().click();
    await page.locator('.swal2-input').fill('customers');
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('#ds-list')).toContainText('bronze.customers', { timeout: 60_000 });
    await expect(page.locator('#ds-list')).not.toContainText('Procesando', { timeout: 60_000 });
  });

  await test.step('creates, validates and runs a pipeline from a template', async () => {
    await page.goto(workspaceUrl + '/pipelines');
    await expectAccessible(page, 'pipelines (empty)');
    await page.locator('#pl-new').click();
    await page.fill('#pl-name', 'limpieza');
    await page.locator('#pl-validate').click();
    await expect(page.locator('.swal2-title')).toContainText('La definición es válida');
    await closeDialog(page);
    await page.locator('#pl-save').click();
    await expect(page.locator('#pl-list')).toContainText('limpieza');
    await page.locator('#pl-run').click();
    await expect(page.locator('#pl-runs')).toContainText('Correcto', { timeout: 90_000 });
    await expect(page.locator('#pl-runs')).toContainText('quality_check');
    await expectAccessible(page, 'pipelines (run report)');
  });

  await test.step('the output table shows its lineage', async () => {
    await page.goto(workspaceUrl);
    await expect(page.locator('#ds-list')).toContainText('silver.customers_clean');
    await page.getByRole('button', { name: 'Linaje de silver.customers_clean' }).click();
    await expect(page.locator('#ds-lineage-body')).toContainText('bronze.customers');
    await expectAccessible(page, 'lineage modal');
    await page.keyboard.press('Escape');
  });

  await test.step('object storage: container, upload with metadata, archive and lifecycle', async () => {
    await page.locator('#res-list a[aria-label="Abrir almacen"]').click();
    await expect(page).toHaveURL(/\/app\/resources\/[0-9A-Z]{26}\/storage$/);
    await expect(page.locator('#st-containers')).toHaveAttribute('aria-busy', 'false');
    await expectAccessible(page, 'storage (empty)');
    await page.fill('#st-container-name', 'ventas-crudas');
    await page.locator('#st-container-form [type=submit]').click();
    await expect(page.locator('#st-container-title')).toHaveText('ventas-crudas');

    await page.fill('#st-key', '2025/clientes.csv');
    await page.setInputFiles('#st-file', join(ROOT, 'public/assets/datasets/retail/customers.csv'));
    await page.fill('#st-metadata', '{"origen": "crm"}');
    await page.locator('#st-upload-form [type=submit]').click();
    await expect(page.locator('#st-objects')).toContainText('2025/clientes.csv');
    await expect(page.locator('#st-objects')).toContainText('origen=crm');
    const download = page.getByRole('button', { name: 'Descargar 2025/clientes.csv' });
    await expect(download).toBeEnabled();

    await page.getByRole('combobox', { name: 'Nivel de 2025/clientes.csv' }).selectOption('archive');
    await expect(download).toBeDisabled();

    await page.fill('#st-archive-days', '30');
    await page.fill('#st-delete-days', '365');
    await page.locator('#st-lifecycle-form [type=submit]').click();
    await expect(page.locator('#st-container-summary')).toContainText('archivar a los 30 días, eliminar a los 365 días');
    await expectAccessible(page, 'storage (container)');
  });

  expect(consoleErrors).toEqual([]);
});
