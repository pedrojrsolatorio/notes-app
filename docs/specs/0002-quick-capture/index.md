# 0002. Quick capture

**Date**: 2026-10-28
**Status**: In Progress

## Summary

Quick capture lets you save a passing thought from the notes page in seconds, without going through the full editor. You open it with a single key on a computer or a floating button on a touch screen, type in one box, and the first line becomes the title. A capture is an ordinary note, so search, folders, trash, editing, and attachments keep working. The feature uses the existing note table and needs no database change.

## Requirements

**User stories**:
- As the owner of these notes, I want to capture a thought in seconds from the notes page so that I do not lose it while navigating to the editor.
- As a keyboard user, I want to open capture with a single key and save with a familiar shortcut so that my hands stay on the keyboard.
- As a touch user, I want a button within thumb reach so that capture works without a keyboard.

**Acceptance criteria** (the contract, each criterion is independently checkable):
- **AC-1**: On the notes list, and not in Trash, a visible Quick capture button opens a modal with a single labeled text box that holds focus.
- **AC-2**: A single save action, from the save button or Ctrl/Cmd+Enter, creates exactly one note owned by the signed in user, closes the modal, and shows a success toast. Both entry points are inert while a save is in flight.
- **AC-3**: The first line becomes the title, trimmed and capped at 255 characters by code point. Any overflow past the cap and every later line become the note body, so no typed words are lost.
- **AC-4**: Pressing N while focus is not in an input, text area, select, or content editable region, with no modifier key, no open dialog or popover, and not in Trash, opens the same modal. Key repeat, a composing key event, and held modifiers do nothing, and Shift+N never opens it.
- **AC-5**: Below the Tailwind `md` breakpoint (768px) the header trigger is hidden and a fixed floating button opens the modal. At `md` and wider the header trigger is shown and the floating button is hidden.
- **AC-6**: In Trash, the header button, floating button, and N shortcut do nothing, and both the open and save actions refuse to run.
- **AC-7**: Text that is empty or only Unicode whitespace cannot be saved. The save control is disabled in the interface with a short message asking for some text, and a crafted submit fails validation with an inline message.
- **AC-8**: After a successful save, the view shows All Notes, the search is empty, the sort is Newest, the folder filter is cleared, and the open editor selection is unchanged.
- **AC-9**: Closing without saving through Escape, Cancel, the X, or an outside click discards the draft, so the next open starts empty. A database failure keeps the modal open with the text intact and shows a danger toast.
- **AC-10**: The interface teaches the shortcuts. The trigger tooltip names N and shows a key hint, and the modal footer names Ctrl/Cmd+Enter and N.
- **AC-11**: User text is stored as escaped HTML paragraphs. Raw HTML typed into the box shows as the literal tags in the list and the editor and is never executed.
- **AC-12**: A captured note is an ordinary note. It appears under All Notes, is searchable by title and body, can be opened, edited, moved to trash, restored, and receive attachments.

## Decision

**Chosen option**: Option 2: Notes page modal with a plain text box.

Quick capture lives in the existing notes Livewire component as a Flux modal. It opens from a header button, a floating button below the `md` breakpoint, or the N key, and it saves an ordinary note with no folder and a derived title.

**Implementation skills**: `laravel-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/laravel-best-practices/`) · `livewire-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/livewire-development/`) · `fluxui-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/fluxui-development/`) · `tailwindcss-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/tailwindcss-development/`) · `testing-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/testing-best-practices/`)

## Feature design

**Data model sketch**:

| Entity | Fields | Relationships and constraints |
|---|---|---|
| Note | Existing fields: `id` primary key, `user_id` required foreign key, nullable `folder_id`, required `title` string up to 255, nullable `content` text, `version` integer, timestamps, soft delete marker | Belongs to one user and zero or one folder. A captured note has `folder_id` set to null. |
| User | Existing user fields | One user has many notes. The note owner always comes from the signed in session. |
| Folder | Existing folder fields | No change. Captures are never assigned to a folder. |

No new table, column, index, or migration. The existing `notes.content` column stores the body as escaped HTML paragraphs. The existing `version` column is untouched.

