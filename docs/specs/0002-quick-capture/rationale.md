# Rationale: Quick capture

## Context

Creating a note today takes several steps. New Note creates an Untitled Note without selecting it, then you find it in the list, click it, scroll to the editor, fill the title and folder, write, and save. For a thought you want to keep for ten seconds, that path is long enough to lose the thought.

The app is one main notes page backed by a single Livewire component. The editor is Tiptap, it owns its own DOM, and it only sends HTML to the server on save. Note content is stored as HTML, the title is required, and the notes table already supports folders, search, trash, and attachments. There is no offline mode and no sync to design around. The scope marks this feature as Slice 2, built with the Tracer Bullet approach and checked in the real app under the Alpha workflow.

## Options considered

### Option 1: Extend the existing New Note flow

Make New Note create and select a note, then move focus into the title or editor.

**Pros**:
- No new interface and no new state.
- Reuses the full rich text editor from the first save.

**Cons**:
- Still requires scrolling, a title decision, and a folder decision before the thought is safe.
- Booting the full editor for a short thought is heavy on a phone.
- Changes the meaning of a familiar control.

### Option 2: Notes page modal with a plain text box

A modal in the notes component, opened by a button or the N key, with one text box.

**Pros**:
- One action to open and one shortcut to save.
- No editor boot, so it is fast on touch and desktop.
- State stays in the existing Livewire component, so the list refreshes immediately.
- Reuses the note table and every behavior built on it.

**Cons**:
- Adds a second way to create a note, with its own title rule.
- Plain text only, so formatting needs the editor later.
- Only reachable from the notes page.

### Option 3: Global capture component in the app layout

Place a capture modal in the shared layout so it works on every signed in page.

**Pros**:
- Usable from the dashboard and profile as well.
- Builds the habit of capturing from anywhere.

**Cons**:
- Needs a separate Livewire component and a way to refresh the notes list on another page.
- The app has very few pages, so the extra reach is small today.
- More moving parts for the same result.

### Option 4: Inline capture bar above the note list

An always visible form at the top of the notes page.

**Pros**:
- No click to open and very discoverable.
- Works with keyboard and touch.

**Cons**:
- Takes permanent space from the list.
- Competing focus with search and the editor.
- A modal is clearer about the start and end of a capture.

## Rationale

Option 2 fits the current app and the Tracer Bullet approach. The notes page is where the list lives, so the captured note can appear without cross component refresh or navigation. The modal keeps the quick path separate from the editor, which matters because Tiptap owns its DOM and only syncs on save. A separate global component would add coordination work for pages the user rarely visits.

Reusing the note table is the main reason the feature stays small. A capture is a normal note, so search, folders, trash, editing, and attachments apply without new code or a migration. A separate capture table would need promotion, duplicate list logic, and its own cleanup.

Plain text is chosen over a mini rich text editor because the job is speed. The first line as the title keeps the list readable, and sending overflow past the title cap into the body means the rule never drops words. No folder is assigned because All Notes is the one view guaranteed to show the note, and filing later in the editor is cheap.

The after save reset follows from the visibility goal. All Notes, an empty search, and the Newest sort put the new note at the top. The open editor is left alone so no unsaved work is touched.

N and Ctrl/Cmd+Enter were chosen for speed and safety. Enter must remain a new line for multi line thoughts, and a single N avoids the browser conflicts of Ctrl/Cmd+K. The key only fires when focus is outside fields and the editor, with no modifiers, no other modal open, and not in Trash. A save failure keeps the modal and the text, because losing a captured thought to a database error is worse than a second click.

One accepted tradeoff is accessibility. A single character shortcut can interfere with assistive technology quick navigation, and WCAG 2.1.4 asks for a way to turn it off or remap it. The feature is small enough to ship before that control exists, and a later item records the debt.

Two smaller choices follow from the same goal. A client in flight flag guards the single submit path, because a queued Livewire request carries its own text and clearing the server value would not stop it. The list preview decodes entities once before Blade escapes them, so typed tags read as tags; storage stays escaped and no raw HTML is ever rendered.
