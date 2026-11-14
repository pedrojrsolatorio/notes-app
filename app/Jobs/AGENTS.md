# Reminder Queue Job

- `SendNoteReminder.php` is dispatched by `DispatchDueNoteReminders.php`, which runs every minute from `routes/console.php`.
- A job is valid only for its note and reminder generation. Stale generations must not send mail or update a newer reminder.
- Deliver only for an active, due note and the owner's current verified email address.
- Keep the three attempt schedule and generic mail boundary in [0005-note-reminders.md](../../docs/specs/0005-note-reminders.md).

_Drafted by /sync from the introducing change, worth a quick human pass._