**Interface behavior**:
- Show a Quick capture button beside New Note at `md` and wider, and a fixed floating button below `md` at the bottom right with a safe area inset. Hide both in Trash.
- Wrap the header button in a `flux:tooltip` with its content and `kbd="N"`. Use a Flux modal with a heading, one `flux:textarea` with a label and `autofocus`, a helper message when the text is blank, and an inline validation error.
- The modal opens empty and clears on every close. One `closeQuickCapture()` action handles Escape, Cancel, the X, an outside click, and entry into Trash.
- Ctrl/Cmd+Enter submits. Enter inserts a line. The button, the form submit, and the shortcut call one submit path.
- Put the shortcuts in the footer as `<kbd>` hints: Ctrl or Cmd plus Enter, and N.
- A save sets a client in flight flag before the request and clears it when the request settles. The button is disabled and the shortcut returns early while the flag is set.
- Guard the shortcut in the browser with `dialog[open]` and `[popover]:popover-open`, the focus target, modifier keys, key repeat, and composition. Compare the lowercased `event.key`, never `event.code`.
- Text rule: normalize CRLF and CR to LF, trim Unicode whitespace from the whole text once, and split at the first LF. Trim the first line again. The title is its first 255 code points. The body is the first line from code point 256 onward, plus every later line, then trimmed as a whole. A body of `''` stays empty.
- Store the body as one `<p>` per line, each line escaped with `e()`. An empty line becomes `<p></p>`.
- Decode entities once in the existing list preview before Blade escapes, so typed tags show as tags rather than entities.
- Impose no additional body length limit beyond the storage column. The server request size limit is the practical ceiling, and a paste beyond it fails before this action runs.
- Validation errors keep the modal open and show inline messages only, with no danger toast. A throwable from the create call shows a danger toast and keeps the text.

**State transitions**:
- Closed, no draft → open with an empty box and focus in the text box.
- Open, empty or only Unicode whitespace → save control disabled, helper message shown, modal stays open.
- Open with text → submit → save in flight, button disabled, shortcut inert.
- Save in flight → success → note created, view reset to All Notes, modal closed, draft cleared, success toast.
- Save in flight → validation error → in flight cleared, modal stays open, text kept, inline error.
- Save in flight → database error → in flight cleared, modal stays open, text kept, danger toast.
- Open with text → Escape, Cancel, the X, or outside click → modal closed, draft cleared.
- Open with text → Trash opened → modal closed, draft cleared.
- Trash view → open trigger, N, or open action → refused.

**API surface**:

| Surface | Kind | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `openQuickCapture()` | Livewire action | None | Modal open with empty text, validation cleared, focus in the text box | Signed in and verified user on the notes route | Returns silently while `isTrashView` is true |
| `saveQuickCapture()` | Livewire action | `quickCaptureText` string, required | One created Note, modal closed, view reset, success toast | Note created through `auth()->user()->notes()` | Returns silently in Trash; blank text returns a validation error; any other throwable shows a danger toast and leaves the modal open |
| `closeQuickCapture()` | Livewire action | None | Modal closed, text cleared, validation cleared | Existing component | None |
| `$showQuickCaptureModal` | Public property | Flux modal open state | Boolean | Existing component | None |
| `$quickCaptureText` | Public property | Text box binding | Raw string, escaped before storage | Existing component | Validation message when blank |

No new route, controller, public API, external service, or configuration value. The existing notes route keeps the `auth` and `verified` middleware.

**Value sourcing**:

| Action | Value produced or displayed | Source |
|---|---|---|
| Open | Modal visibility | `$showQuickCaptureModal` |
| Open | Focus target | `autofocus` on the `flux:textarea` inside the native dialog |
| Open | Header and floating trigger visibility | Tailwind `md` classes on the existing page |
| Open | Trash trigger availability | `$isTrashView` |
| Open | Whether the N shortcut is active | `$isTrashView`, `event.key`, modifier keys, `event.repeat`, `event.isComposing`, the focus target, and `document.querySelector('dialog[open], [popover]:popover-open')` |
| Save | Raw text | `$quickCaptureText` from the text box |
| Save | Note owner | `auth()->id()`, never a browser input |
| Save | Whitespace rule | Unicode whitespace trimmed once on the server; JS `trim` for the disabled state |
| Save | Title | First 255 code points of the first line after its own Unicode trim |
| Save | Body | First line from code point 256, plus later lines, trimmed as a whole and escaped into one `<p>` per line |
| Save | Folder | Null by decision; no folder input exists |
| Save | Created note | Existing `notes` table through the signed in user's notes relation |
| Save | Refusal in Trash | `$isTrashView` checked at the start of open and save |
| Save | Submit in flight | Client `saving` flag set before the request and cleared when it settles; the button and shortcut read it |
| Save | Save control enabled state | The client `saving` flag plus the trimmed text; the server repeats the Unicode trim |
| Save | Validation feedback | Livewire error bag from `$this->validate`; helper message from the client trim |
| Save | Draft clearing | `closeQuickCapture()` clears `$quickCaptureText` and validation |
| Save | Success message | Static translated text |
| Save | Failure message | Static translated danger text from a throwable caught around the create call only |
| Save | List view | `selectedFolderId` set to null, `search` set to empty, `sortBy` set to `newest`; `isTrashView` is already false because the guard runs first |
| Display | Shortcut labels | Static text N and Ctrl/Cmd+Enter in the tooltip and footer |
| Display | Preview text | Existing preview after `html_entity_decode(strip_tags($note->content ?? ''))`, then Blade escaping |

