/**
 * Starts the job dispatcher for the run (labs setup, ingestion, SQL and grading are jobs), imports the lab catalog
 * and clears the local rate-limit windows so repeated local runs do not hit the auth throttles. Local development only.
 */
import { spawn } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { PHP, ROOT, env, php } from './helpers';

export default async function globalSetup(): Promise<void> {
  // Guard: this setup wipes rate limits and creates test accounts - local development installs only.
  const appEnv = env('APP_ENV');
  const host = new URL(process.env.E2E_BASE_URL ?? 'http://localhost/educloudlab/').hostname;
  if (!['local', 'development', 'testing'].includes(appEnv) || !['localhost', '127.0.0.1', '::1'].includes(host)) {
    throw new Error(`E2E refuses to run: APP_ENV=${appEnv || '(empty)'}, host=${host}. Local development installs only.`);
  }
  php('scripts/labs-import.php');
  php('-r', "$app = require 'app/bootstrap.php'; $app->db()->execute('DELETE FROM rate_limits');");
  const dispatcher = spawn(PHP, ['scripts/dispatcher.php'], { cwd: ROOT, stdio: 'ignore', detached: true });
  dispatcher.unref();
  writeFileSync(join(ROOT, 'e2e-results.dispatcher.pid'), String(dispatcher.pid));
}
