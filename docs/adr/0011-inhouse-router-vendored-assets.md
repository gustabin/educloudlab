# ADR-011: In-house router and migrator; vendored frontend assets

- Status: Accepted

## Decision
- A small router and SQL migration runner, about 100 lines each, instead of extra dependencies.
- jQuery, Bootstrap, SweetAlert2, Font Awesome, CodeMirror 5 and Chart.js are vendored under `public/assets/vendor`. No CDN, which keeps a strict CSP (`script-src 'self'`) and allows offline installs.
