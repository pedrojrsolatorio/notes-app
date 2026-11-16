# 0006. Accessibility and responsive use

**Date**: 2026-11-15
**Status**: Proposed

## Summary

Make both notes surfaces usable with a keyboard, a screen reader, and a small touch screen. The authenticated notes page and the installed offline app get visible labels, one main landmark, a skip link, deliberate focus moves, live status messages, 44 pixel targets for primary and mobile controls, and a master and detail layout below 1024 pixels. The work keeps the current Flux components and the separate offline client, adds no dependency, and meets WCAG 2.2 AA in the available dark theme and forced colors.

## Requirements

**User stories**:
- As someone who uses a keyboard, I want to reach and operate every note action without a mouse so that I can write and organize notes at speed.
- As someone who uses a screen reader, I want clear names, roles, state, and status so that I know what each control does and what changed.
- As someone on a phone or small screen, I want the list and editor to fit the screen and the controls to be easy to tap so that I can use the app comfortably.

**Acceptance criteria** (the contract, each criterion is IDed and independently checkable):
- **AC-1**: Every note in the list is keyboard operable. Each note row has a focusable primary control, Enter or Space selects the note, and the reminder, restore, and delete controls remain reachable without triggering selection. The focused control has a visible focus indicator.
- **AC-2**: Search, sort, title, folder, and quick capture fields have visible labels and programmatic names. Icon only controls have accessible names. Toggle controls expose their pressed state. Folder action controls name the folder they act on.
- **AC-3**: The editor toolbar is a labeled group. Every control has an accessible name and exposes pressed state where it toggles. The toolbar wraps into rows at narrow widths, and below 768 pixels the secondary controls move into a More menu so every control stays reachable by keyboard. The editor has a short visible hint and a static screen reader description that names the toolbar. Pressed state carries the current toggle state.
- **AC-4**: Each notes surface has one main landmark and a skip link. The skip link is the first focusable control and moves focus to the notes main content. Heading order is logical, and no page renders a second main landmark.
- **AC-5**: Below 1024 pixels, the note list and editor are separate views. The list view shows folders above the note list. The editor view has a back control that returns to the list and focuses the selected note row, or the list heading when no note is selected. The page does not scroll horizontally at 320 pixels; tables, code blocks, and long strings wrap or scroll inside the editor.
- **AC-6**: At 1024 pixels and up, folders, note list, and editor appear in three columns. Changing the viewport across the breakpoint preserves the selected note and the focused control. The breakpoint is `min-width: 1024px`.
- **AC-7**: Primary buttons, note actions, folder controls, status controls, and modal controls meet a 44 by 44 pixel touch target with spacing. Editor toolbar controls meet at least 24 by 24 pixels with spacing and stay reachable through the toolbar or the More menu. The toolbar wraps instead of overflowing.
- **AC-8**: Focus moves deliberately after actions and uses stable focus keys. Save keeps focus in the editor, unless validation fails. Delete moves focus to the next note, else the previous note, else the list heading. Restore keeps focus on the restored note control, or the Trash heading when the note leaves the view. A folder change moves focus to the note folder select, and a deleted folder returns focus to that select. Quick capture moves focus to its text area and returns focus to the opener, else the header button, else the list heading. Focus restoration runs after the Livewire morph or the offline render.
- **AC-9**: On `/notes`, each event is announced once and not conveyed by color alone: save and upload success use the toast, validation uses its alert, and online or offline status uses the persistent status region. On `/app`, the status text covers online, offline, syncing, needs sign in, error, last sync, and pending count, and the persistent status region is the only announcer for connection and sync status. Each surface updates its persistent status region in place and never announces the same event twice.
- **AC-10**: Validation errors appear inline, use `role="alert"`, are linked with `aria-describedby`, and move focus to the first invalid field. Success uses one announcer: a toast on `/notes` and a live status on `/app`. This covers title, folder, attachment, quick capture, and reminder errors. The attachment error stays visible until corrected or dismissed and is linked to the file input. The quick capture hint is not an alert until submit.
- **AC-11**: Text and interface colors meet WCAG 2.2 AA contrast in the available dark theme. Focus indicators stay visible in the dark theme and in OS forced colors mode. If a light theme is added, it must also meet AA. No state or required information relies on color alone.
- **AC-12**: Motion respects `prefers-reduced-motion`. No essential action depends on animation.
- **AC-13**: Quick capture opens with `Alt+Shift+N`. The handler requires Alt and Shift, rejects Ctrl and Meta, and ignores repeats and composing. A single `N` no longer opens it. The tooltip, the quick capture modal hint, and `aria-keyshortcuts` show the same shortcut.
- **AC-14**: A table width menu offers narrow, medium, wide, and reset. Narrow, medium, and wide set every cell `colwidth` from a fixed 720 pixel design width at 50, 75, and 100 percent, divided across the columns. Reset clears `colwidth`. The menu shows the preset whose total width is within 40 pixels, otherwise none is checked. It works with keyboard and touch, and the chosen width persists after save and reload. The offline editor has no resize handles, preserves existing `colwidth`, and lets tables auto size.
- **AC-15**: The offline app meets the same semantics, keyboard, focus, status, label, touch target, responsive, contrast, motion, and validation rules. It keeps native `prompt` and `confirm` dialogs and has no formatting toolbar.
- **AC-16**: Pest DOM checks for the Livewire notes page cover labels, landmarks, the skip link, toolbar names and pressed state bindings, shortcut hints, and the single main landmark. No new test dependency is added. Offline parity is covered by the manual matrix in AC-17.
- **AC-17**: The manual browser and assistive technology matrix passes: VoiceOver on Safari for iOS and macOS, NVDA on Chrome or Firefox for Windows, and a smoke test on Android Chrome. The installed app passes a cold offline reload after a build, local note editing, sync status, and sign in recovery that returns to `/app`.
- **AC-18**: Existing behavior and tests still pass: note create, edit, save, search, sort, folders, trash and restore, quick capture, reminders, attachments, and sync.
- **AC-19**: Status, live region, toast, and log output never include a note title or note content. Accessible names may include the note title. Server exception logs redact payload and bindings to the exception class plus a correlation id, and offline `last_error` stores a safe message only.

