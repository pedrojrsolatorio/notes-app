function toLocalDateTimeValue(date) {
    const pad = (value) => String(value).padStart(2, "0");

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function localDateTimeToUtc(value) {
    if (typeof value !== "string" || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime()) || toLocalDateTimeValue(date) !== value) {
        return null;
    }

    date.setSeconds(0, 0);

    return date.toISOString();
}

export function utcToLocalDateTime(value) {
    if (typeof value !== "string" || value === "") {
        return "";
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return "";
    }

    return toLocalDateTimeValue(date);
}

export function formatReminderTime(value) {
    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return "";
    }

    return new Intl.DateTimeFormat(undefined, {
        dateStyle: "medium",
        timeStyle: "short",
    }).format(date);
}
