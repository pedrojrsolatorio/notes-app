process.env.TZ = "America/New_York";

import assert from "node:assert/strict";
import test from "node:test";
import { formatReminderTime, localDateTimeToUtc, utcToLocalDateTime } from "../../resources/js/note-reminder-time.js";

test("[AC-2] converts local minute values to UTC with zero seconds and milliseconds", () => {
    assert.equal(localDateTimeToUtc("2026-11-14T12:34"), "2026-11-14T17:34:00.000Z");
});

test("[AC-3] rejects a local time missing during the spring daylight saving change", () => {
    assert.equal(localDateTimeToUtc("2026-03-08T02:30"), null);
});

test("[AC-3] chooses the earlier UTC instant for an ambiguous autumn local time", () => {
    assert.equal(localDateTimeToUtc("2026-11-01T01:30"), "2026-11-01T05:30:00.000Z");
});

test("[AC-2] formats a stored UTC instant in the browser local time zone", () => {
    assert.equal(utcToLocalDateTime("2026-11-14T17:34:00.000Z"), "2026-11-14T12:34");
    assert.match(formatReminderTime("2026-11-14T17:34:00.000Z"), /Nov 14, 2026/);
    assert.equal(utcToLocalDateTime(null), "");
});