## Decision

**Chosen option**: Option 1: Fix the current surfaces in place with targeted accessible and responsive changes.

The authenticated Livewire notes page and the installed offline app keep their current architecture and each receive the same accessible behavior through their own implementation.

**Implementation skills**: `fluxui-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/fluxui-development/`) · `livewire-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/livewire-development/`) · `tailwindcss-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/tailwindcss-development/`) · `testing-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/testing-best-practices/`)

## Feature design

**Data model sketch**:
None. There is no new table, column, index, or persisted preference. The table width choice lives in the existing note HTML through the `colwidth` attribute that the Tiptap table extension already uses. No reminder field enters offline data.

**State transitions**:
- View state below 1024 pixels: list to editor when a note is selected, and editor to list through the back control. At 1024 pixels and up, folders, list, and editor stay visible in three columns.
- Status state on `/notes`: online, offline, saving, and uploading. Validation uses its own alert. Status state on `/app`: online, offline, syncing, needs sign in, and error. The current status is always shown as text, not only as a colored dot.
- Modal state: closed to open to closed. Quick capture moves focus in on open and returns it to the opener or a safe fallback on close.
- No domain state changes. Note and folder rules stay as they are.

**API surface**:
None. There is no new endpoint, request, response, auth rule, or CSRF behavior.

**Value sourcing**:
| Action | Value produced or displayed | Source |
|---|---|---|
| Note list item | Accessible name | Note title, with `Untitled` when empty |
| Search field | Visible label and accessible name | Static copy, `Search notes` |
| Sort field | Visible label and accessible name | Static copy, `Sort notes` |
| Note title field | Visible label and accessible name | Static copy, `Title` |
| Folder field | Visible label and accessible name | Static copy, `Folder` |
| Editor toolbar | Control name | Static action label from the toolbar control map |
| Editor toolbar | Pressed state | Current Tiptap selection and mark state |
| Editor toolbar | More menu contents | Static list of secondary controls hidden below 768 pixels |
| Editor hint | Visible and screen reader text | Static copy that names the toolbar; pressed state carries the current format |
| Skip link | Focus target | The notes main landmark id |
| Notes status | Status text | Browser online events plus the save and upload state |
| Notes status | Live region role | Persistent `role="status"` for connection and upload state; validation errors use `role="alert"` |
| Offline status | Status text | Browser online events plus the offline sync runtime state |
| Offline status | Last sync time | `sync_meta.last_sync_at` for the current user |
| Offline status | Pending count | Number of outbox rows for the current user |
| Validation | Error text and field link | Laravel validation error bag |
| Focus key | Stable target id | Note id, folder id, action name, or list heading |
| Back control | Focus target | Selected note row, else the list heading |
| Delete action | Focus target | Next note, else previous note, else the list heading |
| Restore action | Focus target | Restored note control, else the Trash heading |
| Folder change | Focus target | The note folder select; a deleted folder also returns focus there |
| Quick capture | Return focus | Opener, else the header button, else the list heading |
| Responsive view | List, editor, or three column view | Current viewport width compared with the `min-width: 1024px` breakpoint |
| Touch target | Minimum size | 44 pixels for primary and mobile controls; 24 pixels with spacing for toolbar controls |
| Quick capture | Shortcut hint | The `Alt+Shift+N` constant used by the handler, hint, and `aria-keyshortcuts` |
| Table width menu | Chosen preset | The user's menu choice: narrow, medium, wide, or reset |
| Table width menu | Applied column width | Fixed 720 pixel design width at 50, 75, or 100 percent, divided across columns and written to each cell `colwidth` |
| Table width menu | Current preset | Total `colwidth` within 40 pixels of a preset target, otherwise none |
| Table width menu | Reset result | Every cell `colwidth` cleared |
| Offline dialog | Dialog text | Native `prompt` or `confirm` copy |
| Announcement | Spoken text | The status or validation message, never note title or content |
| Log line | Error detail | Exception class plus a correlation id, never payload or bindings |

