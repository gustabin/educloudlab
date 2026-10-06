---
name: design
description: EduCloud Lab UX/UI workflow and design system. Use before designing, building or reviewing any page, screen, component, empty/loading/error state, or CSS in this project (public site, student portal, instructor and admin screens).
---

# EduCloud Design

## Purpose
Keep every screen consistent, accessible (WCAG 2.1 AA target) and original. The platform is inspired by cloud consoles in concept only. Never copy Microsoft UI, icons or branding.

## When to use / not use
- Use for new screens, components, layout changes, design tokens and UX reviews.
- Do not use for backend-only work (use `backend-feature`) or lab content (use `lab-authoring`).

## Required inputs
The screen name, its user role(s), the primary task, and the API endpoints the screen consumes.

## Workflow
1. **Discovery:** confirm role, task and data in the master plan §19.
2. **IA:** place the screen in the navigation (sidebar section, breadcrumb path).
3. **Screen spec:** write `docs/ux/screens/<screen>.md` using the template below *before* building.
4. **Build:** PHP view in the module's `views/` using the layout and partials. Behaviour goes in `public/assets/js/features/<feature>.js`.
5. **Accessibility review:** use the checklist below. Use the `browser-automation` skill or Playwright + axe for console errors and a11y.
6. **UX validation:** walk through the loading, empty, error and success states.

## Screen spec template
```
# <Screen>
Role(s): | Primary task: | Route: | Auth/permission:
Data required (endpoints): | Actions (button → endpoint → feedback):
Loading state: | Empty state (message + primary action): | Error states (401/403/404/422/429/500/network):
Acceptance criteria (Given/When/Then):
```

## Design tokens (`public/assets/css/tokens.css`)
- Colors: `--ec-primary` (teal #0f766e), `--ec-accent` (indigo #4338ca), `--ec-success`, `--ec-warning`, `--ec-danger`, `--ec-info`, `--ec-surface`, `--ec-surface-2`, `--ec-border`, `--ec-text`, `--ec-text-muted`. Define light values, then dark values under `[data-bs-theme="dark"]`. Every text/background pair must reach a contrast ratio of at least 4.5:1.
- Spacing: `--ec-space-1..8` (4px scale). Radius: `--ec-radius-sm/md/lg` (4/8/12px).
- Typography: system font stack. Base 16px. Scale 12/14/16/20/24/32. Code font `ui-monospace, Consolas, monospace`.
- Map tokens onto Bootstrap CSS variables (`--bs-primary` etc.) rather than overriding component CSS.

## Component standards
- **Shell:** collapsible left sidebar (sections: Inicio, Workspaces, Datos, SQL, Labs, Cursos, Admin), a top bar with the tenant switcher and user menu, and breadcrumbs under the top bar.
- **Cards** for resources (icon, name, type badge, status badge, kebab actions). **Tables** have sticky headers, server-side pagination, and sortable columns from an allowlist.
- **Status badges:** a fixed map. provisioning=info, active=success, failed=danger, deleting=warning, deleted=secondary, queued/running=info + spinner.
- **Forms:** every input has a `<label>`. Errors appear inline (`invalid-feedback`) mapped from the 422 `details`. The submit button disables while a request is in flight.
- **Feedback:** SweetAlert2 for confirmations (destructive actions require typing the resource name) and blocking errors. Toasts for non-blocking success.
- **Loading:** skeleton placeholders for lists. A spinner and `aria-busy` for buttons. **Empty:** an illustration-free message, a short explanation and one primary action. **Error:** human message, request_id, and a retry when the error is retryable.

## Accessibility checklist
Semantic landmarks (`header/nav/main`), one `h1` per page, no skipped heading levels, visible `:focus-visible`, every action reachable by keyboard, modals trap focus and return it, async results announced in an `aria-live="polite"` region, a skip link, `lang="es"`, and no information conveyed by colour alone.

## Security in UI
Never insert API data with `.html()`. Use `.text()` or build elements. No inline scripts or `on*` attributes (CSP `script-src 'self'`). Escape server output with `e()`.

## Review criteria (pass/fail)
- The spec exists.
- All states are implemented.
- Tokens are used (no hardcoded colors).
- Contrast is OK.
- The keyboard walkthrough works.
- No console errors.
- No CSP violations.
- Responsive at 360px, 768px and 1280px.
