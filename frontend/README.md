# PaxofiCloud frontend — design system v1 and portal prototype

React + TypeScript + Vite. This is the **design system v1** and a clickable
prototype of three customer-portal screens: **sign-in (with two-step
verification)**, **dashboard** and **domain search**. It uses sample data
only; it does not call the API and nothing leaves the browser.

```bash
cd frontend
npm ci
npm run dev       # http://127.0.0.1:5173
npm run verify    # tokens in sync, lint, typecheck, tests, production build
```

## Design tokens (Figma handoff)

`src/design/tokens.json` is the **single source of truth**, in the
[W3C Design Tokens](https://design-tokens.github.io/community-group/format/)
format. A designer can import it into Figma with the Tokens Studio plugin
and rebuild every screen without re-deciding any colour, size or spacing.

- `npm run tokens` generates `src/design/tokens.css` (CSS custom properties
  named `--pc-<group>-<name>`). Never edit the CSS by hand; CI fails if it
  drifts from the JSON.
- **Brand colours (PKDMS):** Navy `#0B1F3A`, Blue `#2F6BFF`, Teal `#00BFA6`;
  typeface Inter (self-hosted, no third-party font requests).
- **Two layers:** `color.*` are raw palette values; `semantic.*` say what a
  colour is *for* (text, surface, action, feedback, nav). Components use only
  semantic tokens, so a re-theme changes the JSON, not the components.
- **Accessibility is tested, not hoped for:** `tokens.test.ts` checks every
  text/background pair for WCAG 2.2 AA contrast (4.5:1 text, 3:1 UI). This is
  why buttons use `blue.600` (`#1F56E0`) rather than brand blue: white on
  `#2F6BFF` is below 4.5:1. Brand blue stays the focus-ring and accent colour,
  and teal is decorative only.

## Components

| Component | File | Notes |
|---|---|---|
| Button | `components/Button.tsx` | `primary`, `secondary`, `ghost`, `danger`; `md`/`sm`; loading state; 44 px touch target |
| TextField | `components/TextField.tsx` | Label always visible; hint and error wired with `aria-describedby`; `aria-invalid` on error |
| Card | `components/Card.tsx` | Optional title and header actions |
| Badge | `components/Badge.tsx` | Tones: neutral, success, warning, danger, info, accent |
| Alert | `components/Alert.tsx` | `role="alert"` for errors, `role="status"` otherwise |
| AppShell | `components/AppShell.tsx` | Sidebar nav, top bar, skip link, `aria-current` on the active page |
| Logo | `components/Logo.tsx` | Mark + wordmark, light and inverse |

## Rules this code enforces

- **Money is integer minor units** (`lib/money.ts`): kobo/cents only, never
  floats, formatted without floating-point division.
- **No raw HTML:** `dangerouslySetInnerHTML`, `eval` and `new Function` fail
  lint (identity threat model T-19).
- **Sign-in behaviour matches the threat model:** one generic error for wrong
  email or password, a separate 6-digit TOTP step, and `autocomplete` hints
  for password managers.
- **Frontends talk only to the PCF API** (company rule). The prototype has no
  network calls; real data arrives in Sprint 1 (identity) and Sprint 2
  (catalogue/cart).

## Not in v1

Dark mode, the admin console, the public website (Next.js, Sprint 10), and
real API integration. The production Content-Security-Policy is set by the
server, not in `index.html`.
