# ADR-012: Project license

- Status: **Superseded on 2026-10-08**: Apache-2.0, public repository. Previously accepted 2026-10-06 as proprietary.

## Context
The 2026-10-06 decision kept the project private and proprietary ("all rights reserved"), with an explicit note to revisit this ADR before any publication. On 2026-10-08 the author decided to publish the repository publicly on GitHub (`github.com/gustabin/educloudlab`).

## Options
- MIT: permissive and minimal.
- Apache-2.0: permissive, with an explicit patent grant and NOTICE handling.
- AGPL-3.0: strong copyleft, including network use.
- Keep proprietary, which would make the code source-available but not reusable.

## Decision
**Apache License 2.0** (user decision, 2026-10-08). This was also the master plan's original recommendation (§26).
- `LICENSE` holds the Apache-2.0 text.
- `NOTICE` holds the copyright, the non-affiliation disclaimer and a pointer to third-party notices.
- `composer.json` and `package.json` declare `"license": "Apache-2.0"`. `package.json` stays `private: true`, because it is development tooling and is not published to npm.

## Consequences
- **Reuse:** anyone may use, modify and redistribute the code, including commercially, provided they keep the license and NOTICE and state their changes.
- **Patents:** contributors grant a patent license, and it terminates for anyone who files a patent suit.
- **Dependencies:** every third-party dependency is compatible (`THIRD_PARTY_NOTICES.md`): MIT, BSD, Apache-2.0, PSF, and CC BY/OFL for the icons and fonts. PHPMailer (LGPL-2.1) is used unmodified through Composer and keeps its notice. nette/* is used under its BSD-3-Clause option.
- **Contributions:** they are accepted under Apache-2.0, inbound equals outbound (`CONTRIBUTING.md`).
- **Branding:** the "not affiliated with Microsoft" disclaimer stays in the UI footer, the README and NOTICE.
- **Commit authorship:** history uses the author's GitHub noreply address.
