import { execFileSync } from 'node:child_process';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, resolve } from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, Page } from '@playwright/test';

export const ROOT = resolve(__dirname, '..');
export const PHP = process.env.E2E_PHP ?? 'php';
export const PASSWORD = 'una frase larga para e2e 2026';

/** Values from the local .env (development install). */
export function env(key: string): string {
  const line = readFileSync(join(ROOT, '.env'), 'utf8').split(/\r?\n/).find((l) => l.startsWith(key + '='));
  return (line ?? '').slice(key.length + 1).replace(/^"|"$/g, '');
}

export function php(...args: string[]): string {
  return execFileSync(PHP, args, { cwd: ROOT, encoding: 'utf8' });
}

/** Delivers queued emails (file driver) and returns the newest token sent to $email for $path (/verify-email…). */
export function tokenFromMail(email: string, path: string): string {
  php('scripts/mailer.php', '--once');
  const dir = join(env('STORAGE_PATH'), 'mail');
  const files = readdirSync(dir).filter((f) => f.endsWith('.eml'))
    .map((f) => ({ f, t: statSync(join(dir, f)).mtimeMs })).sort((a, b) => b.t - a.t);
  for (const { f } of files) {
    const raw = readFileSync(join(dir, f), 'utf8');
    if (!raw.includes(email)) continue;
    const decoded = raw.replace(/=\r?\n/g, '').replace(/=3D/g, '=');
    const m = decoded.match(new RegExp(path.replace(/[/-]/g, '\\$&') + '\\?token=([A-Za-z0-9_-]+)'));
    if (m) return m[1];
  }
  throw new Error(`no ${path} email for ${email}`);
}

/** axe-core on the current page: no serious or critical WCAG 2.1 A/AA violations. */
export async function expectAccessible(page: Page, name: string): Promise<void> {
  // Measure the settled page: no hover state on the last clicked button and no running CSS transition or animation
  // (fades of tabs, modals and buttons). Otherwise contrast is measured on half-transparent colours, which slower
  // CI runners catch (found by the first GitHub Actions runs).
  await page.mouse.move(0, 0);
  await page.waitForFunction(() => document.getAnimations().every((a) => a.playState !== 'running'), null, { timeout: 10_000 });
  // CodeMirror 5 (third-party editor) is excluded: its internal scroller is flagged, but the editor itself is reachable
  // and operable with the keyboard through its own textarea (labelled in sqllab.js).
  const results = await new AxeBuilder({ page }).withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa']).exclude('.CodeMirror').analyze();
  const blocking = results.violations
    .filter((v) => v.impact === 'serious' || v.impact === 'critical')
    .map((v) => `${v.id} (${v.impact}): ${v.nodes.slice(0, 3).map((n) => n.target.join(' ') + (n.any[0] ? ' [' + n.any[0].message + ']' : '')).join(' | ')}`);
  expect(blocking, `accessibility violations on ${name}`).toEqual([]);
}

export async function register(page: Page, email: string, name: string): Promise<void> {
  await page.context().clearCookies();
  await page.goto('register');
  await page.fill('#reg-name', name);
  await page.fill('#reg-email', email);
  await page.fill('#reg-password', PASSWORD);
  await page.fill('#reg-password2', PASSWORD);
  const [registered] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/v1/auth/register')),
    page.getByRole('button', { name: 'Crear cuenta', exact: true }).click(),
  ]);
  expect(registered.status()).toBe(202);
  const token = tokenFromMail(email, '/verify-email');
  await page.goto('verify-email?token=' + token);
  await page.fill('#verify-password', PASSWORD);
  const [verified] = await Promise.all([
    page.waitForResponse((r) => r.url().endsWith('/api/v1/auth/verify-email')),
    page.getByRole('button', { name: 'Confirmar mi correo' }).click(),
  ]);
  expect(verified.status()).toBe(200);
  // The form then redirects by itself; let it land before the caller navigates (otherwise the two navigations race).
  await page.waitForURL(/\/login\?notice=verified$/);
}

export async function login(page: Page, email: string): Promise<void> {
  await page.context().clearCookies();
  await page.goto('login');
  await page.fill('#login-email', email);
  await page.fill('#login-password', PASSWORD);
  await Promise.all([page.waitForURL(/\/app$/), page.getByRole('button', { name: 'Iniciar sesión', exact: true }).click()]);
}

/** Switches the session to the named organization through the tenant switcher. */
export async function switchTenant(page: Page, orgName: string): Promise<void> {
  await page.locator('.ec-tenant-switcher').click();
  await Promise.all([page.waitForLoadState('load'), page.getByRole('button', { name: orgName }).click()]);
  await expect(page.locator('.ec-tenant-switcher')).toContainText(orgName);
}

/** Puts SQL into the CodeMirror editor of the SQL Lab. */
export async function setSql(page: Page, sql: string): Promise<void> {
  await page.evaluate((text) => {
    const cm = (document.querySelector('.CodeMirror') as unknown as { CodeMirror: { setValue(v: string): void } }).CodeMirror;
    cm.setValue(text);
  }, sql);
}

export async function closeDialog(page: Page): Promise<void> {
  await page.locator('.swal2-confirm').click();
  await expect(page.locator('.swal2-popup')).toBeHidden();
}
