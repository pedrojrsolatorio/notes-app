# 0005. Note Reminders

**Date**: 2026-11-14
**Status**: Proposed

## Summary

You can schedule one email reminder for a note, change it, or clear it. The app stores the chosen moment in UTC (a single time standard), then sends a generic email when it is due. Reminders stay on the note, and the feature uses the app's existing mail service, background queue (work waiting to run), and scheduler (the service that checks when work is due).

## Context

The notes app needs a way to help you return to a note at a time you choose. A reminder must still work when the notes page is closed, and must not expose private note content in an email.

Notes can be moved to Trash and edited from the offline client. Reminder delivery depends on the server, scheduler, and queue worker, so reminder state must not be mistaken for note content or accidentally sent by offline sync (the process that copies note edits between devices). Reminders also need clear recovery behavior when a note changes, mail delivery fails, or background services are unavailable.

## Requirements

**User stories**:

* As a note owner, I want to set, change, and clear a reminder so I can return to a note at a time I choose.
* As a note owner, I want a generic email reminder with a link to my note so I can reopen it without exposing its contents.
* As a note owner, I want clear failure and Trash behavior so a reminder does not become an unexpected or stale email.

**Acceptance criteria**:

* **AC-1**: On the authenticated notes page, you can set, change, or clear one reminder for the selected note using a modal. A new reminder starts with a blank date and time field. When editing, the field shows the current reminder. The note list shows the reminder time and its scheduled, queued, or failed state. Scheduled reminders offer Change and Clear, queued reminders show their pending state and offer Change and Clear, and failed reminders offer Change, Retry now, and Clear. In Trash, an existing reminder is shown with Clear only. Actions provide success feedback, and invalid input shows an inline error.
* **AC-2**: The date and time input uses the browser's local time zone and minute precision. The browser sends a UTC ISO 8601 value ending in `Z`, with seconds and milliseconds set to zero, and the server stores the resulting instant in UTC. A newly entered time in the past is rejected against the server's UTC clock. There is no maximum future date. The list displays the stored instant in the browser's current local time zone at minute precision, using the browser's locale, so travel does not change the instant.
* **AC-3**: The browser resolves the local date and time in its current time zone before sending it. A local time that does not exist during a spring daylight saving change is rejected when conversion does not round trip to the requested local time. When a local time occurs twice during an autumn change, the earlier UTC instant is selected.
* **AC-4**: A scheduler runs every minute and queues due reminders for delivery. When the scheduler or queue worker is unavailable, an active note's due reminder is delivered after service recovers. With services running, dispatch begins within the next scheduler interval.
* **AC-5**: The email uses the app's configured mail transport and the user's current email address. The app sends only when that address is currently verified. If it is not verified, the reminder enters the failed state without sending, and you can retry it after verifying the address.
* **AC-6**: The email uses the generic subject “Your note reminder” and body “You asked to revisit a note. Open your notes to view it.” Its link opens the specific note on the authenticated notes page. If you are signed out, sign in returns you to that note. A missing, trashed, permanently deleted, or non owned note is unavailable. In those cases the page shows the notes list with the generic notice “This note is no longer available” and does not reveal note contents.
* **AC-7**: After the mail transport accepts delivery, the app clears the reminder time, state, and generation token. It keeps no reminder history.
* **AC-8**: A mail delivery failure is retried at the original due instant, one minute after it, and five minutes after it. After the third failed attempt, the reminder enters the failed state only if the job's generation still matches. The note list offers Retry now and Clear. Retry now starts a fresh attempt cycle. Changing the reminder time also starts a fresh cycle. Saving the same UTC instant does not reset the current cycle.
* **AC-9**: Each schedule or manual retry cycle has a new server generated UUID token. A queued job acts only when its token still matches the note's current token and the reminder is due and active. A clear or schedule change completed before the job's final check prevents that job from sending. Once mail handoff has begun, it cannot be recalled. The job updates reminder state only if its generation still matches, so it cannot overwrite a newer reminder. Reminder changes do not update the note's content modification time or sync version.
* **AC-10**: Moving a note to Trash retains its reminder and pauses delivery. The due query includes soft deleted notes. A reminder that becomes due while the note is in Trash is cleared without sending or keeping history. Restoring the note before its due time resumes the reminder. Restoring it after the due time clears the reminder without sending, even if the scheduler was unavailable while the note was in Trash. Setting, changing, or retrying a reminder is unavailable while the note is in Trash; you may clear an existing reminder there.
* **AC-11**: Only a signed in, verified user can manage reminders on notes they own. Every read and write is scoped to the user's notes. A request for another user's note reveals no note data.
* **AC-12**: Reminder fields are not included in note backup exports or restores. Restored notes have no active reminders, and restoring a backup does not send reminder email.
* **AC-13**: Reminder management is available only on the authenticated notes page. Reminder changes do not create offline sync change records or add reminder data to the offline client or sync payload.

