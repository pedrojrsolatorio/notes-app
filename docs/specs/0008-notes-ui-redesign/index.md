# 0008. Notes UI redesign

**Date**: 2026-11-22
**Status**: Proposed

## Summary

Redesign how both notes surfaces look and flow: the logged in page at `/notes` and the installable offline app at `/app`. Apple Notes is the layout model (sidebar with smart lists, a calm hairline note list, a document style editor) and Craft is the polish model (typography, whitespace, refined detail). Every existing feature keeps working exactly as it does today: no database, API, sync, or authentication code changes are part of this decision. The build changes shared design tokens first, reworks the sidebar, list, editor, and toolbar on `/notes`, then mirrors the same look into `/app`.

## Requirements

**User stories**:
- As a note taker, I want an Apple Notes like layout so that scanning and writing feels calm and fast.
- As a user in either theme, I want warm, legible surfaces with clear text hierarchy so that long sessions stay comfortable.
- As a returning user, I want every existing flow (folders, search, sort, trash, quick capture, attachments, reminders, offline sync) to behave exactly as before so that the new look costs me nothing.
- As a keyboard or screen reader user, I want the accessibility guarantees from spec 0006 to keep holding after the redesign.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: Both surfaces show an Apple Notes style sidebar in the page area: smart lists "All Notes" and "Trash" on top, the flat folder list below them (no nesting), a clear active state with `aria-current`, and a collapse control (hidden below 1024 pixels so folders stay above the list) that turns the sidebar into an icon rail with icons and accessible names, persisted across reloads in local storage under the shared key `notes-sidebar-collapsed` on both surfaces.
- **AC-2**: The note list uses borderless rows separated by hairlines instead of cards. Each row shows the note title (semibold), a one line preview, and the updated date formatted `M j, Y` in the browser's local time zone from the existing ISO `updated_at`, right aligned from `sm` up and below the preview on smaller screens, shown on Trash rows too. The list still pages 50 rows at a time with the existing Load more control.
- **AC-3**: The editor is document style: the title is a separate input rendered as the first line of the document in large type (validation, save, and data flow unchanged), the visible "Title" label stays (small and muted above the input; a placeholder is never the label), inside one rounded surface with hairline border and generous padding, with the attachments section separated by a hairline divider.
- **AC-4**: The rich text toolbar is a slim unboxed row above the editor that wraps into more rows when needed, with no heavy box: groups of controls separated by subtle vertical dividers, active (pressed) states visible, and below 768 pixels the primary set is Bold, Italic, Underline, and Strike plus the More menu with every other control inside it, as spec 0006 AC-3 requires.
- **AC-5**: A new token layer drives both surfaces: the warm neutral palette replaces the cool values under the existing `zinc` key names in `@theme` so Flux and all hand rolled markup inherit one warm ramp, light and dark on `/notes`, `/app` uses the same tokens in its dark only shell (dark page `stone-900`, panels `stone-800`, muted text `stone-600` light and `stone-400` dark), rebuilt type scale on Instrument Sans (no new font), hairline borders, soft shadows, 12 pixel radius on surfaces and 8 pixel radius on controls, black primary buttons, and a soft blue accent (`blue-600` #2563eb light, `blue-400` #60a5fa dark) reserved for links, focus rings at 3:1 contrast or better in both themes, and text selection.
- **AC-6**: States are redesigned: empty states are typographic with a headline, one supporting line, and a primary action where one applies ("No notes yet" plus New note, "No matching notes" plus Clear search, Trash empty with headline and line only); shimmer skeleton rows appear only on `/app` while its first list load runs (the `/notes` list is server rendered and keeps its current rows during updates); search shows a result count line only while search is non-empty, computed as an exact total, reading "N notes", with a clear button that has `aria-label="Clear search"`, on both surfaces, and never inside a live region (the existing status already announces totals); saves and uploads keep the existing inline Saving and `wire:loading` text; existing error patterns (inline `@error`, toasts, offline banners) are kept and only restyled.
- **AC-7**: No functional regression: folder create/rename/delete, note create/edit/trash/restore/delete forever, search, all four sort orders, quick capture, attachments, reminders, offline sync and conflict handling, and both theme modes behave as they do today; the existing Pest suite passes with only intentional DOM assertion updates.
- **AC-8**: Accessibility parity with spec 0006: every one of its acceptance criteria still holds as it applies to the current app (see the Key invariants note on the stale AC-15 clause), including visible labels, toolbar names and pressed states, master and detail layout below 1024 pixels, 44 pixel primary targets, focus indicators, and live status regions, and the new surfaces (skeletons, hairlines, warm neutrals) keep WCAG 2.2 AA contrast in both themes with hairlines perceptible in dark mode.
- **AC-9**: `/app` parity: the offline app mirrors the redesigned sidebar, list rows, editor, toolbar, and states using the same token classes; `/app` stays dark only and keeps its native `prompt` and `confirm` dialogs (spec 0006 AC-15 requires them; parity means the look, not swapping dialogs); no Dexie, sync, or service worker logic changes are made, confirmed by diff review plus the test suite.
- **AC-10**: Responsive behavior is refined, not reinvented: folders visible from medium widths, list and editor swap as master and detail below 1024 pixels, the quick capture FAB stays on small screens, and search and sort stack on narrow widths.

## Decision

**Chosen option**: Option 2: Apple Notes inspired layout with a new design system (details in [rationale.md](rationale.md)).

Rework the layout and introduce a warm neutral token layer on both notes surfaces, keeping every behavior, flow, and accessibility guarantee unchanged (basis: the engineer's six pain points, which a pure reskin cannot fix, and the design brief naming Apple Notes as primary inspiration).

**Implementation skills**: `fluxui-development` (project skill, `.agents/skills/fluxui-development/`) · `tailwindcss-development` (project skill, `.agents/skills/tailwindcss-development/`) · `livewire-development` (project skill, `.agents/skills/livewire-development/`)

## Rationale

Reasoning and options: see [rationale.md](rationale.md).

## Feature design

**Design specification** (the concrete look `/develop` builds to):

*Tokens and theme* (`resources/css/app.css`):
- Neutrals: keep the `zinc` key names in `@theme` but replace their values with the warm gray (stone) equivalents, so Flux components and every hand rolled class inherit one warm ramp without touching each usage; auth and settings inherit automatically.
- Dark surfaces: page `stone-900`, panels and sidebar `stone-800`, muted text `stone-400` (light muted text `stone-600`), hairlines in the warm 200 step (dark 700 step) and confirmed perceptible.
- Accent: `blue-600` (#2563eb) light and `blue-400` (#60a5fa) dark for links, `:focus-visible` rings (3:1 contrast or better in both themes), and text selection only. Active navigation uses a warm gray fill, not the accent.
- Primary buttons stay solid black with white text in both themes (existing decision, kept).
- Radius: `rounded-xl` (12 pixel) for panels, cards, modals, and the editor surface; `rounded-lg` (8 pixel) for buttons, inputs, and rows.
- Borders: one pixel hairlines in the warm 200 step (dark: 700 step). Elevation: `shadow-sm` only, no heavy shadows.
- Type scale on the existing self hosted Instrument Sans: page heading `text-lg font-semibold` (down from `text-2xl font-bold`), sidebar group labels `text-xs font-medium` muted, list row title `text-sm font-semibold`, preview `text-sm` muted one line clamp, date `text-xs` muted, editor title input `text-3xl font-semibold`, ProseMirror headings tightened to 1.75 / 1.375 / 1.125 rem.
- Spacing: list rows `py-3 px-4`, editor surface padding `p-6 sm:p-8`, column gaps `gap-6`.

*Sidebar* (page level aside in `⚡index.blade.php`, mirrored in `offline-app.js` `mountShell()`):
- Order: "All Notes" and "Trash" as smart lists with icons, then the flat folder list. The layout level sidebar (app nav, account menu) stays as app chrome and only inherits new tokens; the notes sidebar and it are not merged.
- Active state: warm gray pill background plus `aria-current="page"`; hover is a lighter warm fill.
- Collapse control turns the aside into an icon rail (icons `document-text` for All Notes, `trash` for Trash, `folder` for folders, each with an accessible name and tooltip), state persisted in `localstorage` under `notes-sidebar-collapsed`, shared by both surfaces (same origin) and kept distinct from the Flux layout sidebar key. The collapse control is hidden below 1024 pixels so folders stay above the list on small screens.
- Folder row actions (rename, delete) keep the existing dropdown menus. On small screens the aside stacks above the list exactly as today.

*Note list*:
- Rows are borderless, split by one pixel hairlines, grouped in one rounded surface (or flat under the header; pick whichever reads cleaner during the build, both satisfy AC-2).
- Row content: title, one line preview (existing 120 character stripped preview), and the updated date formatted `M j, Y` in the browser's local time zone from the ISO `updated_at`, right aligned from `sm` up and below the preview on smaller screens, on normal and Trash rows alike.
- Search stays at the top of the list, restyled, with a clear button (`aria-label="Clear search"`, only while search is non-empty) and a result count line reading "N notes" that shows only while search is non-empty and is computed as an exact total (an extra count query on `/notes`, Dexie `.count()` on `/app`); the count line is plain text, never an `aria-live` region. Sort select keeps its four existing options, restyled.
- Reminder rows: a compact status chip shows the reminder state, and the actions (Set, Change, Clear, Retry) move into a row level dropdown, keyboard reachable with 44 pixel items. Trash rows keep inline Restore and Delete permanently on a second line. Sync state dots on `/app` keep their current behavior with new styling.

*Editor*:
- One rounded surface, hairline border: borderless large title input first, folder select below it, slim toolbar, then the Tiptap area with generous padding. The editor container keeps `wire:ignore` and `wire:key` exactly as today; no morph dependent markup goes inside it.
- Attachments section below a hairline divider; action row (Save, Delete, Move to Trash) unchanged in behavior.
- The Tiptap surface keeps its own styles in the `.ProseMirror` block, restyled to the new type scale and accent.

*Toolbar*:
- One slim row, no outer box beyond a hairline bottom border, groups separated by subtle vertical dividers: marks, lists, block type, size and color, highlight and link, alignment, table. Table and width dropdowns still appear only when a table is active. Below 768 pixels secondary controls move into the More menu (spec 0006 AC-3).

*States*:
- Empty: headline plus one line plus primary action where one applies: "No notes yet" / "Capture your first thought" / New note button; "No matching notes" plus a Clear search action; Trash empty is headline plus line only.
- Loading: skeleton rows (matching row height, `animate-pulse`, already neutralized by the reduced motion block) only on `/app` while its first list load runs; the `/notes` list is server rendered and keeps its current rows during updates, with no skeleton; the empty state appears only after a load completes with zero rows.
- Errors and notices: keep inline `@error`, `Flux::toast`, and the offline amber banners and status dot, restyled to the new tokens.

*Responsive*: current model kept and refined (master and detail below 1024 pixels, folders from medium widths, FAB on small screens, stacked search and sort).

*Icons*: standardize on the Flux (Heroicons style) icon set for all new or touched markup; remove reliance on the scattered hand drawn SVGs where a Flux icon exists. No new icon package.

**Data model sketch**: no change. No migration. The redesign reads fields the app already loads: `notes.title`, `notes.content`, `notes.updated_at`, `notes.folder_id`, folder names, and the existing sync state fields.

**API surface**: none. No endpoint, Livewire action, validation rule, or sync payload changes. The redesign is presentational.

**Value sourcing**:

| Action | Value produced / displayed | Source |
|---|---|---|
| Note row date | `M j, Y` in the browser's local time zone | `notes.updated_at` (existing ISO column, already loaded; Dexie `updated_at` on `/app`), formatted client side on both surfaces |
| Note row title and preview | title, first 120 stripped characters | existing `notes.title` and current preview logic (unchanged) |
| Search result count | exact number of matching notes labeled "N notes" | the same query that builds the visible list, plus its count (extra count query on `/notes`, Dexie `.count()` on `/app`); shown only while search is non-empty |
| Smart list labels | "All Notes", "Trash" | fixed UI strings decided in this spec |
| Active nav state | current folder, Trash, or All Notes | existing Livewire filter state / `/app` `uiState` |
| Sidebar collapsed state | rail or expanded | `localstorage` key `notes-sidebar-collapsed`, shared by both surfaces (decided in this spec) |
| Reminder chip state | reminder set or not | existing note reminder fields (unchanged) |
| Theme | light or dark | Flux appearance setting (`@fluxAppearance`) on `/notes`; hardcoded dark on `/app` |
| Accent color, radii, type sizes | token values | this spec's Design specification, implemented in `@theme` |

**Key invariants**:
- No backend, schema, API, sync, or auth code changes.
- Every note, folder, and attachment query stays scoped to `auth()->user()` (existing rule, untouched).
- All spec 0006 and spec 0004 acceptance criteria keep holding as they apply to the current app. One stale clause: 0006 AC-15 says the offline app "has no formatting toolbar", but the offline toolbar shipped in the 2026-11-22 visual alignment, so this spec preserves that existing functionality and Follow-up carries amending 0006 AC-15. `/app` keeps its native `prompt` and `confirm` dialogs as 0006 requires; parity means the look, not swapping dialogs.
- The title stays a separate field and input; it only looks like the document's first line.
- Primary buttons remain black; the accent never becomes a primary button color.
- Cursor paging (50 rows) and all performance guarantees from spec 0007 stay.

**Security model**: unchanged. No new surfaces, no new data exposure; the redesign touches presentation only.

**Configuration required**: none. No new environment variables or credentials.

**Critical test scenarios** (each maps to an acceptance criterion in ## Requirements):
- Happy path: open `/notes`, use the sidebar to switch folders, open a note, edit the title and body, save, and see the row update with its date in the `M j, Y` local format on both surfaces, verifies **AC-2**, **AC-3**, **AC-7**
- Validation: save a note with an empty title and the inline error still appears and the note stays unchanged, verifies **AC-6**, **AC-7**
- Accessibility: keyboard through the toolbar, confirm pressed states and the More menu below 768 pixels, verify master and detail below 1024 pixels, verifies **AC-4**, **AC-8**
- Themes: walk the main flows in light and dark and confirm contrast and legibility, verifies **AC-5**, **AC-8**
- Offline: run the same flows on `/app` online, then offline, confirm sync status and conflict handling still work with the new look, verifies **AC-9**
- Regression: run the Pest suite, verifies **AC-7**

## Build plan

Tracer Bullet order: each slice ships working end to end before the next starts; `/notes` lands first and `/app` follows in one parity slice to keep the divergence window short.

1. Token layer: warm neutrals, accent, radii, shadows, and the rebuilt type scale in `resources/css/app.css` and the touched Blade classes, satisfies **AC-5**
2. Sidebar rework on `/notes`: smart lists, flat folder list, active states, collapse to icon rail, satisfies **AC-1**
3. Note list and search: hairline rows with title, preview, date, result count, clear button, restyled sort, satisfies **AC-2**, **AC-6**
4. Editor and toolbar: document style surface, title as first line, slim grouped toolbar with More menu preserved, satisfies **AC-3**, **AC-4**
5. States pass: empty states with primary actions where they apply, skeleton first load on `/app`, restyled errors and notices, satisfies **AC-6**
6. Responsive and accessibility sweep against spec 0006 in both themes, satisfies **AC-8**, **AC-10**
7. `/app` parity: mirror tokens, sidebar, list, editor, toolbar, and states in `resources/views/pwa/notes.blade.php` and `resources/js/offline-app.js`, dark only, satisfies **AC-5**, **AC-9**
8. Full regression: Pest suite plus the browser pass on both surfaces, satisfies **AC-7**

## Consequences

**Positive**:
- The list becomes calm and scannable (hairlines instead of cards), hierarchy reads clearly, and the toolbar stops competing with the text, which addresses all six stated pain points.
- One token layer keeps `/notes` and `/app` visually locked together and makes future UI work cheaper.
- No backend risk: nothing about data, sync, or auth can break from this work.

**Negative / tradeoffs**:
- Two UI codebases (the Blade component and the hand built DOM in `offline-app.js`) must be changed in step; the parity slice is large and the surfaces can drift until slice 7 lands. The shared token layer is the main mitigation, with verify steps 14 to 16 checking markup parity by hand.
- The notes component is a single 1953 line Blade file plus a 2745 line JS file; a wide visual diff raises regression risk, so assertions and manual checks must run per slice, not only at the end.
- Auth and settings pages are out of scope but inherit the new tokens, so they will look slightly mismatched until a follow up pass.
- The new date in each row is new visual information; if the format reads wrong on old notes it will be noticed immediately.

**Neutral**:
- Some existing tests assert classes or text that the redesign changes; those assertions are updated deliberately, never weakened.
- `/app` stays dark only, so light theme work does not reach it.
- Screenshots and any docs showing the old look go stale.

## Migration plan

**Strategy**: incremental slices, no data migration.
**Phases**:
1. Tokens land first and restyle everything passively; the app stays fully usable after every slice.
2. `/notes` areas are reworked one by one (sidebar, list, editor, states), each verified in the browser before the next.
3. `/app` parity follows, then the full regression pass.
**Rollback**: revert the commits of the failing slice; no schema or API change means no data to roll back.
**Risks**: visual regressions against spec 0006, `/app` drift if the parity slice slips, and stale test assertions masking a real break.

## Follow-up

- [ ] Amend spec 0006 AC-15: its "no formatting toolbar" clause is stale (the offline toolbar shipped in the 2026-11-22 visual alignment); update it to match the current app so the two specs agree.
- [ ] Enroll a scope feature row (for example feature 12: notes UI redesign) linking this spec so its status tracks the build lifecycle.
- [ ] Consider noting `fluxui-development` and `tailwindcss-development` in root `AGENTS.md` so every UI task loads their conventions.
- [ ] Optional follow on pass: restyle auth and settings pages to the new tokens (explicitly out of scope here).
- [ ] Nested folders remain a separate backend decision (schema, sync, validation); the sidebar stays flat until a future spec approves nesting.
