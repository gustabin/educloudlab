# Third-Party Notices

EduCloud Lab uses the third-party components listed below. Their licenses were verified with `composer licenses` (runtime with `--no-dev`) on 2026-10-07 and must be re-checked whenever dependencies change.

Each component remains under its own license. License texts ship inside each package directory (`vendor/<package>/LICENSE`).

## Notes on specific licenses

- **phpmailer/phpmailer (LGPL-2.1-only):** used as an unmodified library installed through Composer. Its license and notice must be kept. If the library is ever modified, those modifications must be released under LGPL-2.1.
- **nette/utils, nette/schema (BSD-3-Clause OR GPL-2.0 OR GPL-3.0):** EduCloud Lab elects the **BSD-3-Clause** option.

## Runtime PHP dependencies (Composer `require` and their dependencies, distributed with the app)

| Package | Version | License |
|---|---|---|
| dflydev/dot-access-data | v3.0.3 | MIT |
| firebase/php-jwt | v7.2.1 | BSD-3-Clause |
| graham-campbell/result-type | v1.2.0 | MIT |
| league/commonmark | 2.10.3 | BSD-3-Clause |
| league/config | v1.2.0 | BSD-3-Clause |
| nette/schema | v1.3.6 | BSD-3-Clause, GPL-2.0-only, GPL-3.0-only |
| nette/utils | v4.0.10 | BSD-3-Clause, GPL-2.0-only, GPL-3.0-only |
| opis/json-schema | 2.6.0 | Apache-2.0 |
| opis/string | 2.1.0 | Apache-2.0 |
| opis/uri | 1.1.0 | Apache-2.0 |
| phpmailer/phpmailer | v6.12.0 | LGPL-2.1-only |
| phpoption/phpoption | 1.10.0 | Apache-2.0 |
| psr/event-dispatcher | 1.0.0 | MIT |
| symfony/deprecation-contracts | v3.7.1 | MIT |
| symfony/polyfill-ctype | v1.37.0 | MIT |
| symfony/polyfill-mbstring | v1.43.0 | MIT |
| symfony/polyfill-php80 | v1.43.0 | MIT |
| vlucas/phpdotenv | v5.7.0 | BSD-3-Clause |

## Development-only PHP dependencies (`require-dev`, not distributed)

| Package | Version | License |
|---|---|---|
| myclabs/deep-copy | 1.14.0 | MIT |
| nikic/php-parser | v5.9.0 | BSD-3-Clause |
| phar-io/manifest | 2.0.4 | BSD-3-Clause |
| phar-io/version | 3.2.1 | BSD-3-Clause |
| phpstan/phpstan | 2.3.0 | MIT |
| phpunit/php-code-coverage | 10.1.16 | BSD-3-Clause |
| phpunit/php-file-iterator | 4.1.0 | BSD-3-Clause |
| phpunit/php-invoker | 4.0.0 | BSD-3-Clause |
| phpunit/php-text-template | 3.0.1 | BSD-3-Clause |
| phpunit/php-timer | 6.0.0 | BSD-3-Clause |
| phpunit/phpunit | 10.5.66 | BSD-3-Clause |
| sebastian/cli-parser | 2.0.1 | BSD-3-Clause |
| sebastian/code-unit | 2.0.0 | BSD-3-Clause |
| sebastian/code-unit-reverse-lookup | 3.0.0 | BSD-3-Clause |
| sebastian/comparator | 5.0.5 | BSD-3-Clause |
| sebastian/complexity | 3.2.0 | BSD-3-Clause |
| sebastian/diff | 5.1.1 | BSD-3-Clause |
| sebastian/environment | 6.1.0 | BSD-3-Clause |
| sebastian/exporter | 5.1.4 | BSD-3-Clause |
| sebastian/global-state | 6.0.2 | BSD-3-Clause |
| sebastian/lines-of-code | 2.0.2 | BSD-3-Clause |
| sebastian/object-enumerator | 5.0.0 | BSD-3-Clause |
| sebastian/object-reflector | 3.0.0 | BSD-3-Clause |
| sebastian/recursion-context | 5.0.2 | BSD-3-Clause |
| sebastian/type | 4.0.0 | BSD-3-Clause |
| sebastian/version | 4.0.1 | BSD-3-Clause |
| squizlabs/php_codesniffer | 3.13.6 | BSD-3-Clause |
| theseer/tokenizer | 1.3.1 | BSD-3-Clause |

## Vendored frontend libraries (`public/assets/vendor`, distributed with the app)

Fetched with `npm run vendor` (see `package.json`). Each library's license file is copied next to it.

| Library | Version | License |
|---|---|---|
| jQuery | 3.7.1 | MIT |
| Bootstrap | 5.3.8 | MIT |
| SweetAlert2 | 11.26.25 | MIT |
| Font Awesome Free | 6.7.2 | Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT. Attribution is kept in `fontawesome/LICENSE.txt`. |
| CodeMirror | 5.65.21 | MIT (SQL Lab editor: core, SQL mode, matchbrackets, show-hint, sql-hint) |
| Chart.js | 4.5.1 | MIT (dashboards, M9: `chart.umd.min.js`, unmodified) |

**Note on SweetAlert2:** releases since 11.4.9 contain code that changes behaviour only on pages served from Russian/Belarusian domains with a Russian-language UI. It has no effect on EduCloud Lab deployments under other domains. The issue is recorded here for transparency, and the library will be replaced if that condition ever applies.

## Development-only JavaScript tooling (`npm`, not distributed)

Used by the end-to-end and accessibility suite (`npm run e2e`). The browser is the locally installed Chrome; no browser binaries are downloaded.

| Package | Version | License |
|---|---|---|
| @playwright/test | 1.63.0 | Apache-2.0 |
| @axe-core/playwright | 4.13.0 | MPL-2.0 |
| axe-core | 4.13.0 | MPL-2.0 (unmodified; used only to test pages) |

## Planned components (to be verified when added)

None at the moment.

## Execution plane (Python, `worker/.venv`, not distributed with the web app)

| Component | Version | License |
|---|---|---|
| Python | 3.11 | PSF-2.0 |
| duckdb (PyPI) | 1.5.6 | MIT |
| pytest (dev only) | 9.1.1 | MIT |

## Sample datasets

All bundled sample datasets (`public/assets/datasets/retail/`) are **synthetic**, generated deterministically by `scripts/generate-retail-data.php`, and dedicated to the public domain under **CC0 1.0**. They contain no real personal data.

## Trademarks

Microsoft, Azure and Microsoft Fabric are trademarks of Microsoft Corporation. EduCloud Lab is an independent project. It is not affiliated with, endorsed by or certified by Microsoft. References to those products appear only in educational comparisons.
