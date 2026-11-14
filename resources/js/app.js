import { createTiptapEditor, createTiptapComponent } from "./tiptap";
import "./note-backup";
import { formatReminderTime, localDateTimeToUtc, utcToLocalDateTime } from "./note-reminder-time";

window.createTiptapEditor = createTiptapEditor;
window.createTiptapComponent = createTiptapComponent;
window.noteReminderTime = { formatReminderTime, localDateTimeToUtc, utcToLocalDateTime };
