# ADR-012: Project license

- Status: Accepted (2026-10-06, user decision)

## Context
The project will not be published publicly on GitHub, at least for now.

## Options
- MIT: permissive and minimal.
- Apache-2.0: permissive, with an explicit patent grant and NOTICE handling.
- Proprietary (all rights reserved).

## Decision
**Proprietary, all rights reserved.** `composer.json` declares `"license": "proprietary"`. The project ships no open-source LICENSE file.

## Consequences
- Nobody may reuse the code without the author's permission.
- The project can be relicensed later under MIT or Apache-2.0; going from closed to open is easy, while going from open to closed is not. Every third-party dependency (see THIRD_PARTY_NOTICES.md) is compatible with proprietary distribution. PHPMailer's LGPL-2.1 terms still apply: use it unmodified and keep its notice.
- If the repository is ever published, this ADR must be revisited first.
