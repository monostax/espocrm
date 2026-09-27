# Monostax UI colors

CRM uses the same semantic palette as Chatwoot in light and dark modes.

- `frontend/less/monostax/palette.less` mirrors Chatwoot's
  `app/javascript/dashboard/assets/scss/_next-colors.scss` and `theme/colors.js`.
- `frontend/less/monostax/variables.less` maps native Espo theme variables to that
  palette. It is imported after the individual theme variables, including iframe
  styles. Dark and Glass use the dark palette; the other themes use the light
  palette.
- The palette also exports CSS custom properties such as `--n-background` and
  `--n-slate-12`. `client/css/monostax-theme.css` exposes these to Tailwind as
  `bg-n-background`, `text-n-slate-12`, `border-n-weak`, etc.

| UI role | Chatwoot / Monostax token |
| --- | --- |
| Page, navbar, inverse navbar | `n-background` |
| Panels and tables | `n-surface-1` |
| Panel headings | `n-surface-2` |
| Inputs and modal content | `n-solid-1` |
| Primary text / secondary text | `n-slate-12` / `n-slate-11` |
| Dividers / input borders | `n-weak` / `n-strong` |
| Primary actions and focus | `n-brand` (`#191919` light / `#fafafa` dark) |
| Primary action hover | `n-brand-hover` (`#333333` light / `#e4e4e7` dark) |
| Text on primary actions | `n-brand-foreground` (white light / near-black dark) |
| Success / danger / warning / info | Teal / ruby / amber / iris |

Use the semantic variables for new styles rather than hardcoded colors. Color
mode follows CRM's selected theme, so Tailwind components do not need separate
`dark:` color overrides. When updating the palette, compare both modes with the
Chatwoot source; the CRM build remains independent of the Chatwoot checkout.

Build native themes with `npx grunt less-only`. Build Tailwind utilities with
`npm run tailwind:minify` after adding utility classes to templates.
