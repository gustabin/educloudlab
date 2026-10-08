import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, unlinkSync } from 'node:fs';
import { join } from 'node:path';
import { ROOT } from './helpers';

export default async function globalTeardown(): Promise<void> {
  const file = join(ROOT, 'e2e-results.dispatcher.pid');
  if (!existsSync(file)) return;
  for (const pid of readFileSync(file, 'utf8').split(/\s+/).filter((p) => /^[1-9][0-9]*$/.test(p))) {
    try {
      if (process.platform === 'win32') {
        execFileSync('taskkill', ['/T', '/F', '/PID', pid], { stdio: 'ignore' });
      } else {
        process.kill(Number(pid), 'SIGTERM');
      }
    } catch {
      // already stopped
    }
  }
  unlinkSync(file);
}
