// Copies the minimal distributable files of frontend libraries into public/assets/vendor (ADR-011).
// Usage: npm install && npm run vendor
import { cpSync, mkdirSync, rmSync, existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const nm = join(root, 'node_modules');
const out = join(root, 'public', 'assets', 'vendor');

const files = {
  'jquery/jquery.min.js': 'jquery/dist/jquery.min.js',
  'jquery/LICENSE.txt': 'jquery/LICENSE.txt',
  'bootstrap/bootstrap.min.css': 'bootstrap/dist/css/bootstrap.min.css',
  'bootstrap/bootstrap.bundle.min.js': 'bootstrap/dist/js/bootstrap.bundle.min.js',
  'bootstrap/LICENSE': 'bootstrap/LICENSE',
  // Non-".all" build: styles come from the CSS file, so CSP can stay style-src 'self'.
  'sweetalert2/sweetalert2.min.js': 'sweetalert2/dist/sweetalert2.min.js',
  'sweetalert2/sweetalert2.min.css': 'sweetalert2/dist/sweetalert2.min.css',
  'sweetalert2/LICENSE': 'sweetalert2/LICENSE',
  'fontawesome/css/all.min.css': '@fortawesome/fontawesome-free/css/all.min.css',
  'fontawesome/webfonts': '@fortawesome/fontawesome-free/webfonts',
  'fontawesome/LICENSE.txt': '@fortawesome/fontawesome-free/LICENSE.txt',
  // CodeMirror 5 (SQL Lab editor): core, SQL mode, bracket matching, SQL autocompletion.
  'codemirror/codemirror.js': 'codemirror/lib/codemirror.js',
  'codemirror/codemirror.css': 'codemirror/lib/codemirror.css',
  'codemirror/mode/sql.js': 'codemirror/mode/sql/sql.js',
  'codemirror/addon/matchbrackets.js': 'codemirror/addon/edit/matchbrackets.js',
  'codemirror/addon/show-hint.js': 'codemirror/addon/hint/show-hint.js',
  'codemirror/addon/show-hint.css': 'codemirror/addon/hint/show-hint.css',
  'codemirror/addon/sql-hint.js': 'codemirror/addon/hint/sql-hint.js',
  'codemirror/LICENSE': 'codemirror/LICENSE',
};

rmSync(out, { recursive: true, force: true });
const versions = {};
for (const [dest, src] of Object.entries(files)) {
  const from = join(nm, src);
  if (!existsSync(from)) {
    console.error(`Missing ${src} - run npm install first`);
    process.exit(1);
  }
  const to = join(out, dest);
  mkdirSync(dirname(to), { recursive: true });
  cpSync(from, to, { recursive: true });
  const pkg = src.startsWith('@') ? src.split('/').slice(0, 2).join('/') : src.split('/')[0];
  versions[pkg] ??= JSON.parse(readFileSync(join(nm, pkg, 'package.json'), 'utf8')).version;
}
writeFileSync(join(out, 'VERSIONS.json'), JSON.stringify(versions, null, 2) + '\n');
console.log('Vendored:', versions);
