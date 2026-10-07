/**
 * Release 1.2 flow (M10b): an instructor builds course content (module, lesson in Markdown, publish), a student joins,
 * is notified through the bell, reads the lesson and marks it completed; the progress grid shows it. axe on every screen.
 */
import { expect, test } from '@playwright/test';
import { expectAccessible, login, php, register, switchTenant } from './helpers';

const run = Date.now().toString(36);
const student = `e2e-m10b-alumna-${run}@test.example`;
const teacher = `e2e-m10b-profe-${run}@test.example`;
const orgName = `Academia E2E ${run}`;

test('course modules, lessons and notifications', async ({ page }) => {
  const consoleErrors: string[] = [];
  page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

  let courseUrl = '';
  let joinCode = '';
  await test.step('instructor creates a course with a module and a published lesson', async () => {
    await register(page, teacher, 'Profe Contenidos');
    php('scripts/org.php', 'create', orgName, teacher);
    const orgId = php('scripts/org.php', 'list').split(/\r?\n/).find((l) => l.endsWith(orgName))!.split(' ')[0];
    php('scripts/org.php', 'add-member', orgId, teacher, 'instructor');
    await login(page, teacher);
    await switchTenant(page, orgName);
    await page.goto('app/courses');
    await page.getByRole('button', { name: 'Nuevo curso' }).click();
    await page.fill('#course-code', 'CONT-' + run.toUpperCase().slice(-5));
    await page.fill('#course-title', 'Fundamentos de datos');
    await Promise.all([page.waitForURL(/\/app\/courses\/[0-9A-Z]{26}$/), page.locator('#course-create-form [type=submit]').click()]);
    courseUrl = page.url();
    await expect(page.locator('#course-content')).toContainText('Organiza el curso');

    await page.getByRole('button', { name: 'Nuevo módulo' }).click();
    await page.fill('#swal-module-title', 'Introducción');
    await Promise.all([page.waitForLoadState('load'), page.locator('.swal2-confirm').click()]);
    await expect(page.locator('#course-content')).toContainText('Introducción');

    await page.getByRole('button', { name: 'Nueva lección en Introducción' }).click();
    await page.locator('.swal2-input').fill('Qué es un lakehouse');
    await Promise.all([page.waitForURL(/\/app\/lessons\/[0-9A-Z]{26}$/), page.locator('.swal2-confirm').click()]);
    await page.fill('#lesson-edit-body', '## Capas\n\n| Capa | Contenido |\n|---|---|\n| bronze | datos crudos tipados |\n\n<script>alert(1)</script>');
    await page.fill('#lesson-edit-minutes', '10');
    await Promise.all([page.waitForLoadState('load'), page.locator('#lesson-edit-form [type=submit]').click()]);
    await expect(page.locator('.ec-markdown h2')).toHaveText('Capas');
    await expect(page.locator('.ec-markdown')).toContainText('<script>alert(1)</script>'); // shown as text
    await expectAccessible(page, 'lesson (staff editor)');

    await page.goto(courseUrl);
    await Promise.all([page.waitForLoadState('load'), page.locator('[data-lesson] [data-lesson-status="published"]').click()]);
    await Promise.all([page.waitForLoadState('load'), page.locator('[data-module] [data-module-status="published"]').click()]);
    await Promise.all([page.waitForLoadState('load'), page.locator('[data-course-status="published"]').click()]);
    await page.locator('#course-join-rotate').click();
    await page.locator('.swal2-confirm').click();
    await expect(page.locator('.swal2-title')).toHaveText(/^[A-Z2-9]{5}-[A-Z2-9]{5}$/);
    joinCode = (await page.locator('.swal2-title').innerText()).trim();
    await Promise.all([page.waitForLoadState('load'), page.locator('.swal2-confirm').click()]);
    await expectAccessible(page, 'course content (staff)');
  });

  await test.step('student joins, gets notified and completes the lesson', async () => {
    await register(page, student, 'Alumna Contenidos');
    await login(page, student);
    await page.goto('app/courses');
    await page.fill('#course-join-code', joinCode);
    await page.locator('#course-join-form [type=submit]').click();
    await Promise.all([page.waitForURL(courseUrl), page.locator('.swal2-confirm').click()]);
    await expect(page.locator('#course-content')).toContainText('Qué es un lakehouse');
    await expect(page.locator('#course-content')).not.toContainText('Borrador');
    await expectAccessible(page, 'course content (student)');

    // This lesson was published before the student enrolled: notifications go to the students enrolled when the
    // content becomes visible, so the bell has nothing yet. The next step publishes a new lesson.
    await expect(page.locator('#ec-notif-toggle')).toHaveAttribute('aria-label', 'Notificaciones');
  });

  await test.step('a newly published lesson reaches the enrolled student through the bell', async () => {
    await login(page, teacher);
    await switchTenant(page, orgName);
    await page.goto(courseUrl);
    await page.getByRole('button', { name: 'Nueva lección en Introducción' }).click();
    await page.locator('.swal2-input').fill('Capa silver');
    await Promise.all([page.waitForURL(/\/app\/lessons\//), page.locator('.swal2-confirm').click()]);
    await page.goto(courseUrl);
    await Promise.all([page.waitForLoadState('load'), page.locator('[data-lesson]').filter({ hasText: 'Capa silver' })
      .locator('[data-lesson-status="published"]').click()]);

    await login(page, student);
    await switchTenant(page, orgName);
    await expect(page.locator('#ec-notif-count')).toHaveText('1');
    await expect(page.locator('#ec-notif-toggle')).toHaveAttribute('aria-label', 'Notificaciones (1 sin leer)');
    await page.locator('#ec-notif-toggle').click();
    await expect(page.locator('#ec-notif-list')).toContainText('Nueva lección: Capa silver');
    await expectAccessible(page, 'notification dropdown');
    await Promise.all([page.waitForURL(/\/app\/lessons\//), page.locator('[data-notif]').first().click()]);
    await expect(page.locator('#lesson-title')).toContainText('Capa silver');
    await expect(page.locator('#ec-notif-count')).toBeHidden();

    await page.locator('a', { hasText: 'Anterior' }).click();
    await expect(page.locator('#lesson-title')).toContainText('Qué es un lakehouse');
    await Promise.all([page.waitForLoadState('load'), page.locator('#lesson-complete').click()]);
    await expect(page.locator('#lesson-complete')).toHaveAttribute('aria-pressed', 'true');
    await expectAccessible(page, 'lesson (student)');
  });

  await test.step('the instructor sees the lesson progress', async () => {
    await login(page, teacher);
    await switchTenant(page, orgName);
    await page.goto(courseUrl + '/progress');
    await expect(page.locator('.ec-progress-table')).toContainText('1/2');
    await expectAccessible(page, 'course progress with lessons');
  });

  expect(consoleErrors).toEqual([]);
});
