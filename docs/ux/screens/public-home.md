# Public home (`/`)

| | |
|---|---|
| **Role(s)** | Anonymous visitors (prospective students, teachers, contributors). Signed-in users see the same page with the dashboard link in the header. |
| **Primary task** | Understand in under a minute what EduCloud Lab teaches and why it is worth trying, then start (`/register`) or browse the labs (`/labs`). |
| **Route** | `/` (public, indexable). Data: published labs from `PublicRepository::labs()`, server-rendered. No API calls and no JavaScript. |

## Content (top to bottom)

1. **Hero:**
   - h1, lead, and two CTAs (`/register`, `/labs`);
   - a trust strip (free, Apache-2.0, auto-grading, synthetic data);
   - a decorative SQL Lab mockup (HTML/CSS, `aria-hidden`, with a visually hidden description).
2. **Numbers**, computed from the published catalog:
   - labs;
   - graded exercises (the sum of tasks);
   - hours of guided practice (the sum of estimated minutes).

   When the catalog is empty, the band is omitted.
3. **Data flow:** files → RAW → BRONZE → SILVER → GOLD → semantic model → dashboard. It is vertical below `lg` and one row from `lg` up, so the arrows always join consecutive steps.
4. **Capabilities:** 9 cards, each with an icon, a title, a one-line benefit and keyword chips.
5. **Learning path:** published labs in code order, linking to `/labs/{slug}`, with difficulty and minutes. The last lab is marked as the capstone. One line below explains how grading works.
6. **Audiences:** students, teachers, and open source (Apache-2.0, self-hosting, GitHub).
7. **Secure by design**, 4 items.
8. **FAQ:** 4 native `<details>` questions.
9. **Closing CTA.**

UX review (2026-10-09), changes applied:
- **Copy:** less repetition (no separate grading section, no 4th stat, two chips per card).
- **Tokens:** `--ec-on-primary` keeps text on primary and accent at AA in both themes, and `--ec-shadow-lg` replaces a hardcoded shadow.
- **Flow:** its own classes (`.ec-flow-*`), which no longer clash with the lakehouse badge classes.
- **Focus:** the project focus ring also on Bootstrap buttons and nav links.

## States

- **Loading:** none (server-rendered).
- **Empty catalog:** the numbers band is omitted, and the learning path is replaced by a link to `/labs`.
- **Errors:** the regular error pages; the page itself has no failing async parts.

## Acceptance criteria

- **Given** 10 published labs, **when** a visitor opens `/`, **then**:
  - the numbers match the catalog (labs, exercises, hours);
  - each lab links to its public page;
  - draft or retired labs never appear.
- Exactly one h1, with no skipped heading levels. Each section is labelled by its heading.
- axe (WCAG 2.1 A/AA) reports no serious or critical violations, and there are no console errors.
- No horizontal scroll at 360 px. The layout works at 768 and 1280 px.
- Only design tokens are used for colour. The mockup's code colours are tokens with AA contrast in light and dark themes.
- Claims are truthful:
  - no affiliation with Microsoft (footer disclaimer kept);
  - "free" refers to the software and the hosted labs, not to infrastructure costs for self-hosting.
