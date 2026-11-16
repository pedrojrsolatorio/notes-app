# Rationale: Accessibility and responsive use

## Context

The notes app has two notes surfaces. The authenticated Livewire page at `/notes` is the main browser experience, and the public `/app` shell with `resources/js/offline-app.js` is the installed offline experience. Both are real, both are in use, and both must be usable by the same people.

The current code has a set of concrete barriers. The note list uses a click handler on an `<article>`, so a keyboard user cannot select a note. Search, sort, title, and folder fields have no visible labels. The editor toolbar uses letters, symbols, and `title` attributes, and its toggle buttons do not expose pressed state. The authenticated layout wraps content in `flux:main`, while the notes view adds a second `<main>`. There is no skip link. The offline status and notices are plain text with no live region. `renderAll()` replaces the whole offline DOM, so focus is lost after many actions. Touch targets vary, the toolbar does not wrap, table resizing is pointer drag only, and the single key `N` opens quick capture from anywhere on the page.

The forces are practical. The project uses Flux free, Livewire 4, Tiptap 3, and Tailwind 4. The offline client is separate JavaScript and does not use Livewire directives. The project has Pest feature tests, no browser test runner, and a rule against new dependencies without approval. Both layouts hardcode the dark theme today, so there is no light theme to test. The scope has feature 10 planned for Slice 6, and the build approach is Tracer Bullet, so the work should land as thin end to end slices rather than one large rewrite.

The consequence of not deciding is that the two surfaces keep drifting. A fix on the Livewire page would not reach the offline app, a later UI change could remove labels or focus moves, and the team would have no written contract or regression check. WCAG 2.2 AA is the chosen bar because it is a known standard that covers keyboard use, names, state, contrast, focus, and touch. The 44 pixel target is stricter than AA for primary and mobile controls, and the toolbar uses the AA target size with spacing plus a More menu at narrow widths.

## Options considered

### Option 1: Fix the current surfaces in place

Keep the Livewire page and the offline app as they are, and make targeted changes to each one. Add labels, names, pressed state, landmarks, a skip link, focus moves, live regions, responsive views, and touch target rules. Share the behavior through the spec and the test contract, not through a new component layer.

**Pros**:
- Smallest correct change. It keeps the current routes, Flux components, Livewire actions, and offline client.
- Ships in thin slices, which fits the Tracer Bullet approach.
- No new dependency, schema, endpoint, or auth rule.
- The existing test suite and the current manual flows stay useful.
- The team can verify each surface on its own.

**Cons**:
- Two implementations must stay in step.
- Some Flux and Tiptap internals may need overrides that a package upgrade could break.
- The shared behavior is enforced by tests and review, not by one code path.

### Option 2: Rebuild both surfaces on a shared accessible component layer

Create a shared component layer for note lists, fields, toolbars, status, and dialogs, then render it from both the Livewire page and the offline client.

**Pros**:
- One implementation for labels, focus, status, and responsive behavior.
- Less chance that the two surfaces drift.

**Cons**:
- This is a large rewrite of two working clients.
- The offline client is plain JavaScript and does not use Livewire, so a shared layer would need a new rendering boundary or a lot of glue.
- It puts note editing, search, folders, trash, reminders, and attachments at risk in one change.
- It is much larger than the accessibility problem requires.

### Option 3: Add a headless UI library or browser accessibility test dependency

Replace parts of the interface with a headless component library, or add a browser test runner and an accessibility checker.

**Pros**:
- A library can supply proven dialog, menu, and focus behavior.
- A browser checker can catch some regressions automatically.

**Cons**:
- It adds dependencies without approval and does not fix the current markup by itself.
- The project already has Flux for the Livewire page, so a second component system adds conflict.
- Browser tooling is useful, but the project has no runner today and the manual matrix is still needed for screen readers.
- The cost is high for a small notes app.

### Option 4: Audit and document the gaps only

Write an accessibility report and leave the fixes to later work.

**Pros**:
- Very cheap and fast.
- Gives the team a list to work from.

**Cons**:
- No user gets a better experience.
- The gaps stay in production and can grow.
- There is no build contract or regression check.

## Rationale

Option 1 is the right fit because the existing architecture is sound and the problem is a set of missing behaviors, not a broken foundation. The Livewire page already uses Flux fields, labels, errors, and modals in the folder and reminder forms. The offline client already has a clear render path and a per user store. Targeted changes can reach WCAG 2.2 AA without replacing either client or adding a dependency.

The main cost is two implementations. That cost is real, so the spec makes the behavior explicit and gives it one set of acceptance criteria, one build plan, and one Pest contract. The manual matrix covers what DOM tests cannot, namely screen reader output, touch use, forced colors, and a cold offline reload. The runner up was the shared component layer. It would reduce duplication, but it would also turn an accessibility slice into a rewrite of two clients, which is the wrong trade for this stage of the app.

The chosen design also keeps the data and security model still. There is no schema change, no new endpoint, and no auth change. The only stored UI value is the table width, and it uses the `colwidth` attribute that Tiptap already writes. Status and validation messages are text only, and they must never carry note content. That keeps the privacy boundary from the offline sync spec intact.

A cross check found several gaps that the first draft left to the builder. The shortcut changed to `Alt+Shift+N` because `Ctrl+Shift+N` and `Cmd+Shift+N` are reserved by Chrome, Edge, Firefox, and Safari for a new incognito window. The page may never receive the key, so the original choice could not be verified.

The table width menu now uses per cell `colwidth` as the only source of truth. Narrow, medium, and wide come from a fixed 720 pixel design width, not the current pane width, so a phone edit does not shrink a desktop note. The menu shows a preset only when the total width is within 40 pixels, and reset clears `colwidth`. The offline editor preserves existing widths and disables resize, so an offline save does not erase a table width.

The layout is three columns at 1024 pixels and up: folders, list, and editor. Below 1024 the list and editor are separate views, folders sit above the list, and the back control returns focus to the selected note row or the list heading. Status is split by surface because `/notes` has no sync runtime and no outbox. `/notes` shows online, offline, save, upload, and validation state. `/app` keeps the full sync status, last sync, and pending count.

Focus restoration is a first class rule, not a set of one off selectors. Both surfaces use stable focus keys, and the offline app keeps its editor and status bar mounted instead of rebuilding the whole shell. This preserves focus, cursor, undo history, and scroll position. Pest checks cover the Livewire page only because the offline DOM is built in the browser; the manual matrix covers the offline app. The contrast claim covers the dark theme and forced colors because a light theme does not exist yet. A light theme is a follow up.

The sign in flow gains a return path to `/app`, and the service worker precache must cover the shell build assets so a cold offline reload after a build still works. Server logs use the exception class and a correlation id, never the note payload, and offline `last_error` stores a safe message only.

