/**
 * MVP release gate (master plan §51 and §30): register → verify (file mail) → login → workspace → upload customers.csv
 * → query → silver/gold transforms → instructor course with LAB-005 → student joins, works and submits → score
 * shown → instructor sees progress → cleanup. Every screen is checked with axe-core (WCAG 2.1 A/AA, serious+critical).
 */
import { join } from 'node:path';
import { expect, test } from '@playwright/test';
import { ROOT, closeDialog, expectAccessible, login, php, register, setSql, switchTenant } from './helpers';

const run = Date.now().toString(36);
const student = `e2e-alumna-${run}@test.example`;
const teacher = `e2e-profe-${run}@test.example`;
const orgName = `Universidad E2E ${run}`;

test('MVP end-to-end flow', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  await test.step('public site is accessible', async () => {
    for (const path of ['./', 'labs', 'labs/bronze-silver-gold', 'courses', 'login', 'register']) {
      await page.goto(path);
      await expectAccessible(page, path);
    }
  });

  await test.step('student registers, verifies the email and logs in', async () => {
    await register(page, student, 'Alumna E2E');
    await login(page, student);
    await expect(page.getByRole('heading', { level: 1 })).toContainText('Alumna E2E');
    await expectAccessible(page, 'dashboard');
  });

  let workspaceUrl = '';
  await test.step('creates a workspace with a lakehouse and uploads customers.csv', async () => {
    await page.goto('app/workspaces');
    await expectAccessible(page, 'workspaces');
    await page.getByRole('button', { name: 'Nuevo workspace' }).click();
    await page.fill('#ws-name', 'Proyecto Retail E2E');
    await Promise.all([page.waitForURL(/\/app\/workspaces\/[0-9A-Z]{26}$/), page.locator('#ws-create-form [type=submit]').click()]);
    workspaceUrl = page.url();

    await page.getByRole('button', { name: 'Nuevo recurso' }).click();
    await page.locator('#res-type-lakehouse').check();
    await page.fill('#res-name', 'lago');
    await page.locator('#res-create-form [type=submit]').click();
    await expect(page.locator('#res-list')).toContainText('lago');

    await page.fill('#ds-name', 'customers');
    await page.setInputFiles('#ds-file', join(ROOT, 'public/assets/datasets/retail/customers.csv'));
    await page.locator('#ds-upload-form [type=submit]').click();
    await expect(page.locator('[data-ec-ingest]')).toBeVisible({ timeout: 60_000 }); // profiled by the dispatcher
    await expectAccessible(page, 'workspace detail');
  });

  await test.step('ingests the raw file into bronze', async () => {
    await page.locator('[data-ec-ingest]').first().click();
    await page.locator('.swal2-input').fill('customers');
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('#ds-list')).toContainText('bronze.customers', { timeout: 60_000 });
    await expect(page.locator('#ds-list')).not.toContainText('Procesando', { timeout: 60_000 });
  });

  await test.step('queries the lakehouse and saves silver and gold tables', async () => {
    await page.goto(workspaceUrl + '/sql');
    await expect(page.locator('#sql-catalog')).toContainText('customers');
    await setSql(page, 'SELECT city, count(*) AS clientes FROM bronze.customers GROUP BY city ORDER BY clientes DESC');
    await page.locator('#sql-run').click();
    await expect(page.locator('#sql-status')).toContainText('filas', { timeout: 30_000 });
    await expect(page.locator('#sql-results table tbody tr').first()).toBeVisible();
    await expectAccessible(page, 'SQL Lab');

    for (const [layer, table, sql] of [
      ['silver', 'customers_clean', 'SELECT DISTINCT customer_id, lower(trim(email)) AS email, city FROM bronze.customers WHERE email IS NOT NULL'],
      ['gold', 'customers_by_city', 'SELECT city, count(*) AS clientes FROM silver.customers_clean GROUP BY city'],
    ]) {
      await setSql(page, sql);
      await page.locator('#sql-save-table').click();
      await page.selectOption('#sql-save-layer', layer);
      await page.fill('#sql-save-table-name', table);
      await page.locator('#sql-save-form [type=submit]').click();
      await expect(page.locator('.swal2-title')).toContainText(`Tabla ${layer}.${table} creada`, { timeout: 60_000 });
      await closeDialog(page);
    }
    await page.locator('#sql-catalog-refresh').click();
    await expect(page.locator('#sql-catalog')).toContainText('customers_by_city');
  });

  let joinCode = '';
  let courseUrl = '';
  await test.step('instructor creates a course with LAB-005 and a join code', async () => {
    await register(page, teacher, 'Profe E2E');
    php('scripts/org.php', 'create', orgName, teacher);
    const orgId = php('scripts/org.php', 'list').split(/\r?\n/).find((l) => l.endsWith(orgName))!.split(' ')[0];
    php('scripts/org.php', 'add-member', orgId, teacher, 'instructor');

    await login(page, teacher);
    await switchTenant(page, orgName);
    await page.goto('app/courses');
    await page.getByRole('button', { name: 'Nuevo curso' }).click();
    await page.fill('#course-code', 'E2E-' + run.toUpperCase().slice(-6));
    await page.fill('#course-title', 'Ingeniería de datos E2E');
    await Promise.all([page.waitForURL(/\/app\/courses\/[0-9A-Z]{26}$/), page.locator('#course-create-form [type=submit]').click()]);
    courseUrl = page.url();
    await page.selectOption('#course-assign-lab', 'LAB-005');
    await Promise.all([page.waitForLoadState('load'), page.locator('#course-assign-form [type=submit]').click()]);
    await expect(page.locator('[data-course-lab-remove="LAB-005"]')).toBeVisible();
    await Promise.all([page.waitForLoadState('load'), page.locator('[data-course-status="published"]').click()]);
    await page.locator('#course-join-rotate').click();
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-title')).toHaveText(/^[A-Z2-9]{5}-[A-Z2-9]{5}$/);
    joinCode = (await page.locator('.swal2-title').innerText()).trim();
    await Promise.all([page.waitForLoadState('load'), page.locator('.swal2-confirm').click()]);
    await expectAccessible(page, 'course (staff)');
  });

  let attemptUrl = '';
  await test.step('student joins the course and starts LAB-005', async () => {
    await login(page, student);
    await page.goto('app/courses');
    await expectAccessible(page, 'courses');
    await page.fill('#course-join-code', joinCode.toLowerCase());
    await page.locator('#course-join-form [type=submit]').click();
    await expect(page.locator('.swal2-title')).toContainText('inscrito');
    await Promise.all([page.waitForURL(courseUrl), page.locator('.swal2-confirm').click()]);
    await Promise.all([page.waitForURL(/\/app\/lab-attempts\//), page.locator('[data-course-lab-start="LAB-005"]').click()]);
    attemptUrl = page.url();
    await expect(page.locator('#lab-submit')).toBeEnabled({ timeout: 120_000 }); // setup jobs loaded the samples
    await expectAccessible(page, 'lab attempt');
  });

  await test.step('works on the lab in its SQL Lab and gets graded', async () => {
    const labSql = await page.locator('a', { hasText: 'Abrir SQL Lab' }).getAttribute('href');
    await page.goto(labSql!);
    await expect(page.locator('#sql-catalog')).toContainText('customers');
    await setSql(page, 'SELECT DISTINCT customer_id, first_name, last_name, lower(trim(email)) AS email, city, signup_date FROM bronze.customers WHERE email IS NOT NULL');
    await page.locator('#sql-save-table').click();
    await page.selectOption('#sql-save-layer', 'silver');
    await page.fill('#sql-save-table-name', 'customers');
    await page.locator('#sql-save-form [type=submit]').click();
    await expect(page.locator('.swal2-title')).toContainText('Tabla silver.customers creada', { timeout: 60_000 });
    await closeDialog(page);

    await page.goto(attemptUrl);
    await page.locator('#lab-submit').click();
    await expect(page.locator('.swal2-title')).toHaveText('Puntuación: 30 / 100', { timeout: 120_000 });
    await closeDialog(page);
    await expect(page.locator('#task-t1 [data-result]')).toContainText('Superado');
    await expect(page.locator('#task-t2 [data-result]')).toContainText('Aún no superado');
  });

  await test.step('instructor sees the student progress and reviews the attempt', async () => {
    await login(page, teacher);
    await switchTenant(page, orgName);
    await page.goto(courseUrl + '/progress');
    await expect(page.locator('.ec-progress-table')).toContainText('Alumna E2E');
    await expect(page.locator('.ec-progress-cell')).toContainText('30/100');
    await expectAccessible(page, 'course progress');
    await Promise.all([page.waitForURL(attemptUrl), page.locator('.ec-progress-cell').click()]);
    await expect(page.locator('#lab-submit')).toHaveCount(0); // read-only review
  });

  await test.step('cleanup: the student abandons the lab', async () => {
    await login(page, student);
    await switchTenant(page, orgName); // the course attempt lives in the organization
    await page.goto(attemptUrl);
    await page.locator('#lab-abandon').click();
    await Promise.all([page.waitForURL(/\/app\/labs$/), page.locator('.swal2-confirm').click()]);
    await expectAccessible(page, 'labs catalog');
  });

  await test.step('platform admin monitor shows the run', async () => {
    php('scripts/admin.php', 'grant', teacher);
    await login(page, teacher);
    await page.goto('app/admin');
    await expect(page.locator('[data-admin-list="jobs"] table')).toContainText('validate');
    await page.getByRole('tab', { name: 'Usuarios' }).click();
    await page.fill('#admin-users-q', student);
    await expect(page.locator('[data-admin-list="users"] table')).toContainText(student);
    // Bootstrap fades tab panes in: measure contrast once the pane is fully opaque.
    await expect.poll(() => page.locator('#admin-users-pane').evaluate((el) => getComputedStyle(el).opacity)).toBe('1');
    await expectAccessible(page, 'admin monitor');
    php('scripts/admin.php', 'revoke', teacher);
  });

  // The only console errors allowed are the expected ones from failed API calls (none happen in this flow).
  expect(consoleErrors).toEqual([]);
});