## Options considered

### Option 1: Store one reminder on each note and send email through the app

Keep the reminder time and state on the existing note. Use the existing scheduler and queue to send a generic email when the time is due.

**Pros**:

* Fits the one reminder per note requirement without a new table or service.
* Email works when the browser is closed and uses the app's existing delivery configuration.
* The note row gives the scheduler a direct, indexed way to find due reminders.

**Cons**:

* Reliable delivery depends on the scheduler, queue worker, and mail transport being operated.
* Reminder state needs explicit protection from note sync, backup, Trash, and stale jobs.

### Option 2: Store reminders in a separate table

Give each reminder its own record, with room for multiple reminders or a delivery history.

**Pros**:

* Separates reminder lifecycle from note lifecycle and supports future reminder history.
* Can grow to multiple reminders per note without changing the note table.

**Cons**:

* Adds a relationship, migration, and more lifecycle handling for a feature that allows only one reminder per note.
* History and multiple reminders are outside the requested scope.

### Option 3: Use browser notifications

Schedule a notification in the browser rather than sending email.

**Pros**:

* Avoids sending email and can provide a direct in browser alert.
* Does not require the app's mail transport.

**Cons**:

* Delivery depends on browser permissions, device availability, and local notification support.
* It does not meet the request for an email that works while the notes page is closed.

## Decision

**Chosen option**: Option 1: Store one reminder on each note and send email through the app.

Use the existing Laravel mail transport, database queue, and scheduler. Keep the first version on the authenticated online notes page, with no offline sync support. Use a generation UUID to separate schedule cycles and skip stale jobs. This reuses the app's existing operations and keeps reminder data out of note content, backups, and offline sync.

**Implementation skills**: `laravel-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/laravel-best-practices/`) · `livewire-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/livewire-development/`) · `fluxui-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/fluxui-development/`) · `tailwindcss-development` (`pedrojrsolatorio/notes-app`, `.agents/skills/tailwindcss-development/`) · `testing-best-practices` (`pedrojrsolatorio/notes-app`, `.agents/skills/testing-best-practices/`)

## Rationale

The app already uses a relational note model, Livewire actions, a database queue, and scheduled commands. Keeping one reminder on the note avoids a second entity for a single value while still supporting asynchronous delivery. Email meets the need for an alert when the browser is closed.

The schedule token, owner scoped lookups, generic email, and explicit Trash behavior address the main privacy and stale delivery risks. A separate table would add lifecycle work without a current need for history or multiple reminders. Browser notifications would not reliably reach you when the device or browser is unavailable.

## Feature design

**Data model sketch**:

| Entity | Key and relationship | Fields and constraints |
|---|---|---|
| User, existing | One User has many Notes | Existing primary key and email fields. Delivery uses the current email and requires `email_verified_at` to be set. |
| Note, existing | One Note belongs to one User | Existing primary key and `user_id`. Add nullable UTC `reminder_at` datetime, nullable `reminder_status` string with values `scheduled`, `queued`, or `failed`, and nullable `reminder_generation` UUID. Add an index on `reminder_status` and `reminder_at` for due reminder lookup. |

There is no Reminder entity or reminder history. If there is no active reminder, all three new fields are null. An active reminder has a time, a state, and a generation token. A note's existing soft delete behavior (keeping a deleted note in Trash without removing its row) applies. Due reminder lookup includes soft deleted notes so the dispatcher can clear reminders that expire in Trash.

**State transitions**:

* No reminder to scheduled when a valid future time is saved.
* Scheduled to queued when the due reminder is claimed for delivery.
* Queued to queued for the first and second failed mail attempts, then to failed after the third failure.
* Queued to failed without sending if the current owner email is no longer verified at due time.
* Queued to no reminder after the mail transport accepts delivery.
* Failed to queued when Retry now is selected and the account email is verified.
* Scheduled, queued, or failed to scheduled when a different future time is saved. The new schedule has a new generation token.
* Scheduled, queued, or failed to no reminder when cleared.
* A reminder stays scheduled while its note is in Trash. If it becomes due in Trash, the dispatcher clears it. Restoring before the due time resumes it. Restoring after the due time clears it instead of sending a late email.

**API surface**:

| Endpoint or action | Method | Key inputs | Key outputs | Auth | Key errors |
|---|---|---|---|---|---|
| `notes.index?note=<portable_id>` | GET | Optional note portable ID | Opens the owner scoped note, or the notes list with an unavailable notice | Signed in and verified | Unavailable note, no note data disclosed |
| `saveReminder` Livewire action | Livewire | Selected note ID, ISO 8601 UTC time | Persisted reminder state and toast | Signed in and verified | Invalid or past time, note not owned, note in Trash |
| `clearReminder` Livewire action | Livewire | Selected note ID | Cleared reminder state and toast | Signed in and verified | Note not owned |
| `retryReminder` Livewire action | Livewire | Selected note ID | Queued state and toast | Signed in and verified | No failed reminder, email not verified, note in Trash |
| Due reminder dispatcher | Scheduled task | Current UTC time | Queued mail jobs, or clears due reminders for notes in Trash | Server process | Queue or database failure is reported; reminder remains scheduled if queue insertion fails |
| Reminder mail job | Queue job | Note ID and generation UUID | Sends generic mail and clears reminder fields after transport acceptance | Server process | Stale generation, unavailable note, Trash, unverified email, or mail failure |

The dispatcher atomically claims each due reminder and inserts its database queue job in the same transaction. Use one unique queued job for each note and generation. If queue insertion fails, the transaction leaves the reminder scheduled for a later scheduler run. Immediately before mail handoff, the job locks and rechecks the note, due time, status, current user email verification, and generation. This final check is the send boundary. A clear or schedule change committed before it prevents sending. Once the check passes and mail handoff begins, a later change cannot recall the message. Do not hold a database lock during network delivery. The job only updates state if its generation still matches. A failed mail attempt keeps the current generation for its automatic retry cycle. Manual retry and a changed schedule receive a new generation. A transport may accept a message and then report an uncertain failure; a retry can send a duplicate, which is an accepted tradeoff.

The email link uses the note's portable ID in the existing authenticated `notes.index` route. The portable ID identifies the note but grants no access. The route and Livewire actions still resolve the note through the signed in user's notes relation.

**Value sourcing**:

| Action or read | Value produced or displayed | Source |
|---|---|---|
| Open reminder modal | Current reminder time | `Note.reminder_at`, converted from UTC to the browser's current local time zone |
| Open reminder modal for a note without a reminder | Blank date and time field | No active reminder, indicated by all three reminder fields being null |
| Save reminder | UTC reminder instant | Browser local date and time input, resolved using the browser time zone and sent as ISO 8601 UTC |
| Save or change reminder | `scheduled` state and generation token | Server sets the state and generates a UUID; never accepted from client input |
| Show reminder in note list | Local time and current state | `Note.reminder_at` converted to the browser's current time zone, and `Note.reminder_status` |
| Dispatch due reminder | Due note and generation | Indexed query including soft deleted notes, using `reminder_status`, `reminder_at`, and current UTC time |
| Address reminder email | Recipient and verification | The note's current owner, `User.email`, and `User.email_verified_at` at send time |
| Build email link | Note destination | `Note.portable_id` in the `notes.index` query string |
| Open email link | Note content or unavailable notice | Query portable ID resolved through the current user's active notes relation |
| Retry reminder | New attempt cycle | Current failed reminder on the selected owner scoped note; server creates a new generation UUID |
| Complete reminder | Cleared state | A successful mail transport handoff clears all three reminder fields |
| Retry a failed mail job | Next attempt time and failed state | The queue's attempt metadata for the current generation and the original `Note.reminder_at`, with attempts at the due instant, one minute later, and five minutes after it; no database retry counter. Failure state changes only if the generation still matches. |
| Show action feedback | Toast, inline error, or available action | Action result, validation errors, `Note.reminder_status`, and whether the note is in Trash |
| Show reminder time | Localized date and time at minute precision | `Note.reminder_at` and the browser's current locale and time zone |
| Show an unavailable link notice | Generic unavailable notice | A missing, trashed, permanently deleted, or non owned note after owner scoped lookup |

**Key invariants**:

* The database stores UTC instants. The user's current browser time zone is used only for local entry and display.
* A reminder is server controlled. The client cannot choose its status or generation token.
* A reminder job must match the current generation and recheck active note ownership and state before sending.
* Claiming and queuing a reminder is atomic. The job locks and rechecks the current generation at the send boundary, then releases the lock before network delivery.
* Reminder only updates do not change the note's content modification time or sync version and do not write a sync change record.
* Note backup and restore do not read or write reminder fields.
* The mail body and subject contain no note title or content. Successful delivery clears the reminder without an application level history record.

**Security model**:

Only a signed in, verified user may create, change, retry, or clear reminders on notes they own. Resolve notes through the authenticated user's notes relation for every action and route read. The job uses the note's current owner and current verified email address at send time. An email link requires authentication and ownership; its portable ID is not an authorization token. Do not expose note titles, contents, email addresses, or mail body content in reminder status messages or failure logs.

