# 0008. Notes UI redesign — rationale

## Context

The notes app works and its features are complete enough that the remaining complaint is how it looks and feels. The engineer named six pain points: the note list feels card heavy and dense, typographic hierarchy is weak, the toolbar feels cluttered, the colors feel bland, spacing feels cramped, and the empty, loading, and error states feel unfinished. The goal is a product that feels like a professionally designed personal note tool, with Apple Notes as the primary layout reference (sidebar, folder navigation, note list, search, editing workflow) and Craft as the secondary reference for typography, whitespace, and rich text polish.

Forces at play: this is a redesign of a live application, not a green light for a rewrite. Every feature must survive it: authentication and Google sign in, folders, search, sort, trash, quick capture, attachments, reminders, offline sync with conflict handling, and both themes. Two prior specs already constrain the work: spec 0006 guarantees accessibility and responsive behavior on both surfaces, and spec 0004 guarantees the offline app's behavior. The frontend is split across a large Livewire single file component and a hand built DOM layer in the offline client, so any visual change must be made twice to stay in sync. The project also runs a rule against new dependencies without approval, and the engineer chose to keep the existing self hosted Instrument Sans.

Both inspiration sites (apple.com/notes and craft.do) returned HTTP 403 from this environment, and web search was unavailable, so the design direction is drawn from prior knowledge of both products and cited by name rather than by fetched pages. The current code base was mapped directly instead (see evidence below).

Not deciding would leave the app functional but visually tired, with the six pain points persisting and the two surfaces slowly drifting apart after the recent alignment pass.

## Options considered

### Option 1: Reskin only

Keep the current layout (folders aside, card list, bordered panel editor, boxed toolbar) and change only tokens: warm colors, new type scale, softer borders and radii.

**Pros**:
- Smallest diff, lowest regression risk, fastest to ship.
- Addresses blandness and cramped spacing immediately.

**Cons**:
- Does not fix the stated structural pain points: cards stay card heavy, the toolbar stays a box, hierarchy stays whatever the markup allows.
- The engineer explicitly chose "visual design plus layout rework", so this underdelivers on the brief.

### Option 2: Apple Notes inspired layout with a new design system (chosen)

Rework the layout on the Apple Notes model (smart list sidebar, hairline note list with date, document style editor with the title as the first line, slim toolbar) and introduce a warm neutral token layer with Craft style polish, mirrored into `/app`. Behaviors, flows, and accessibility guarantees stay untouched.

**Pros**:
- Directly attacks every one of the six pain points, including the structural ones.
- The Apple Notes model is proven for exactly this job (scan, find, write) and maps cleanly onto the existing three area grid, so the rework is a transformation of what exists, not a new architecture.
- Token layer plus mirrored parity slice keeps the two surfaces locked together.

**Cons**:
- Larger diff across two large files, so regression risk is real and must be managed slice by slice.
- Requires the parity slice for `/app` or the surfaces drift again.

### Option 3: Full Craft style rework including interaction patterns

Go further: floating selection toolbar, larger radii and floating cards, rethink navigation and interaction patterns (for example a command palette, different trash model).

**Pros**:
- Most distinctive, most "designed" result.
- Floating toolbar keeps the writing surface maximally clean.

**Cons**:
- Interaction rework risks breaking spec 0006 guarantees and the engineer's red line on preserving behavior.
- Adds features the brief explicitly warns against adding just because the inspiration sites have them.
- Highest regression surface for the least extra visual gain.

## Rationale

Option 2 is the only choice that matches the engineer's own answers: they picked layout rework over a pure reskin and named all six pain points, several of which are structural (card density, toolbar box, weak hierarchy) and cannot be fixed by color alone. The Apple Notes layout was chosen as the primary model because it solves the same core job (capture, organize, find, write) with a layout that already rhymes with the app's three area grid, so the redesign transforms existing structure instead of inventing a new one. Craft is deliberately kept as the polish layer only (type scale, whitespace, detail), because its interaction ideas (floating toolbars, richer chrome) would push into behavior changes the brief forbids without separate approval. Warm neutrals over cool zinc and a restrained blue accent address the "bland" complaint while black primary buttons preserve an existing product decision, and keeping Instrument Sans honors the no new dependencies rule. Flat folders were confirmed with the engineer so the sidebar rework stays presentational and does not drag schema, sync, and validation into a UI decision.

## References

**Project sources** (verifiable, in this repo):
- `AGENTS.md`: stack, conventions, authorization scoping rules, and the frontend constraints this redesign must respect.
- `docs/specs/0006-accessibility-and-responsive-use/index.md`: accessibility and responsive guarantees the redesign must preserve.
- `docs/specs/0004-offline-use-and-device-sync/index.md`: offline app behavior and parity constraints.
- `docs/scope/scope.md`: build approach (Tracer Bullet) used to order the build plan.
- Current implementation: `resources/views/components/notes/⚡index.blade.php`, `resources/js/offline-app.js`, `resources/views/pwa/notes.blade.php`, `resources/css/app.css` (mapped during this design run, see evidence below).

**Practices & standards**:
- Apple Notes layout model (sidebar with smart lists, hairline note list, document style editor): named by the engineer as the primary inspiration; cited by name only because the page returned HTTP 403 from this environment.
- Craft visual polish model (typography, whitespace, refined rich text detail): named by the engineer as secondary inspiration; cited by name only for the same reason.
- WCAG 2.2 AA contrast and target size (carried from spec 0006).
- Tracer Bullet incremental delivery (project build approach from `docs/scope/scope.md`).

## Evidence: current UI map (from the code scout, 2026-11-22)

- `/notes` (`resources/views/components/notes/⚡index.blade.php`, 1953 lines): page grid is folders aside (`md:grid-cols-4`) plus a `lg:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)]` list and editor split; master and detail swap below 1024 pixels via Alpine `mobileView`. Folder rows, note rows (bordered cards with 120 character previews), search and sort, and the toolbar are hand rolled Tailwind markup; 61 Flux component usages cover buttons, dropdowns, modals, fields, and toasts. Toolbar: bordered box, `flex-wrap`, everything except four mark buttons hidden below `md` into a "More" dropdown.
- `/app` (`resources/views/pwa/notes.blade.php` + `resources/js/offline-app.js`, 2745 lines): standalone shell, `<html class="dark">` hardcoded, DOM built in `mountShell()`, mirrors `/notes` structure and classes; status bar with colored dot, sign in card, amber notices, blue update banner; no Flux at all.
- Tokens (`resources/css/app.css`, 222 lines): Tailwind v4 `@theme` with Instrument Sans, explicit zinc ramp hex values, `--color-accent` neutral 800 (inverted under `.dark`), default radii (`--radius-lg: .5rem`), class based dark variant, large `.ProseMirror` block, focus visible outline, reduced motion and forced colors blocks.
- Auth and settings: heavily Flux, centered card (auth) and navlist (settings); they share the zinc language but are out of scope here.
- Icons: Flux built ins (Heroicons lineage), four local Lucide overrides, hand drawn alignment SVGs in the toolbar.
- No `design.md` or styleguide exists; spec 0006 is the closest related document.
