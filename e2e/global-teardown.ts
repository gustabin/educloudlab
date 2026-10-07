import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT } from './helpers';

export default async function globalTeardown(): Promise<void> {
  const file = join(ROOT, 'e2e-results.dispatcher.pid');
  if (!existsSync(file)) return;
  const pid = readFileSync(file, 'utf8').trim();
  try {
    if (process.platform === 'win32') {
      execFileSync('taskkill', ['/T', '/F', '/PID', pid], { stdio: 'ignore' });
    } else {
      process.kill(Number(pid), 'SIGTERM');
    }
  } catch {
    // already stopped
  }
  unlinkSync(file);
}
