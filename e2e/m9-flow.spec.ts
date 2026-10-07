/**
 * Release 1.2 flow (M9): gold star schema (SQL Lab) → semantic model (editor, validate, save, explore) → dashboard
 * (template, save, viewer with KPI, charts and a region filter). Every screen is checked with axe-core.
 */
import { join } from 'node:path';
import { expect, Page, test } from '@playwright/test';
import { ROOT, closeDialog, expectAccessible, login, register, setSql } from './helpers';

const run = Date.now().toString(36);
const student = `e2e-m9-${run}@test.example`;

const MODEL = {
  fact: 'gold.fact_sales',
  relationships: [{ table: 'gold.dim_store', fact_column: 'store_key', column: 'store_key' }],
  measures: [
    { name: 'ingresos', label: 'Ingresos', agg: 'sum', column: 'line_total', format: 'currency' },
    { name: 'pedidos', label: 'Pedidos', agg: 'count_distinct', column: 'order_id', format: 'integer' },
  ],
  dimensions: [
    { name: 'region', label: 'Región', table: 'gold.dim_store', column: 'region' },
    { name: 'mes', label: 'Mes', column: 'order_date', grain: 'month' },
  ],
};

async function uploadAndIngest(page: Page, name: string): Promise<void> {
  await page.fill('#ds-name', name);
  await page.setInputFiles('#ds-file', join(ROOT, `public/assets/datasets/retail/${name}.csv`));
  await page.locator('#ds-upload-form [type=submit]').click();
  const ingest = page.getByRole('button', { name: `Ingerir ${name}` });
  await expect(ingest).toBeVisible({ timeout: 60_000 });
  await ingest.click();
  await page.locator('.swal2-input').fill(name);
  await page.locator('.swal2-confirm').click();
  await expect(page.locator('#ds-list')).toContainText(`bronze.${name}`, { timeout: 60_000 });
  await expect(page.locator('#ds-list')).not.toContainText('Procesando', { timeout: 60_000 });
}

test('warehouse, semantic model and dashboard', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  let workspaceUrl = '';
  await test.step('bronze tables and a gold star schema', async () => {
    await register(page, student, 'Alumna M9');
    await login(page, student);
    await page.goto('app/workspaces');
    await page.getByRole('button', { name: 'Nuevo workspace' }).click();
    await page.fill('#ws-name', 'Analítica E2E');
    await Promise.all([page.waitForURL(/\/app\/workspaces\/[0-9A-Z]{26}$/), page.locator('#ws-create-form [type=submit]').click()]);
    workspaceUrl = page.url();
    await page.getByRole('button', { name: 'Nuevo recurso' }).click();
    await page.locator('#res-type-lakehouse').check();
    await page.fill('#res-name', 'lago');
    await page.locator('#res-create-form [type=submit]').click();
    await expect(page.locator('#res-list')).toContainText('lago');
    await uploadAndIngest(page, 'stores');
    await uploadAndIngest(page, 'orders');

    await page.goto(workspaceUrl + '/sql');
    for (const [table, sql] of [
      ['dim_store', 'SELECT row_number() OVER (ORDER BY store_id) AS store_key, store_id, region FROM bronze.stores'],
      ['fact_sales', 'SELECT o.order_id, o.order_date, s.store_key, 10.0 AS line_total FROM bronze.orders o JOIN gold.dim_store s ON s.store_id = o.store_id'],
    ]) {
      await setSql(page, sql);
      await page.locator('#sql-save-table').click();
      await page.selectOption('#sql-save-layer', 'gold');
      await page.fill('#sql-save-table-name', table);
      await page.locator('#sql-save-form [type=submit]').click();
      await expect(page.locator('.swal2-title')).toContainText(`Tabla gold.${table} creada`, { timeout: 60_000 });
      await closeDialog(page);
    }
  });

  await test.step('semantic model: validate, save and explore', async () => {
    await page.goto(workspaceUrl + '/analytics');
    await expect(page.locator('.ec-catalog-list')).toContainText('gold.fact_sales');
    await expect(page.locator('#an-models')).toHaveAttribute('aria-busy', 'false');
    await expectAccessible(page, 'analytics (empty)');
    await page.getByRole('button', { name: 'Nuevo modelo semántico' }).click();
    await page.fill('#an-name', 'ventas');
    await setSql(page, JSON.stringify(MODEL, null, 2));
    await page.locator('#an-validate').click();
    await expect(page.locator('.swal2-title')).toContainText('El modelo es válido');
    await closeDialog(page);
    await page.locator('#an-save').click();
    await expect(page.locator('#an-models')).toContainText('ventas');
    await expect(page.locator('#an-explore')).toBeVisible();
    await page.selectOption('#an-explore-dimension', 'region');
    await page.locator('#an-explore-form [type=submit]').click();
    await expect(page.locator('#an-explore-status')).toContainText('Actualizado', { timeout: 60_000 });
    await expect(page.locator('#an-explore-result canvas[role=img]')).toHaveCount(1);
    await expectAccessible(page, 'analytics (model + explore)');
  });

  await test.step('dashboard from the template, viewer with a filter', async () => {
    await page.getByRole('button', { name: 'Nuevo dashboard' }).click();
    await page.fill('#an-name', 'panel');
    await page.locator('#an-save').click();
    await expect(page.locator('#an-dashboards')).toContainText('panel');
    await Promise.all([page.waitForURL(/\/app\/dashboards\/[0-9A-Z]{26}$/), page.locator('#an-open').click()]);
    await expect(page.locator('#db-status')).toContainText('Actualizado', { timeout: 60_000 });
    await expect(page.locator('[data-widget="pedidos"] .ec-kpi-value')).toHaveText(/^\d[\d.]*$/);
    await expect(page.locator('[data-widget="por_region"] canvas[role=img]')).toHaveCount(1);
    await expectAccessible(page, 'dashboard viewer');

    const total = await page.locator('[data-widget="pedidos"] .ec-kpi-value').innerText();
    const region = page.locator('[data-filter="region"]');
    await expect(region.locator('option')).not.toHaveCount(1);
    await region.selectOption({ index: 1 });
    await page.locator('#db-filters [type=submit]').click();
    await expect(page.locator('#db-status')).toContainText('Actualizado', { timeout: 60_000 });
    await expect(page.locator('[data-widget="pedidos"] .ec-kpi-value')).not.toHaveText(total);
  });

  expect(consoleErrors).toEqual([]);
});