**Key invariants**:
- A single save action creates exactly one note, owned by the signed in user. The button and the shortcut are inert while a save is in flight.
- A captured note always has `folder_id` set to null.
- The stored title is never empty and never longer than 255 code points.
- The body has no app level cap. The server request size limit is the practical ceiling and sits outside this rule.
- Unicode whitespace is trimmed once before the title and body split.
- The body is stored as one escaped paragraph per line.
- The list preview decodes entities once and Blade escapes the result, so no raw HTML is executed.
- Quick capture never reads or changes the editor title, editor content, selected note, or attachment state.
- Trash is view state, not an authorization boundary. The open and save actions refuse to run there, and a crafted call that clears it is harmless because ownership comes from the session.
- Closing always clears the draft.
- The note list reset after save is limited to the notes page view state.

**Security model**:

The notes page already requires a signed in and verified account. Every capture is created through that account's notes relation, so ownership cannot be supplied or changed by the browser. There is no folder input, so there is no cross account folder assignment to validate. The feature adds no public endpoint, sharing rule, or administrator override. Note text is private to its owner under the existing model. The Trash refusal and the keyboard guard are interface rules, not access control. A crafted request can call the action, but it still creates a note only for the signed in user.

**Configuration required**:

None.

**Critical test scenarios**:
- Happy path: open from the button, type two lines, save, and confirm one note is created with the expected title and body, the modal closes, the view shows All Notes with an empty search and Newest sort, and a success toast is sent. Verifies **AC-1**, **AC-2**, **AC-3**, **AC-8**.
- Title only: capture one line and confirm the title is set and the body is `''`. Verifies **AC-3**.
- Overflow and line endings: capture a 255 code point line, a 256 code point line with an emoji at the boundary, a CRLF line ending, and a leading blank line. Confirm the title, the overflow, and every later line survive exactly. Verifies **AC-3**.
- Exact storage and preview: capture a body containing `<b>bold</b>` and `Tom & Jerry`. Assert the exact stored HTML string, the list preview showing the literal tags and the ampersand, and the editor seed round trip. Verifies **AC-11**, **AC-12**.
- Empty text: try spaces, tabs, a newline, and an NBSP only. Confirm the control is disabled with the helper message, a direct call returns the validation error key, and no note is created. Verifies **AC-7**.
- Exactly once: a single action call creates one note. Check a manual double click and a double Ctrl/Cmd+Enter in the browser. Verifies **AC-2**.
- Failure case: throw from `Note::creating`, through a test double, and confirm the modal stays open with the text intact, a danger toast is sent, and no note is created. Verifies **AC-9**.
- Security case: type raw HTML and confirm it is escaped in storage and never executed. Confirm a note created for one account is not visible to another, that `user_id` is the actor, and that `folder_id` is null. Verifies **AC-2**, **AC-11**, **AC-12**.
- View reset: set a non default search, folder, sort, selected note, editor title, and editor content before the capture. Confirm the list resets to All Notes, an empty search, and Newest, while the selected note and editor values are unchanged. Verifies **AC-8**.
- Keyboard case, manual: open with N outside fields, ignore N inside the search field and the editor, with a modifier held, with Shift, on key repeat, during IME composition, and while the folder dialog is open. Verifies **AC-4**.
- Trash case: show Trash and confirm the triggers are absent in the render, the open and save actions return silently, and direct calls create nothing. Verifies **AC-6**.
- Responsive case, manual: below 768px the header trigger is hidden and the floating button opens the modal; at 768px and wider the reverse. The tooltip reads N and the footer shows both hints. Verifies **AC-5**, **AC-10**.