**Key invariants**:
- WCAG 2.2 AA is the bar for both surfaces.
- Each page has exactly one main landmark, and the skip link is the first focusable control.
- Every interactive control has an accessible name. Every toggle exposes its state.
- No status, live region, toast, or log line includes a note title or note content. Accessible names may include the note title.
- Primary and mobile controls are at least 44 by 44 pixels. Toolbar controls are at least 24 by 24 pixels with spacing.
- The responsive breakpoint is `min-width: 1024px`.
- The quick capture shortcut is `Alt+Shift+N` everywhere it is shown or handled.
- Table widths use the existing per cell `colwidth` attribute only, and reset clears it. The offline editor preserves existing widths and disables resize.
- Focus restoration uses stable focus keys and runs after the Livewire morph or the offline render.
- Each surface has one persistent status region, updates it in place, and announces each event once.
- The offline app follows the same behavior with its own DOM code and keeps native dialogs.
- Existing note, folder, reminder, attachment, and sync behavior stays unchanged.

**Security model**:
No change. Every note, folder, and attachment query remains scoped to `auth()->user()`. Note content stays escaped HTML. The new status and announcement paths must not write note titles or content to the DOM live region, toast, browser log, or server log. Server exception logs use the exception class plus a correlation id, never payload or bindings. Offline `last_error` stores a safe message only. `APP_DEBUG` stays false in production. The offline store remains per user.

**Configuration required**:
None. No new environment variable, credential, dependency, or build step.

**Critical test scenarios** (each maps to an acceptance criterion in `## Requirements`):
- Keyboard list: tab to a note, select it with Enter, reach its reminder and delete controls, and keep the focused control visible; verifies **AC-1**, **AC-8**, **AC-13**.
- Labels and landmarks: inspect search, sort, title, folder, toolbar, More menu, folder actions, and the skip link; confirm one main landmark and a working skip target; verifies **AC-2**, **AC-3**, **AC-4**.
- Validation and status: submit an empty title, an invalid folder, a bad upload, an empty quick capture, and an invalid reminder; confirm inline alert text, field link, focus, live success, and one announcement per event; verifies **AC-9**, **AC-10**, **AC-19**.
- Focus after actions: save, delete a middle note, delete the last note, restore a note, change a folder, delete the selected folder, open and close quick capture, and use the back control; confirm each focus target and fallback; verifies **AC-8**.
- Responsive and touch: check 320, 375, 768, 1024, and 1440 pixels; confirm list and editor views, back focus, folders above the list, three columns at 1024, no horizontal scroll, 44 pixel primary targets, and 24 pixel toolbar targets; verifies **AC-5**, **AC-6**, **AC-7**.
- Color, motion, and forced colors: check the dark theme, forced colors, focus rings, and reduced motion; if a light theme exists, check it too; verifies **AC-11**, **AC-12**.
- Table width: insert a table, apply each preset with keyboard and touch, confirm the preset detection, reset it, save, reload, and open the note offline; verifies **AC-14**.
- Offline parity: install the app, reload cold after a build, edit a note, check status and focus, and recover from an expired session back to `/app`; verifies **AC-15**, **AC-17**.
- Automated DOM: run the Pest checks for the Livewire page labels, landmarks, skip link, toolbar names, pressed state bindings, shortcut hints, and one main landmark; verifies **AC-16**.
- Manual matrix: run VoiceOver, NVDA, and Android Chrome smoke checks on both surfaces and confirm each event is announced once; verifies **AC-17**.
- Regression: run the existing notes, quick capture, reminder, attachment, and sync tests; verifies **AC-18**.

