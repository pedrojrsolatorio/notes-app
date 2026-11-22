import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const source = readFileSync(
    new URL("../../resources/js/offline-app.js", import.meta.url),
    "utf8",
);

function extractFunction(source, signature) {
    const start = source.indexOf(signature);

    assert.notEqual(start, -1, `Could not find ${signature}`);

    let index = source.indexOf("(", start + signature.length);
    let depth = 0;

    for (; index < source.length; index += 1) {
        if (source[index] === "(") {
            depth += 1;
        } else if (source[index] === ")") {
            depth -= 1;

            if (depth === 0) {
                index += 1;
                break;
            }
        }
    }

    let opened = false;
    depth = 0;

    for (; index < source.length; index += 1) {
        if (source[index] === "{") {
            depth += 1;
            opened = true;
            continue;
        }

        if (source[index] !== "}") {
            continue;
        }

        depth -= 1;

        if (opened && depth === 0) {
            return source.slice(start, index + 1);
        }
    }

    throw new Error(`Could not find the end of ${signature}`);
}

const syncNow = extractFunction(source, "async function syncNow");

test("syncNow never references an undefined db variable", () => {
    assert.doesNotMatch(syncNow, /(?<![\w.])db\./);
});

test("syncNow reads outbox rows through runtime.db", () => {
    assert.match(syncNow, /runtime\.db\.outbox/);
});

const hasPending = extractFunction(source, "async function hasPending");

function fakeOutbox(rows) {
    const collection = {
        toArray: async () => rows,
    };

    return {
        outbox: {
            where: () => ({ equals: () => collection }),
        },
    };
}

test("hasPending resolves the Dexie collection before checking rows", async () => {
    const check = new Function(`${hasPending}; return hasPending;`)();
    const db = fakeOutbox([
        { entity_type: "note", entity_portable_id: "note-1", user_id: 1 },
    ]);

    assert.equal(await check(db, 1, "note", "note-1"), true);
    assert.equal(await check(db, 1, "note", "note-2"), false);
    assert.equal(await check(db, 1, "folder", "note-1"), false);
});