The automated suite covers the happy paths, the text rule, storage, escaping, ownership, the view reset, the failure path, and the Trash refusal. AC-4, AC-5, AC-7's disabled interface, AC-10, and the double submit check are manual, because they depend on the browser.

## Build plan

The Tracer Bullet approach starts with one complete path from the trigger to a saved note, then adds the keyboard and touch entry points, then the edge and failure handling. There is no migration because the note table already fits.

- [x] 1. Build the thin path end to end in `resources/views/components/notes/⚡index.blade.php`: add the modal properties, `openQuickCapture()`, `closeQuickCapture()`, `saveQuickCapture()`, the Flux modal with a labeled `flux:textarea` and `autofocus`, the helper message, the inline error, Cancel and Save controls, and the header trigger. Implement the exact text rule, the escaped paragraph conversion, the create through the signed in user, the All Notes reset, the close, the success toast, the single submit path with the in flight flag, and the preview entity decode. Give validation errors inline feedback only. Add focused Pest coverage in `tests/Feature/QuickCaptureTest.php`. Satisfies **AC-1**, **AC-2**, **AC-3**, **AC-7**, **AC-8**, **AC-11**, **AC-12**.
- [x] 2. Add the N key listener with its guard for `dialog[open]`, open popovers, fields, content editable regions, modifiers, key repeat, composition, and Trash. Use `event.key` and never `event.code`. Add the `flux:tooltip` with `kbd="N"` to the header trigger and the two `<kbd>` hints to the modal footer. Satisfies **AC-4**, **AC-10**.
- [x] 3. Add the floating trigger at the bottom right for widths below `md`, hide the header trigger there, add the Trash guards to both open and save, close the modal on Trash entry, add the failure path that keeps the draft open with a danger toast, and run the keyboard and responsive checks. Satisfies **AC-5**, **AC-6**, **AC-9**, **AC-10**.

## Consequences

**Positive**:
- A thought can become a note in one step, without loading the full editor.
- The feature adds no table and no dependency, and every captured note inherits search, folders, trash, and attachments.
- The title and body rule keeps the list readable while preserving the typed words.
- Both keyboard and touch users get a direct entry point.
- The list preview now reads as typed, because entities are decoded once before Blade escapes them, so `&` and typed tags are readable in every note.

**Negative / tradeoffs**:
- Capture only works on the notes page, so another page still needs a navigation step.
- The capture box is plain text, so formatting must be added later in the editor.
- A single letter shortcut can interfere with assistive technology quick navigation. WCAG 2.1.4 asks for a way to turn it off or remap it, which is not part of this build.
- Saving resets the folder filter, search, and sort to Newest, which overrides a view the user may have chosen on purpose.
- The title and body derivation is a product rule the editor does not share, so a note can look different depending on where it was created.
- A paste larger than the server request limit fails before the action runs and shows the framework error, not the danger toast.
- The preview decode touches every note, not only captures. It is a one line display change with no storage effect.
- Search still runs on the stored text, so a search for a literal `<` or `&` does not match its stored entity form. This is existing search behavior and stays out of scope.
- The shortcut guard is client side. A crafted call can open the modal, but it cannot create a note for another user.

**Neutral**:
- The notes component gains a close action and a client saving flag.
- The floating button uses a fixed position and a safe area inset, so it sits above the mobile browser chrome.
- No asset pipeline change is expected beyond newly used Tailwind classes.

## Follow-up

- [ ] Address WCAG 2.1.4 for the single letter shortcut: offer a modifier form or a setting to turn it off, during the accessibility feature.
- [ ] Consider extending capture to the dashboard and profile after the notes page version is verified.
- [ ] Consider a View note action in the success toast if the toast component supports actions.
- [ ] The community skill pointers still flagged in spec 0001 are missing from root `AGENTS.md`; add them before the next build.
- [ ] Make the Newest sort deterministic with an `id` tiebreaker if same second ties become noticeable.

## Rationale

See [rationale.md](./rationale.md) for the context, options, and reasons behind this decision.