## Build plan

1. Add the shared baseline to the authenticated layout and the offline shell: skip link, one main landmark, a visible focus style, reduced motion handling, and dark theme plus forced colors tokens, satisfies **AC-4**, **AC-11**, **AC-12**.
2. Add labels and names to the notes page fields, folder actions, and editor toolbar; add the control name map, pressed state, static hint, toolbar wrapping, and the More menu below 768 pixels, satisfies **AC-2**, **AC-3**.
3. Make the note list keyboard operable and add stable focus keys and focus restoration after save, delete, restore, folder change, back, and quick capture; change the quick capture shortcut and its hints, satisfies **AC-1**, **AC-8**, **AC-13**.
4. Add the `/notes` status and validation behavior: one persistent status region, online and offline state, save and upload state, inline errors, focus to the first invalid field, and log redaction, satisfies **AC-9**, **AC-10**, **AC-19**.
5. Add the `/notes` responsive layout: three columns at 1024 pixels and up, list or editor below 1024, folders above the list, the back control and its focus target, resize focus preservation, 44 pixel primary targets, 24 pixel toolbar targets, and overflow handling, satisfies **AC-5**, **AC-6**, **AC-7**.
6. Add the table width menu with the fixed 720 pixel mapping, preset detection, and reset, and add the offline resize option that preserves `colwidth`, satisfies **AC-14**.
7. Add the offline semantics and full sync status: labels, landmarks including the sign in state, one persistent status region, validation, focus keys, and a shell that keeps the editor and status bar mounted, satisfies **AC-15**.
8. Add the offline responsive layout, keyboard operation, focus moves, dark theme and forced colors, reduced motion, native dialogs, and primary and mobile touch targets, satisfies **AC-15**.
9. Add the sign in return path to `/app` and the cold offline asset support, then run the manual browser and assistive technology matrix and record the results in `verify.md`, satisfies **AC-17**.
10. Add Pest DOM checks for the Livewire page labels, landmarks, skip link, toolbar names, pressed state bindings, shortcut hints, and the single main landmark, satisfies **AC-16**.
11. Run the existing notes, quick capture, reminder, attachment, and sync regression tests, satisfies **AC-18**.

## Consequences

**Positive**:
- Keyboard and screen reader users can complete every current note task on both surfaces.
- Phone users get a layout that fits the screen and controls that are easy to tap.
- The behavior has a written contract and automated DOM checks, so later UI changes are less likely to remove it.
- The current Flux components, Livewire actions, and offline client stay in place, so the change is small enough to ship in slices.
- No new dependency, schema, endpoint, or auth rule is added.

**Negative / tradeoffs**:
- Two clients still mean two implementations. The Livewire page and the offline app must both keep the same behavior.
- Some Flux internals and Tiptap toolbar markup may need small overrides, which can break on a future package upgrade.
- The table width menu and pointer resize share per cell `colwidth`, so the preset detection needs a tolerance and can show no preset after a manual drag.
- The manual assistive technology matrix is slow and needs a real browser and device access.
- The 1024 pixel breakpoint changes the current layout on tablets in portrait orientation.
- Below 768 pixels, secondary toolbar controls move into a More menu, so not every tool is visible at once.
- The app has no light theme today. The contrast claim covers the available dark theme and forced colors only.

**Neutral**:
- The notes page gets a visible layout change below 1024 pixels.
- The quick capture shortcut changes from `N` to `Alt+Shift+N`.
- Table width choices are stored in the existing note HTML, so no migration is needed.
- The offline app keeps native dialogs and gains no formatting toolbar.
- The sign in flow gains a return path to `/app`.

## Follow-up

- [ ] Add a light theme and verify WCAG 2.2 AA contrast in both themes.
- [ ] Add a repeatable accessibility check to CI if the project later approves a browser test dependency.
- [ ] Review the notes page on a real iPad in portrait orientation after the breakpoint change.
- [ ] Recheck the toolbar overrides after a Flux or Tiptap upgrade.
- [ ] Recheck the service worker precache after each build so a cold offline reload never requests an uncached asset.
- [ ] Decide whether the offline app should get a formatting toolbar in a later slice.

## Rationale

Reasoning and options: see [rationale.md](rationale.md).
