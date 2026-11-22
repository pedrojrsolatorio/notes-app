import assert from "node:assert/strict";
import test from "node:test";
import { resolveIdentityForSync } from "../../resources/js/offline-identity.js";

test("keeps the current account when the app is offline", () => {
    assert.deepEqual(
        resolveIdentityForSync({ currentUserId: 1, probedUserId: undefined, online: false }),
        { status: "offline", userId: 1 },
    );
});

test("does not sync when the account probe cannot be confirmed", () => {
    assert.deepEqual(
        resolveIdentityForSync({ currentUserId: 1, probedUserId: undefined }),
        { status: "unknown", userId: 1 },
    );
});

test("requires sign in when the session is signed out", () => {
    assert.deepEqual(
        resolveIdentityForSync({ currentUserId: 1, probedUserId: null }),
        { status: "signed-out", userId: null },
    );
});

test("reports a different signed in account before syncing", () => {
    assert.deepEqual(
        resolveIdentityForSync({ currentUserId: 1, probedUserId: 2 }),
        { status: "changed", userId: 2 },
    );
});

test("keeps the confirmed account when the session did not change", () => {
    assert.deepEqual(
        resolveIdentityForSync({ currentUserId: 1, probedUserId: 1 }),
        { status: "same", userId: 1 },
    );
});