**Configuration required**:

No new environment variables, credentials, or packages are required. The feature uses the existing Laravel mail configuration, database queue, and scheduler. The development mailer defaults to `log`, which records messages rather than delivering them to an inbox. A real delivery check needs a working mail transport, a running scheduler, and a queue worker.

**Critical test scenarios**:

* Happy path: set a local time, dispatch the reminder, send only generic email to the current verified address, open the selected note after sign in, and clear reminder state after transport acceptance. Verifies **AC-1**, **AC-2**, **AC-4**, **AC-5**, **AC-6**, and **AC-7**.
* Time validation: reject a past time and a nonexistent spring daylight saving time, and choose the earlier repeated autumn time. Verifies **AC-2** and **AC-3**.
* Retry and stale job: exercise attempts at the due time, one minute later, and five minutes later; verify the failed state, manual retry, and that an older generation cannot send or fail a newer schedule. Verifies **AC-8** and **AC-9**.
* Trash and recovery: pause a reminder in Trash, clear it without mail if it becomes due there, resume it after an early restore, clear it after a late restore, and deliver an active due reminder after scheduler or worker recovery. Verifies **AC-4** and **AC-10**.
* Auth and privacy: reject cross owner actions, do not send to an unverified address, and ensure the email link never reveals an unavailable note. Verifies **AC-5**, **AC-6**, and **AC-11**.
* Data boundaries: verify reminder changes create no sync record and backup export or restore does not include or create reminder data. Verifies **AC-12** and **AC-13**.

## Build plan

The project uses Tracer Bullet delivery, so the first slice should complete one thin path through the note model, notes page, scheduler, queue, and email. The remaining work then thickens that path with failure handling and the privacy boundaries.

1. Build the first thin end to end path: add the nullable reminder fields and due lookup index, the modal and note list, local time conversion and validation, the every minute dispatcher, the queued email, and the authenticated portable ID link. Add focused tests for scheduling, delivery, and opening the note. Satisfies **AC-1**, **AC-2**, **AC-3**, **AC-4**, **AC-5**, **AC-6**, and **AC-7**.
2. Thicken the path with atomic claims, queue failure recovery, generation checks, the three attempt times, failed state actions, current email verification, Trash pause and expiry, and reminder only updates that do not alter note timestamps or create sync records. Add tests for stale jobs, retries, Trash, recovery, ownership, and sync isolation. Satisfies **AC-4**, **AC-5**, **AC-8**, **AC-9**, **AC-10**, **AC-11**, and **AC-13**.
3. Verify backup export and restore ignore all reminder fields, then complete the Beta workflow with focused tests and an in app check using a working mail transport, scheduler, and queue worker. Satisfies **AC-12**.

## Consequences

**Positive**:

* You can get a reminder when the browser is closed.
* A single note record holds the current reminder state, and stale jobs cannot affect a newer cycle.
* Note text, backup contents, and offline sync remain separate from reminders.

**Negative / tradeoffs**:

* Delivery depends on the scheduler, database queue, queue worker, and configured mail transport. The one minute target is not met while those services are stopped.
* Reminders are not visible or editable in the offline client.
* A mail transport acceptance is treated as delivery. Later bounces or inbox placement are not tracked, and an uncertain transport failure can result in a duplicate email because reliable delivery is preferred.
* A clear or schedule change cannot recall an email after its final job check and mail handoff have begun.

**Neutral**:

* The migration adds three nullable columns and a due lookup index. Existing notes have no reminder.
* There is no sent reminder history. After successful handoff, the reminder fields are cleared.
* The local `log` mailer does not deliver real email. A working mail configuration is needed for an inbox check.

## Follow-up

* [ ] Add `laravel-best-practices` conventions to root `AGENTS.md` under `## Agent skills`, because they apply across Laravel code in the project.
* [ ] Add `testing-best-practices` conventions to root `AGENTS.md` under `## Agent skills`, because they apply across the test suite.
* [ ] Add `livewire-development` conventions to `resources/views/components/notes/AGENTS.md`, then add a root `AGENTS.md` pointer to that area file.
* [ ] Add `fluxui-development` conventions to `resources/views/components/notes/AGENTS.md`, then add a root `AGENTS.md` pointer to that area file.
* [ ] Add `tailwindcss-development` conventions to `resources/views/components/notes/AGENTS.md`, then add a root `AGENTS.md` pointer to that area file.
* [ ] Configure a real mail transport and run the in app delivery check with the scheduler and queue worker available.
