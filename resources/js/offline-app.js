import Dexie from "dexie";
import { resolveIdentityForSync } from "./offline-identity.js";
import { createTiptapComponent } from "./tiptap.js";

// ===========================================================================
// Identity
// ===========================================================================

const LAST_USER_KEY = "last_user_id";

function lastUserId() {
    const value = localStorage.getItem(LAST_USER_KEY);
    return value ? Number(value) : null;
}

function rememberUserId(userId) {
    if (userId) {
        localStorage.setItem(LAST_USER_KEY, String(userId));
    }
}

function forgetUserId() {
    localStorage.removeItem(LAST_USER_KEY);
}

function pageUserId() {
    const value = document.body?.dataset?.userId;
    return value ? Number(value) : null;
}

async function fetchPageUserId() {
    try {
        const response = await fetch("/app", {
            credentials: "same-origin",
            headers: { "Cache-Control": "no-cache" },
        });

        if (!response.ok) {
            return undefined;
        }

        const text = await response.text();
        const match = text.match(/data-user-id="(\d+)"/);

        return match ? Number(match[1]) : null;
    } catch {
        return undefined;
    }
}

// ===========================================================================
// Store (per user database)
// ===========================================================================

const databases = new Map();

function openDb(userId) {
    if (databases.has(userId)) {
        return databases.get(userId);
    }

    const db = new Dexie(`notes-offline-${userId}`);

    db.version(1).stores({
        notes: "portable_id, [user_id+folder_portable_id], [user_id+sync_state], [user_id+updated_at], [user_id+deleted_at]",
        folders: "portable_id, [user_id+sync_state], [user_id+updated_at]",
        outbox: "client_change_id, user_id, [user_id+created_at]",
        sync_meta: "key",
    });

    db.version(2).stores({
        notes: "portable_id, [user_id+folder_portable_id], [user_id+sync_state], [user_id+updated_at], [user_id+deleted_at], [user_id+updated_at+portable_id], [user_id+folder_portable_id+updated_at+portable_id], [user_id+deleted_at+updated_at+portable_id], [user_id+title_key+portable_id]",
        folders: "portable_id, [user_id+sync_state], [user_id+updated_at]",
        outbox: "client_change_id, user_id, [user_id+created_at]",
        sync_meta: "key",
    }).upgrade((transaction) => transaction.table("notes").toCollection().modify((note) => {
        note.title_key = note.title_key ?? titleKeyFor(note.title ?? "");
    }));

    databases.set(userId, db);

    return db;
}

async function metaGet(db, key, fallback = null) {
    const row = await db.sync_meta.get(key);
    return row ? row.value : fallback;
}

async function metaSet(db, key, value) {
    await db.sync_meta.put({ key, value });
}

async function pendingCount(db, userId) {
    return db.outbox.where("user_id").equals(userId).count();
}

async function hasPending(db, userId, entityType, portableId) {
    const rows = await db.outbox.where("user_id").equals(userId).toArray();

    return rows.some(
        (row) => row.entity_type === entityType && row.entity_portable_id === portableId,
    );
}

// ===========================================================================
// Outbox
// ===========================================================================

function pushOrder(row) {
    if (row.entity_type === "folder" && row.action === "created") {
        return 0;
    }

    if (row.entity_type === "note") {
        return 1;
    }

    if (row.entity_type === "folder" && row.action === "updated") {
        return 2;
    }

    return 3;
}

async function queueOutbox(db, userId, { entity_type, entity_portable_id, action, payload, base_version }) {
    return db.transaction("rw", db.outbox, async () => {
        const matches = await db.outbox
            .where("user_id")
            .equals(userId)
            .filter((row) => row.entity_type === entity_type && row.entity_portable_id === entity_portable_id)
            .toArray();

        // Rows currently being pushed are immutable; newer edits queue separately.
        const existing = matches.find((row) => row.status !== "sending");

        if (existing) {
            // The newest action wins; an unsynced create always stays a create.
            const nextAction = existing.action === "created" ? "created" : action;

            await db.outbox.update(existing.client_change_id, {
                action: nextAction,
                payload,
            });

            return existing.client_change_id;
        }

        const row = {
            client_change_id: crypto.randomUUID(),
            user_id: userId,
            entity_type,
            entity_portable_id,
            action,
            payload,
            base_version,
            attempts: 0,
            last_error: null,
            created_at: new Date().toISOString(),
        };

        await db.outbox.put(row);

        return row.client_change_id;
    });
}

async function dropOutbox(db, clientChangeId) {
    await db.outbox.delete(clientChangeId);
}

// ===========================================================================
// HTTP
// ===========================================================================

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

async function fetchSync(path, options = {}) {
    const attempt = async (token) => fetch(path, {
        credentials: "same-origin",
        redirect: "manual",
        ...options,
        headers: {
            Accept: "application/json",
            "X-Requested-With": "XMLHttpRequest",
            ...(token ? { "X-CSRF-TOKEN": token } : {}),
            ...(options.headers ?? {}),
        },
    });

    let response = await attempt(csrfToken());

    if (response.status === 419) {
        // Stale token: refresh it from the shell once, then retry once.
        const html = await (await fetch("/app", { credentials: "same-origin", headers: { "Cache-Control": "no-cache" } })).text();
        const match = html.match(/name="csrf-token" content="([^"]+)"/);

        if (match) {
            const meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) {
                meta.content = match[1];
            }
        }

        response = await attempt(match?.[1] ?? csrfToken());
    }

    if (response.status === 401 || (response.status >= 300 && response.status < 400) || response.status === 419) {
        throw new NeedsSignInError();
    }

    return response;
}

async function readJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

// ===========================================================================
// Sync engine
// ===========================================================================

const runtime = {
    userId: null,
    db: null,
    online: navigator.onLine,
    syncing: false,
    needsSignIn: false,
    accountChanged: false,
    lastError: null,
    nextRetryAt: 0,
    notices: [],
};

function setRuntime(patch) {
    Object.assign(runtime, patch);
    renderStatus();
}

async function pull(db, userId) {
    let since = (await metaGet(db, "last_revision", 0)) ?? 0;

    for (;;) {
        const url = `/sync?since=${since}&limit=200`;
        const response = await fetchSync(url, { method: "GET" });

        if (response.status === 410) {
            // Our cursor is older than the retained history. Resnapshot.
            await db.transaction("rw", [db.notes, db.folders], async () => {
                await db.notes.clear();
                await db.folders.clear();
            });
            since = 0;
            continue;
        }

        if (response.status !== 200) {
            const error = new Error(`pull failed (${response.status})`);
            const retryAfter = Number(response.headers.get("Retry-After") ?? 0) * 1000;
            error.status = response.status;
            error.retryAt = response.status === 429 && retryAfter > 0
                ? Date.now() + retryAfter
                : Date.now() + backoffDelay(1);

            throw error;
        }

        const data = await readJson(response);

        if (!data) {
            throw new Error("pull returned no data");
        }

        const pendingNotes = new Set();
        const pendingFolders = new Map();

        for (const row of await db.outbox.where("user_id").equals(userId).toArray()) {
            if (row.entity_type === "note") {
                pendingNotes.add(row.entity_portable_id);
                continue;
            }

            const rows = pendingFolders.get(row.entity_portable_id) ?? [];
            rows.push(row);
            pendingFolders.set(row.entity_portable_id, rows);
        }

        await db.transaction("rw", [db.notes, db.folders, db.sync_meta, db.outbox], async () => {
            for (const note of data.notes ?? []) {
                if (pendingNotes.has(note.portable_id)) {
                    continue;
                }

                await db.notes.put({
                    portable_id: note.portable_id,
                    user_id: userId,
                    folder_portable_id: note.folder_portable_id ?? null,
                    title: note.title,
                    title_key: note.title_key ?? titleKeyFor(note.title),
                    content: note.content ?? "",
                    version: note.version,
                    deleted_at: note.deleted_at ?? null,
                    created_at: note.created_at,
                    updated_at: note.updated_at,
                    attachments: note.attachments ?? [],
                    sync_state: "synced",
                    last_synced_at: new Date().toISOString(),
                });
            }

            for (const folder of data.folders ?? []) {
                const pending = pendingFolders.get(folder.portable_id) ?? [];
                const pendingRename = pending.find((row) => row.action === "updated");

                if (pending.length > 0 && (!pendingRename || folder.version <= pendingRename.base_version)) {
                    // A still-valid local folder change wins until it is pushed.
                    continue;
                }

                await db.folders.put({
                    portable_id: folder.portable_id,
                    user_id: userId,
                    name: folder.name,
                    version: folder.version,
                    updated_at: folder.updated_at,
                    sync_state: "synced",
                    last_synced_at: new Date().toISOString(),
                });

                if (pendingRename) {
                    // A stale rename loses: apply the server state.
                    await db.outbox.where("user_id").equals(userId)
                        .filter((row) => row.entity_type === "folder" && row.entity_portable_id === folder.portable_id)
                        .delete();

                    notice(`The folder "${folder.name}" was renamed on another device.`);
                }
            }

            for (const portableId of data.deleted_note_portable_ids ?? []) {
                if (pendingNotes.has(portableId)) {
                    continue;
                }

                await db.notes.delete(portableId);
            }

            for (const portableId of data.deleted_folder_portable_ids ?? []) {
                const wasPending = pendingFolders.has(portableId);

                if (wasPending) {
                    // A server delete always wins over a pending folder change.
                    await db.outbox.where("user_id").equals(userId)
                        .filter((row) => row.entity_type === "folder" && row.entity_portable_id === portableId)
                        .delete();

                    notice("A folder was deleted on another device. Its notes moved to All Notes.");
                }

                await db.folders.delete(portableId);
                await db.notes.where("folder_portable_id").equals(portableId)
                    .modify((note) => {
                        note.folder_portable_id = null;
                    });
            }

            await metaSet(db, "last_revision", data.revision);
            await metaSet(db, "last_sync_at", new Date().toISOString());
        });

        if (!data.has_more) {
            break;
        }

        since = data.revision;
    }
}

async function push(db, userId) {
    const queued = await db.outbox.where("user_id").equals(userId).toArray();

    if (queued.length === 0) {
        return { applied: 0, conflicts: 0, rejected: 0 };
    }

    const ordered = [...queued].sort((a, b) => {
        const byGroup = pushOrder(a) - pushOrder(b);
        return byGroup !== 0 ? byGroup : a.created_at.localeCompare(b.created_at);
    });

    const changes = ordered.slice(0, 100).map((row) => ({
        client_change_id: row.client_change_id,
        entity_type: row.entity_type,
        action: row.action,
        portable_id: row.entity_portable_id,
        base_version: row.base_version,
        payload: row.payload ?? {},
    }));

    let data;

    for (const row of ordered.slice(0, 100)) {
        await db.outbox.update(row.client_change_id, { status: "sending" });
    }

    try {
        const response = await fetchSync("/sync", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ changes }),
        });

        if (response.status !== 200) {
            throw new Error(`push failed (${response.status})`);
        }

        data = await readJson(response);

        if (!data) {
            throw new Error("push returned no data");
        }
    } catch (error) {
        for (const row of ordered.slice(0, 100)) {
            await db.outbox.update(row.client_change_id, {
                status: "queued",
                attempts: (row.attempts ?? 0) + 1,
                last_error: error.message ?? String(error),
            });
        }

        const status = error.status ?? 0;
        const retryAfter = Number(error.retryAfter ?? 0) * 1000;
        error.retryAt = Date.now() + (status === 429 && retryAfter > 0 ? retryAfter : backoffDelay(1));

        throw error;
    }

    const summary = { applied: 0, conflicts: 0, rejected: 0 };

    for (const result of data.results ?? []) {
        const row = await db.outbox.get(result.client_change_id);

        if (!row) {
            continue;
        }

        if (result.status === "applied") {
            summary.applied += 1;
            if (result.note) {
                await applyServerNote(db, userId, result.note);
            }
            if (result.folder) {
                await applyServerFolder(db, userId, result.folder);
            }
        } else if (result.status === "conflict") {
            summary.conflicts += 1;
            if (result.note) {
                await applyServerNote(db, userId, result.note);
            }
            if (result.conflict_note) {
                await insertServerNote(db, userId, result.conflict_note);
                notice("That note changed on another device. Your offline version was kept as a conflict copy.");
            }
        } else {
            summary.rejected += 1;
            const entity = row.entity_type === "note"
                ? await db.notes.get(row.entity_portable_id)
                : await db.folders.get(row.entity_portable_id);

            if (entity) {
                await db[row.entity_type === "note" ? "notes" : "folders"].update(row.entity_portable_id, {
                    sync_state: "conflict",
                });
            }

            if (row.entity_type === "folder" && result.folder) {
                // A stale folder change loses: apply the server state.
                await applyServerFolder(db, userId, result.folder);
            }

            notice(result.message ?? "A change was refused by the server. Your local copy is kept.");
        }

        await dropOutbox(db, result.client_change_id);
    }

    return summary;
}

async function applyServerNote(db, userId, note) {
    const pending = await hasPending(db, userId, "note", note.portable_id);
    const local = await db.notes.get(note.portable_id);

    // Never clobber newer local edits that are still queued.
    if (pending && local) {
        await db.notes.update(note.portable_id, { version: note.version });
        return;
    }

    await insertServerNote(db, userId, note);
}

async function insertServerNote(db, userId, note) {
    await db.notes.put({
        portable_id: note.portable_id,
        user_id: userId,
        folder_portable_id: note.folder_portable_id ?? null,
        title: note.title,
        title_key: note.title_key ?? titleKeyFor(note.title),
        content: note.content ?? "",
        version: note.version,
        deleted_at: note.deleted_at ?? null,
        created_at: note.created_at,
        updated_at: note.updated_at,
        attachments: note.attachments ?? [],
        sync_state: "synced",
        last_synced_at: new Date().toISOString(),
    });
}

async function applyServerFolder(db, userId, folder) {
    const pending = await hasPending(db, userId, "folder", folder.portable_id);

    if (pending) {
        await db.folders.update(folder.portable_id, { version: folder.version });
        return;
    }

    await db.folders.put({
        portable_id: folder.portable_id,
        user_id: userId,
        name: folder.name,
        version: folder.version,
        updated_at: folder.updated_at,
        sync_state: "synced",
        last_synced_at: new Date().toISOString(),
    });
}

function backoffDelay(attempts) {
    return Math.min(60_000, 1_000 * 2 ** Math.min(attempts, 6));
}

async function verifyCurrentUser() {
    const decision = resolveIdentityForSync({
        currentUserId: runtime.userId,
        probedUserId: await fetchPageUserId(),
        online: true,
    });

    if (decision.status === "offline") {
        return true;
    }

    if (decision.status === "unknown") {
        setRuntime({ lastError: "Could not confirm the signed in account." });
        return false;
    }

    if (decision.status === "signed-out") {
        forgetUserId();
        setRuntime({ needsSignIn: true, accountChanged: false, lastError: null });
        renderSignIn();
        return false;
    }

    if (decision.status === "changed") {
        setRuntime({ needsSignIn: false, accountChanged: true, lastError: null });
        notice("The signed in account changed. Reload before syncing.");
        return false;
    }

    setRuntime({ needsSignIn: false, accountChanged: false, lastError: null });
    return true;
}

async function syncNow({ manual = false, verifyIdentity = true } = {}) {
    if (runtime.syncing || runtime.accountChanged || runtime.userId === null || runtime.db === null) {
        return;
    }

    if (!runtime.online || (!manual && Date.now() < runtime.nextRetryAt)) {
        return;
    }

    if (verifyIdentity && ! await verifyCurrentUser()) {
        return;
    }

    const run = async () => {
        setRuntime({ syncing: true, lastError: null });

        try {
            const previousRevision = Number(await metaGet(runtime.db, "last_revision", 0) ?? 0);
            const summary = await push(runtime.db, runtime.userId);
            await pull(runtime.db, runtime.userId);
            const currentRevision = Number(await metaGet(runtime.db, "last_revision", 0) ?? 0);

            if (
                currentRevision > previousRevision
                && uiState.lastRefreshRevision !== currentRevision
            ) {
                uiState.lastRefreshRevision = currentRevision;
                await resetOfflineList({
                    focusHeading: false,
                    statusMessage: "Your notes list was refreshed.",
                });
            }

            if (summary.rejected > 0 || summary.conflicts > 0) {
                renderAll();
            }

            setRuntime({ syncing: false, needsSignIn: false, nextRetryAt: 0 });
            renderAll();
        } catch (error) {
            setRuntime({ syncing: false });

            if (error instanceof NeedsSignInError) {
                setRuntime({
                    needsSignIn: true,
                    lastError: null,
                    nextRetryAt: 0,
                });
            } else {
                const maxAttempts = await runtime.db.outbox
                    .where("user_id")
                    .equals(runtime.userId)
                    .toArray()
                    .then((rows) => rows.reduce((max, row) => Math.max(max, row.attempts ?? 0), 0));

                setRuntime({
                    lastError: error.message ?? String(error),
                    nextRetryAt: error.retryAt ?? Date.now() + backoffDelay(maxAttempts + 1),
                });
            }
            renderStatus();
        }
    };

    if (navigator.locks) {
        await navigator.locks.request("notes-sync-loop", run);
    } else {
        await run();
    }
}

class NeedsSignInError extends Error {}

// ===========================================================================
// Local mutations
// ===========================================================================

function nowIso() {
    return new Date().toISOString();
}

async function saveNote(portableId, { title, content, folder_portable_id }) {
    const db = runtime.db;
    const userId = runtime.userId;
    const local = await db.notes.get(portableId);
    const synced = local?.last_synced_at != null;
    const baseVersion = local?.version ?? 0;

    await db.notes.put({
        ...(local ?? {}),
        portable_id: portableId,
        user_id: userId,
        title,
        title_key: titleKeyFor(title),
        content,
        folder_portable_id,
        version: baseVersion,
        deleted_at: local?.deleted_at ?? null,
        created_at: local?.created_at ?? nowIso(),
        updated_at: nowIso(),
        attachments: local?.attachments ?? [],
        sync_state: "pending",
        last_synced_at: local?.last_synced_at ?? null,
    });

    await queueOutbox(db, userId, {
        entity_type: "note",
        entity_portable_id: portableId,
        action: synced ? "updated" : "created",
        payload: { title, content, folder_portable_id },
        base_version: baseVersion,
    });

    await resetOfflineList({ focusHeading: false });
    renderAll();
    syncNow();
}

async function trashNote(portableId) {
    const db = runtime.db;
    const userId = runtime.userId;
    const local = await db.notes.get(portableId);

    if (!local) {
        return;
    }

    uiState.selectedNoteId = null;
    uiState.mobileView = "list";
    uiState.focusNoteId = null;
    uiState.focusListHeading = true;

    if (local.last_synced_at == null) {
        await db.notes.delete(portableId);
        await db.outbox.where("user_id").equals(userId)
            .filter((row) => row.entity_type === "note" && row.entity_portable_id === portableId)
            .delete();
        await resetOfflineList();
        renderAll();
        syncNow();
        return;
    }

    await db.notes.update(portableId, {
        deleted_at: nowIso(),
        updated_at: nowIso(),
        sync_state: "pending",
    });

    await queueOutbox(db, userId, {
        entity_type: "note",
        entity_portable_id: portableId,
        action: "deleted",
        payload: {},
        base_version: local.version,
    });

    await resetOfflineList();
    renderAll();
    syncNow();
}

async function restoreNote(portableId) {
    const db = runtime.db;
    const userId = runtime.userId;
    const local = await db.notes.get(portableId);

    if (!local) {
        return;
    }

    uiState.selectedNoteId = null;
    uiState.mobileView = "list";
    uiState.focusListHeading = true;

    await db.notes.update(portableId, {
        deleted_at: null,
        updated_at: nowIso(),
        sync_state: "pending",
    });

    await queueOutbox(db, userId, {
        entity_type: "note",
        entity_portable_id: portableId,
        action: "restored",
        payload: {},
        base_version: local.version,
    });

    await resetOfflineList();
    renderAll();
    syncNow();
}

async function purgeNote(portableId) {
    const db = runtime.db;
    const userId = runtime.userId;
    const local = await db.notes.get(portableId);

    if (!local) {
        return;
    }

    uiState.selectedNoteId = null;
    uiState.mobileView = "list";
    uiState.focusNoteId = null;
    uiState.focusListHeading = true;

    if (local.last_synced_at == null) {
        await db.notes.delete(portableId);
        await db.outbox.where("user_id").equals(userId)
            .filter((row) => row.entity_type === "note" && row.entity_portable_id === portableId)
            .delete();
        await resetOfflineList();
        renderAll();
        syncNow();
        return;
    }

    await db.notes.delete(portableId);

    await queueOutbox(db, userId, {
        entity_type: "note",
        entity_portable_id: portableId,
        action: "purged",
        payload: {},
        base_version: local.version,
    });

    await resetOfflineList();
    renderAll();
    syncNow();
}

async function createFolder(name) {
    const db = runtime.db;
    const userId = runtime.userId;
    const portableId = crypto.randomUUID();

    await db.folders.put({
        portable_id: portableId,
        user_id: userId,
        name,
        version: 0,
        updated_at: nowIso(),
        sync_state: "pending",
        last_synced_at: null,
    });

    await queueOutbox(db, userId, {
        entity_type: "folder",
        entity_portable_id: portableId,
        action: "created",
        payload: { name },
        base_version: 0,
    });

    syncNow();

    return portableId;
}

async function renameFolder(portableId, name) {
    const db = runtime.db;
    const userId = runtime.userId;
    const local = await db.folders.get(portableId);

    if (!local) {
        return;
    }

    await db.folders.update(portableId, { name, updated_at: nowIso(), sync_state: "pending" });

    await queueOutbox(db, userId, {
        entity_type: "folder",
        entity_portable_id: portableId,
        action: "updated",
        payload: { name },
        base_version: local.version,
    });

    renderAll();
    syncNow();
}

async function deleteFolder(portableId) {
    const db = runtime.db;
    const userId = runtime.userId;
    const local = await db.folders.get(portableId);

    if (!local) {
        return;
    }

    await db.transaction("rw", [db.folders, db.notes], async () => {
        await db.folders.delete(portableId);

        await db.notes.where("[user_id+folder_portable_id]").equals([userId, portableId])
            .modify((note) => {
                note.folder_portable_id = null;
            });
    });

    if (uiState.selectedNoteId) {
        uiState.focusFolderSelect = true;
    } else {
        uiState.focusListHeading = true;
    }

    if (local.last_synced_at == null) {
        await db.outbox.where("user_id").equals(userId)
            .filter((row) => row.entity_type === "folder" && row.entity_portable_id === portableId)
            .delete();
        syncNow();
        return;
    }

    await queueOutbox(db, userId, {
        entity_type: "folder",
        entity_portable_id: portableId,
        action: "deleted",
        payload: {},
        base_version: local.version,
    });

    syncNow();
}

// ===========================================================================
// Text helpers (same shapes as the server rules)
// ===========================================================================

const TRIM_RE = /^[\p{Z}\x09-\x0D\u0085\uFEFF]+|[\p{Z}\x09-\x0D\u0085\uFEFF]+$/gu;
const TITLE_KEY_TRIM_RE = /^[\p{Z}\x09-\x0D\u0085]+|[\p{Z}\x09-\x0D\u0085]+$/gu;

function titleKeyFor(title) {
    return title.replace(TITLE_KEY_TRIM_RE, "").toLowerCase();
}

function normalizeText(text) {
    return text.replace(/\r\n?/g, "\n").replace(TRIM_RE, "");
}

function escapeHtml(text) {
    return String(text)
        .replaceAll("&", "&amp;")
        .replaceAll("<", "&lt;")
        .replaceAll(">", "&gt;")
        .replaceAll('"', "&quot;")
        .replaceAll("'", "&#039;");
}

function decodeEntities(text) {
    const el = document.createElement("textarea");
    el.innerHTML = text;
    return el.value;
}

function plainText(html) {
    const decoded = decodeEntities(html ?? "");
    const stripped = decoded.replace(/<[^>]*>/g, "");
    return stripped.replace(/\s+/g, " ").trim();
}

function captureToNote(text) {
    const normalized = normalizeText(text);

    if (!normalized) {
        return null;
    }

    const newline = normalized.indexOf("\n");
    const first = newline === -1 ? normalized : normalized.slice(0, newline);
    const rest = newline === -1 ? "" : normalized.slice(newline + 1);
    const firstTrimmed = normalizeText(first);
    const codePoints = Array.from(firstTrimmed);

    const title = codePoints.slice(0, 255).join("");
    const overflow = codePoints.slice(255).join("");
    const body = normalizeText(overflow + (rest === "" ? "" : "\n" + rest));
    const content = body === "" ? "" : body.split("\n").map((line) => `<p>${escapeHtml(line)}</p>`).join("");

    return { title, content };
}

// ===========================================================================
// UI
// ===========================================================================

const root = document.getElementById("offline-app");

const uiState = {
    view: "all",
    folderId: null,
    search: "",
    sort: "newest",
    selectedNoteId: null,
    mobileView: "list",
    focusNoteId: null,
    focusEditorHeading: false,
    focusListHeading: false,
    focusLoadMore: false,
    focusFolderSelect: false,
    noteRows: [],
    notesCursor: null,
    hasMoreNotes: false,
    matchedNotesCount: null,
    notesLoading: false,
    listInitialized: false,
    listStatusMessage: "",
    listStatusMessageId: 0,
    pendingListStatusMessage: null,
    lastRefreshRevision: null,
    editor: null,
    editorToolbar: null,
    editorNoteId: null,
    editorMount: null,
    editorDeletedAt: null,
};

let searchTimeout = null;

function notice(message) {
    runtime.notices.push(message);
    if (runtime.notices.length > 5) {
        runtime.notices.shift();
    }
    renderNotices();
}

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(attrs)) {
        if (key === "class") {
            node.className = value;
        } else if (key === "text") {
            node.textContent = value;
        } else if (key.startsWith("on")) {
            node.addEventListener(key.slice(2), value);
        } else {
            node.setAttribute(key, value);
        }
    }

    for (const child of children) {
        if (child == null) {
            continue;
        }
        node.append(child.nodeType ? child : document.createTextNode(child));
    }

    return node;
}

function statusText() {
    if (runtime.accountChanged) {
        return "Account changed";
    }

    if (runtime.needsSignIn) {
        return "Needs sign in";
    }

    if (runtime.syncing) {
        return "Syncing…";
    }

    if (!runtime.online) {
        return "Offline";
    }

    if (runtime.lastError) {
        return "Sync error";
    }

    return "Online";
}

function renderNotices() {
    const container = document.getElementById("status-notices");

    if (!container) {
        return;
    }

    container.replaceChildren();

    for (const message of runtime.notices) {
        container.append(el("div", {
            class: "rounded-lg bg-amber-100 px-4 py-2 text-sm text-amber-900 dark:bg-amber-900 dark:text-amber-100",
            text: message,
        }));
    }
}

async function renderStatus() {
    const bar = document.getElementById("status-bar");

    if (!bar) {
        return;
    }

    let pending = 0;
    let lastSyncAt = null;

    if (runtime.db && runtime.userId !== null) {
        pending = await pendingCount(runtime.db, runtime.userId);
        lastSyncAt = await metaGet(runtime.db, "last_sync_at", null);
    }

    let dot = document.getElementById("status-dot");
    let text = document.getElementById("status-text");
    let signIn = document.getElementById("status-sign-in");
    let lastSync = document.getElementById("status-last-sync");
    let pendingText = document.getElementById("status-pending");
    let retry = document.getElementById("status-sync-now");

    if (!dot) {
        dot = el("span", { id: "status-dot", class: "inline-block h-2 w-2 rounded-full", "aria-hidden": "true" });
        text = el("span", { id: "status-text" });
        signIn = el("a", {
            id: "status-sign-in",
            href: "/login?redirect=/app",
            class: "inline-flex min-h-11 items-center rounded border px-3 py-1 text-sm font-medium underline",
            text: "Sign in to sync",
        });
        lastSync = el("span", { id: "status-last-sync", class: "text-zinc-500" });
        pendingText = el("span", { id: "status-pending", class: "text-zinc-500" });
        retry = el("button", {
            id: "status-sync-now",
            type: "button",
            class: "min-h-11 rounded border px-3 py-1 text-sm font-medium disabled:opacity-50",
            text: "Sync now",
            onclick: () => {
                if (runtime.accountChanged) {
                    window.location.assign("/app");
                    return;
                }

                syncNow({ manual: true });
            },
        });

        const summary = el("div", {
            id: "status-summary",
            class: "inline-flex flex-wrap items-center gap-3",
            role: "status",
            "aria-live": "polite",
            "aria-atomic": "true",
        });

        summary.append(dot, text, lastSync, pendingText);
        bar.append(summary, signIn, retry);
    }

    const dotColor = runtime.accountChanged
        ? "bg-amber-600"
        : runtime.needsSignIn
            ? "bg-red-600"
            : runtime.syncing
                ? "bg-blue-500"
                : runtime.online
                    ? "bg-emerald-600"
                    : "bg-amber-600";

    dot.className = `inline-block h-2 w-2 rounded-full ${dotColor}`;

    const nextStatus = statusText();
    if (text.textContent !== nextStatus) {
        text.textContent = nextStatus;
    }

    signIn.classList.toggle("hidden", !runtime.needsSignIn);

    const nextLastSync = lastSyncAt ? `Last sync ${new Date(lastSyncAt).toLocaleString()}` : "";
    if (lastSync.textContent !== nextLastSync) {
        lastSync.textContent = nextLastSync;
    }

    lastSync.classList.toggle("hidden", !lastSyncAt);

    const nextPending = `${pending} pending`;
    if (pendingText.textContent !== nextPending) {
        pendingText.textContent = nextPending;
    }

    if (runtime.accountChanged) {
        retry.textContent = "Reload";
    } else {
        retry.textContent = runtime.syncing ? "Syncing…" : "Sync now";
    }

    retry.disabled = runtime.syncing;

}

function localListFilter() {
    return {
        search: uiState.search.trim(),
        folder_id: uiState.view === "trash" ? null : uiState.folderId,
        trash: uiState.view === "trash",
    };
}

function localSortName() {
    if (uiState.sort === "title_az") {
        return "title_asc";
    }

    if (uiState.sort === "title_za") {
        return "title_desc";
    }

    return uiState.sort === "oldest" ? "oldest" : "newest";
}

function localNoteMatches(note, filter) {
    if (note.user_id !== runtime.userId) {
        return false;
    }

    if (filter.trash ? note.deleted_at == null : note.deleted_at != null) {
        return false;
    }

    if (filter.folder_id !== null && note.folder_portable_id !== filter.folder_id) {
        return false;
    }

    if (filter.search !== "") {
        const haystack = `${note.title} ${plainText(note.content ?? "")}`.toLowerCase();

        if (! haystack.includes(filter.search.toLowerCase())) {
            return false;
        }
    }

    return true;
}

function localCursorKey(note, sort) {
    if (sort === "title_asc" || sort === "title_desc") {
        return note.title_key ?? titleKeyFor(note.title ?? "");
    }

    const timestamp = new Date(note.updated_at ?? note.created_at).toISOString();

    return timestamp.replace(/\.(\d{3})Z$/, (_, milliseconds) => `.${milliseconds}000Z`);
}

function encodeLocalCursor(cursor) {
    const bytes = new TextEncoder().encode(JSON.stringify(cursor));
    let binary = "";

    for (const byte of bytes) {
        binary += String.fromCharCode(byte);
    }

    return btoa(binary).replaceAll("+", "-").replaceAll("/", "_").replace(/=+$/, "");
}

function decodeLocalCursor(value) {
    try {
        const binary = atob(value.replaceAll("-", "+").replaceAll("_", "/"));
        const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
        return JSON.parse(new TextDecoder().decode(bytes));
    } catch {
        return null;
    }
}

function localNoteIndex(sort, filter) {
    const userId = runtime.userId;
    const minimum = Dexie.minKey;
    const maximum = Dexie.maxKey;
    const titleSort = sort === "title_asc" || sort === "title_desc";
    const useFolderIndex = ! titleSort && ! filter.trash && filter.folder_id !== null;
    const index = titleSort
        ? "[user_id+title_key+portable_id]"
        : useFolderIndex
            ? "[user_id+folder_portable_id+updated_at+portable_id]"
            : "[user_id+updated_at+portable_id]";
    const lower = useFolderIndex
        ? [userId, filter.folder_id, minimum, minimum]
        : [userId, minimum, minimum];
    const upper = useFolderIndex
        ? [userId, filter.folder_id, maximum, maximum]
        : [userId, maximum, maximum];

    return { index, lower, upper, useFolderIndex, titleSort };
}

function localIndexKey(note, sort, filter, useFolderIndex) {
    if (useFolderIndex) {
        return [runtime.userId, filter.folder_id, note.updated_at, note.portable_id];
    }

    if (sort === "title_asc" || sort === "title_desc") {
        return [runtime.userId, localCursorKey(note, sort), note.portable_id];
    }

    return [runtime.userId, note.updated_at, note.portable_id];
}

async function readLocalNotePage(cursorValue = null) {
    const filter = localListFilter();
    const sort = localSortName();
    const { index, lower, upper, useFolderIndex } = localNoteIndex(sort, filter);
    const descending = sort === "newest" || sort === "title_desc";
    let anchor = null;
    let rangeStart = lower;
    let rangeEnd = upper;
    let includeStart = true;
    let includeEnd = true;

    if (cursorValue !== null) {
        const cursor = decodeLocalCursor(cursorValue);

        if (
            ! cursor
            || cursor.v !== 1
            || cursor.sort !== sort
            || JSON.stringify(cursor.filter) !== JSON.stringify(filter)
            || typeof cursor.key !== "string"
            || typeof cursor.anchor !== "string"
        ) {
            return { invalid: true };
        }

        anchor = await runtime.db.notes.get(cursor.anchor);

        if (
            ! anchor
            || ! localNoteMatches(anchor, filter)
            || localCursorKey(anchor, sort) !== cursor.key
        ) {
            return { invalid: true };
        }

        const anchorKey = localIndexKey(anchor, sort, filter, useFolderIndex);

        if (descending) {
            rangeEnd = anchorKey;
            includeEnd = false;
        } else {
            rangeStart = anchorKey;
            includeStart = false;
        }
    }

    let collection = runtime.db.notes
        .where(index)
        .between(rangeStart, rangeEnd, includeStart, includeEnd);

    if (descending) {
        collection = collection.reverse();
    }

    const matchingNotes = await collection
        .filter((note) => localNoteMatches(note, filter))
        .limit(51)
        .toArray();
    const hasMore = matchingNotes.length > 50;
    const notes = matchingNotes.slice(0, 50);
    const lastNote = notes.at(-1);
    const nextCursor = hasMore && lastNote
        ? encodeLocalCursor({
            v: 1,
            sort,
            filter,
            key: localCursorKey(lastNote, sort),
            anchor: lastNote.portable_id,
        })
        : null;
    const matchedCount = hasMore
        ? null
        : await runtime.db.notes
            .where(index)
            .between(lower, upper)
            .filter((note) => localNoteMatches(note, filter))
            .count();

    return {
        invalid: false,
        notes: notes.map((note) => ({
            ...note,
            content: (note.content ?? "").slice(0, 2000),
        })),
        cursor: nextCursor,
        hasMore,
        matchedCount,
    };
}

async function loadFirstLocalNotePage() {
    try {
        const page = await readLocalNotePage();
        uiState.noteRows = page.notes;
        uiState.notesCursor = page.cursor;
        uiState.hasMoreNotes = page.hasMore;
        uiState.matchedNotesCount = page.matchedCount;
        uiState.listInitialized = true;

        const statusMessage = uiState.pendingListStatusMessage
            ?? (page.hasMore ? "" : `${page.matchedCount} notes total.`);
        uiState.pendingListStatusMessage = null;

        setLocalListStatus(statusMessage);
    } catch {
        uiState.noteRows = [];
        uiState.notesCursor = null;
        uiState.hasMoreNotes = false;
        uiState.listInitialized = true;
        uiState.pendingListStatusMessage = null;
        setLocalListStatus("Notes could not be loaded. Try again.");
    }
}

function setLocalListStatus(message) {
    uiState.listStatusMessage = message;
    uiState.listStatusMessageId++;
}

function renderListStatus() {
    const status = document.getElementById("notes-list-status");

    if (!status || status.dataset.statusMessageId === String(uiState.listStatusMessageId)) {
        return;
    }

    status.dataset.statusMessageId = String(uiState.listStatusMessageId);
    status.textContent = uiState.listStatusMessage;
}

async function resetOfflineList({ focusHeading = true, statusMessage = null } = {}) {
    uiState.noteRows = [];
    uiState.notesCursor = null;
    uiState.hasMoreNotes = false;
    uiState.matchedNotesCount = null;
    uiState.listInitialized = false;
    uiState.focusListHeading = focusHeading;

    if (uiState.selectedNoteId !== null) {
        const selected = await runtime.db.notes.get(uiState.selectedNoteId);

        if (! selected || ! localNoteMatches(selected, localListFilter())) {
            uiState.selectedNoteId = null;
            uiState.mobileView = "list";
            uiState.focusListHeading = true;
        }
    }

    if (statusMessage !== null) {
        uiState.pendingListStatusMessage = statusMessage;
    }
}

async function loadMoreLocalNotes() {
    if (uiState.notesLoading || uiState.notesCursor === null) {
        return;
    }

    uiState.notesLoading = true;
    const cursorValue = uiState.notesCursor;
    const button = document.getElementById("load-more-notes");

    if (button) {
        button.disabled = true;
        button.textContent = "Loading…";
    }

    try {
        const page = await readLocalNotePage(cursorValue);

        if (uiState.notesCursor !== cursorValue) {
            return;
        }

        if (page.invalid) {
            await resetOfflineList({
                focusHeading: true,
                statusMessage: "The notes list was refreshed because its page was no longer valid.",
            });
            await renderAll();
            return;
        }

        uiState.noteRows = [...uiState.noteRows, ...page.notes];
        uiState.notesCursor = page.cursor;
        uiState.hasMoreNotes = page.hasMore;
        uiState.matchedNotesCount = page.matchedCount;

        let message = `${page.notes.length} notes loaded.`;

        if (! page.hasMore) {
            message += ` ${page.matchedCount} notes total.`;
            uiState.focusListHeading = true;
        } else {
            uiState.focusLoadMore = true;
        }

        setLocalListStatus(message);
        await renderList();
    } catch {
        if (uiState.notesCursor !== cursorValue) {
            return;
        }

        setLocalListStatus("Could not load more notes. Try again.");

        if (button) {
            button.disabled = false;
            button.textContent = "Load more";
        }

        renderListStatus();
    } finally {
        uiState.notesLoading = false;
    }
}

async function renderFolders(folders) {
    const container = document.getElementById("folder-list");

    if (!container) {
        return;
    }

    container.replaceChildren();

    const newFolder = el("button", {
        type: "button",
        class: "inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm font-medium hover:bg-zinc-100 dark:hover:bg-zinc-800",
        text: "+ New folder",
        onclick: async () => {
            const name = window.prompt("Folder name");
            if (name && name.trim() !== "") {
                uiState.folderId = await createFolder(name.trim());
                uiState.mobileView = "list";
                await resetOfflineList({ focusHeading: false });
                await renderAll();
            }
        },
    });

    container.append(el("div", { class: "flex items-center justify-between gap-2" }, [
        el("h2", { id: "folders-heading", class: "text-lg font-semibold", text: "Folders" }),
        newFolder,
    ]));

    const item = (label, active, onclick) => el("button", {
        type: "button",
        ...(active ? { "aria-current": "page" } : {}),
        class: `block min-h-11 w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-100 dark:hover:bg-zinc-800 ${active ? "bg-zinc-100 dark:bg-zinc-800" : ""}`.trim(),
        text: label,
        onclick,
    });

    container.append(item("All Notes", !uiState.folderId && uiState.view !== "trash", async () => {
        uiState.folderId = null;
        uiState.view = "all";
        uiState.mobileView = "list";
        if (searchTimeout !== null) {
            clearTimeout(searchTimeout);
        }
        await resetOfflineList();
        await renderAll();
    }));

    const sorted = [...folders].sort((a, b) => a.name.localeCompare(b.name));

    for (const folder of sorted) {
        const row = el("div", { class: "flex items-center gap-1" });

        row.append(item(folder.name, uiState.folderId === folder.portable_id, async () => {
            uiState.folderId = folder.portable_id;
            uiState.view = "all";
            uiState.mobileView = "list";
            if (searchTimeout !== null) {
                clearTimeout(searchTimeout);
            }
            await resetOfflineList();
            await renderAll();
        }));

        const rename = el("button", {
            type: "button",
            class: "min-h-11 rounded px-3 py-2 text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800",
            text: "Rename",
            onclick: async () => {
                const name = window.prompt("Folder name", folder.name);
                if (name && name.trim() !== "") {
                    await renameFolder(folder.portable_id, name.trim());
                    renderAll();
                }
            },
        });

        const remove = el("button", {
            type: "button",
            class: "min-h-11 rounded px-3 py-2 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-950",
            text: "Delete",
            onclick: async () => {
                if (window.confirm(`Delete folder "${folder.name}"? Its notes stay in All Notes.`)) {
                    await deleteFolder(folder.portable_id);
                    if (uiState.folderId === folder.portable_id) {
                        uiState.folderId = null;
                    }
                    await resetOfflineList();
                    await renderAll();
                }
            },
        });

        row.append(rename, remove);
        container.append(row);
    }

    container.append(item("Trash", uiState.view === "trash", async () => {
        uiState.view = "trash";
        uiState.folderId = null;
        uiState.mobileView = "list";
        if (searchTimeout !== null) {
            clearTimeout(searchTimeout);
        }
        await resetOfflineList();
        await renderAll();
    }));

}

async function renderList() {
    const container = document.getElementById("note-list");
    const heading = document.getElementById("note-list-heading");

    if (heading) {
        heading.textContent = uiState.view === "trash" ? "Trash" : "Notes";
    }

    if (!container || !runtime.db) {
        return;
    }

    if (!uiState.listInitialized) {
        await loadFirstLocalNotePage();
    }

    const notes = uiState.noteRows;
    container.replaceChildren();

    if (notes.length === 0) {
        container.append(el("p", {
            class: "p-4 text-sm text-zinc-500",
            text: uiState.search ? "No notes match your search." : "No notes here yet.",
        }));
    } else {
        for (const note of notes) {
            const active = note.portable_id === uiState.selectedNoteId;
            const preview = plainText(note.content).slice(0, 120);
            const item = el("button", {
                type: "button",
                "data-note-id": note.portable_id,
                "aria-current": active ? "true" : "false",
                "aria-label": note.title || "Untitled note",
                class: "block min-h-11 w-full rounded-lg border p-4 text-left focus-visible:outline-3 focus-visible:outline-offset-4",
                onclick: () => {
                    uiState.selectedNoteId = note.portable_id;
                    uiState.mobileView = "editor";
                    uiState.focusNoteId = note.portable_id;
                    uiState.focusEditorHeading = true;
                    renderAll();
                },
            });

            const heading = el("div", { class: "flex items-center gap-2" });
            heading.append(el("span", { class: "flex-1 truncate font-semibold", text: note.title || "Untitled" }));

            if (note.sync_state === "pending" || note.sync_state === "conflict") {
                const dot = note.sync_state === "conflict" ? "bg-red-600" : "bg-amber-600";
                heading.append(el("span", {
                    class: `h-2 w-2 shrink-0 rounded-full ${dot}`,
                    title: note.sync_state === "conflict" ? "Kept locally, server has a different version" : "Waiting to sync",
                }));
            }

            item.append(heading);

            if (preview) {
                item.append(el("p", { class: "mt-1 truncate text-sm text-zinc-500", text: preview }));
            }

            container.append(item);
        }
    }

    if (uiState.hasMoreNotes) {
        container.append(el("button", {
            id: "load-more-notes",
            type: "button",
            class: "min-h-11 rounded-lg border px-4 py-2 text-sm font-medium disabled:opacity-50",
            text: "Load more",
            onclick: loadMoreLocalNotes,
        }));
    }

    if (uiState.focusNoteId && uiState.mobileView === "list") {
        container.querySelector(`[data-note-id="${uiState.focusNoteId}"]`)?.focus();
        uiState.focusNoteId = null;
    } else if (uiState.mobileView !== "list") {
        uiState.focusNoteId = null;
    }

    if (uiState.focusLoadMore) {
        document.getElementById("load-more-notes")?.focus();
        uiState.focusLoadMore = false;
    }

    if (uiState.focusListHeading) {
        document.getElementById("note-list-heading")?.focus();
        uiState.focusListHeading = false;
    }

    renderListStatus();
}

function backToNoteList() {
    uiState.mobileView = "list";
    updateMobileView();

    const current = document.querySelector('#note-list [aria-current="true"]');

    (current ?? document.getElementById("note-list-heading"))?.focus();
}

function showEditorError(message) {
    const input = document.getElementById("note-title");
    const error = document.getElementById("note-title-error");

    if (error) {
        error.textContent = message;
        error.classList.remove("hidden");
    }

    if (input) {
        input.setAttribute("aria-invalid", "true");
        input.focus();
    }
}

const toolbarButtonClass = "inline-flex min-h-6 min-w-6 items-center justify-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800";
const toolbarMenuButtonClass = "inline-flex min-h-6 items-center rounded px-3 py-1 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800";
const toolbarMenuItemClass = "block min-h-11 w-full rounded px-3 py-2 text-left text-sm hover:bg-zinc-100 dark:hover:bg-zinc-800";
const toolbarMenuPanelClass = "absolute left-0 top-full z-50 mt-1 w-48 rounded-lg border p-1 shadow-lg";

function toolbarAlignmentIcon(paths) {
    const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");

    svg.setAttribute("class", "size-4");
    svg.setAttribute("viewBox", "0 0 16 16");
    svg.setAttribute("fill", "none");
    svg.setAttribute("stroke", "currentColor");
    svg.setAttribute("stroke-width", "1.25");
    svg.setAttribute("stroke-linecap", "round");
    svg.setAttribute("aria-hidden", "true");

    for (const pathData of paths) {
        const path = document.createElementNS("http://www.w3.org/2000/svg", "path");

        path.setAttribute("d", pathData);
        svg.append(path);
    }

    return svg;
}

function createTiptapToolbar(component) {
    const help = el("p", {
        id: "note-toolbar-help",
        class: "mb-1 text-xs text-zinc-500 dark:text-zinc-400",
        text: "Formatting toolbar. Use Tab to move between controls; pressed buttons are active formats.",
    });
    const toolbar = el("div", {
        class: "mb-2 rounded-lg border p-1",
        role: "toolbar",
        "aria-label": "Formatting",
        "aria-describedby": "note-toolbar-help",
    });
    const row = el("div", { class: "flex flex-wrap items-center gap-1" });
    const pressedResolvers = new Map();
    const menuClosers = [];
    const tableOnlyNodes = [];
    const tableMenuWrappers = [];
    const fontSizeControls = [];
    const fontColorControls = [];

    toolbar.append(row);

    const closeMenus = () => {
        for (const close of menuClosers) {
            close();
        }
    };

    const call = (method, ...args) => () => component[method](...args);

    const addButton = (parent, label, action, options = {}) => {
        const button = el("button", {
            type: "button",
            class: `${toolbarButtonClass} ${options.class ?? ""}`.trim(),
            "aria-label": label,
            title: label,
            onmousedown: (event) => event.preventDefault(),
            onclick: () => {
                action();
                closeMenus();
            },
        }, [options.icon ?? options.glyph ?? label]);

        if (options.pressed) {
            button.setAttribute("aria-pressed", "false");
            pressedResolvers.set(button, options.pressed);
        } else if (options.format) {
            button.setAttribute("aria-pressed", "false");
            pressedResolvers.set(button, (formats) => Boolean(formats[options.format]));
        }

        parent.append(button);

        return button;
    };

    const addMenuItem = (parent, item) => {
        if (item.heading) {
            const heading = el("p", {
                class: "mt-1 px-3 py-2 text-xs font-medium text-zinc-500",
                text: item.label,
            });

            if (item.tableOnly) {
                tableOnlyNodes.push(heading);
            }

            parent.append(heading);

            return heading;
        }

        const button = el("button", {
            type: "button",
            class: `${toolbarMenuItemClass} ${item.class ?? ""}`.trim(),
            text: item.label,
            onmousedown: (event) => event.preventDefault(),
            onclick: () => {
                item.action();
                closeMenus();
            },
        });

        if (item.pressed) {
            button.setAttribute("aria-pressed", "false");
            pressedResolvers.set(button, item.pressed);
        } else if (item.format) {
            button.setAttribute("aria-pressed", "false");
            pressedResolvers.set(button, (formats) => Boolean(formats[item.format]));
        }

        if (item.tableOnly) {
            tableOnlyNodes.push(button);
        }

        parent.append(button);

        return button;
    };

    const createMenu = (label, triggerText, items, extraClass = "") => {
        const wrapper = el("div", { class: `relative ${extraClass}`.trim() });
        const panel = el("div", {
            class: `${toolbarMenuPanelClass} hidden`,
            role: "group",
            "aria-label": label,
        });
        const trigger = el("button", {
            type: "button",
            class: toolbarMenuButtonClass,
            "aria-label": label,
            title: label,
            "aria-haspopup": "true",
            "aria-expanded": "false",
            onmousedown: (event) => event.preventDefault(),
            onclick: () => {
                const shouldOpen = panel.classList.contains("hidden");

                closeMenus();
                panel.classList.toggle("hidden", !shouldOpen);
                trigger.setAttribute("aria-expanded", String(shouldOpen));
            },
        }, [triggerText]);

        menuClosers.push(() => {
            panel.classList.add("hidden");
            trigger.setAttribute("aria-expanded", "false");
        });

        for (const item of items) {
            addMenuItem(panel, item);
        }

        wrapper.append(trigger, panel);
        row.append(wrapper);

        if (extraClass.includes("max-md:hidden")) {
            tableMenuWrappers.push(wrapper);
        }

        return wrapper;
    };

    const addFontSizeControl = (extraClass = "") => {
        const wrapper = el("div", { class: extraClass });
        const select = el("select", {
            "aria-label": "Font size",
            class: "min-h-6 rounded border border-zinc-300 bg-white px-2 py-1 text-sm",
            onchange: (event) => component.setFontSize(event.target.value),
        }, [
            el("option", { value: "mixed", disabled: "disabled", text: "—" }),
            el("option", { value: "12px", text: "12" }),
            el("option", { value: "14px", text: "14" }),
            el("option", { value: "16px", text: "16" }),
            el("option", { value: "18px", text: "18" }),
            el("option", { value: "20px", text: "20" }),
            el("option", { value: "24px", text: "24" }),
            el("option", { value: "28px", text: "28" }),
            el("option", { value: "32px", text: "32" }),
        ]);

        fontSizeControls.push(select);
        wrapper.append(select);
        row.append(wrapper);

        return wrapper;
    };

    const addFontColorControl = (extraClass = "", compact = false) => {
        const wrapper = el("div", { class: extraClass });
        const input = el("input", {
            id: compact ? "note-font-color" : "more-font-color",
            type: "color",
            "aria-label": "Font color",
            class: compact
                ? "h-6 w-8 cursor-pointer rounded border border-zinc-300 bg-white p-0.5"
                : "mx-3 mb-2 h-11 w-16 cursor-pointer rounded border border-zinc-300 bg-white p-1",
            oninput: (event) => component.setColor(event.target.value),
        });

        fontColorControls.push(input);
        wrapper.append(input);
        row.append(wrapper);

        return wrapper;
    };

    addButton(row, "Bold", call("toggleBold"), { glyph: "B", class: "font-bold", format: "bold" });
    addButton(row, "Italic", call("toggleItalic"), { glyph: "I", class: "italic", format: "italic" });
    addButton(row, "Underline", call("toggleUnderline"), { glyph: "U", class: "underline", format: "underline" });
    addButton(row, "Strikethrough", call("toggleStrike"), { glyph: "S", class: "line-through", format: "strike" });

    addButton(row, "Code block", call("toggleCodeBlock"), { glyph: "Code", class: "max-md:hidden", format: "codeBlock" });
    addButton(row, "Bullet list", call("toggleBulletList"), { glyph: "Bullets", class: "max-md:hidden", format: "bulletList" });
    addButton(row, "Numbered list", call("toggleOrderedList"), { glyph: "Numbers", class: "max-md:hidden", format: "orderedList" });
    addButton(row, "Task list", call("toggleTaskList"), { glyph: "Tasks", class: "max-md:hidden", format: "taskList" });

    addButton(row, "Paragraph", call("setParagraph"), { glyph: "P", class: "max-md:hidden", format: "paragraph" });
    addButton(row, "Heading 1", call("setHeading", 1), { glyph: "H1", class: "max-md:hidden font-bold", format: "heading1" });
    addButton(row, "Heading 2", call("setHeading", 2), { glyph: "H2", class: "max-md:hidden font-bold", format: "heading2" });
    addButton(row, "Heading 3", call("setHeading", 3), { glyph: "H3", class: "max-md:hidden font-bold", format: "heading3" });

    addFontSizeControl("max-md:hidden");
    addFontColorControl("max-md:hidden", true);

    addButton(row, "Highlight", call("toggleHighlight"), { glyph: "Mark", class: "max-md:hidden", format: "highlight" });
    addButton(row, "Link", call("setLink"), { glyph: "Link", class: "max-md:hidden", format: "link" });

    addButton(row, "Align left", call("setTextAlign", "left"), { icon: toolbarAlignmentIcon(["M2 3.5h12", "M2 6.5h8", "M2 9.5h12", "M2 12.5h8"]), class: "max-md:hidden", format: "alignLeft" });
    addButton(row, "Align center", call("setTextAlign", "center"), { icon: toolbarAlignmentIcon(["M2 3.5h12", "M4 6.5h8", "M2 9.5h12", "M4 12.5h8"]), class: "max-md:hidden", format: "alignCenter" });
    addButton(row, "Align right", call("setTextAlign", "right"), { icon: toolbarAlignmentIcon(["M2 3.5h12", "M6 6.5h8", "M2 9.5h12", "M6 12.5h8"]), class: "max-md:hidden", format: "alignRight" });
    addButton(row, "Justify", call("setTextAlign", "justify"), { icon: toolbarAlignmentIcon(["M2 3.5h12", "M2 6.5h12", "M2 9.5h12", "M2 12.5h12"]), class: "max-md:hidden", format: "alignJustify" });

    addButton(row, "Insert table", call("insertTable"), { glyph: "Table", class: "max-md:hidden" });

    createMenu("Table options", "Table ▾", [
        { label: "Add row above", action: call("addTableRowBefore") },
        { label: "Add row below", action: call("addTableRowAfter") },
        { label: "Delete row", action: call("deleteTableRow") },
        { label: "Add column left", action: call("addTableColumnBefore") },
        { label: "Add column right", action: call("addTableColumnAfter") },
        { label: "Delete column", action: call("deleteTableColumn") },
        { label: "Delete table", action: call("deleteTable"), class: "text-red-600 hover:bg-red-50 dark:hover:bg-red-950" },
    ], "max-md:hidden");

    createMenu("Table width", "Width ▾", [
        { label: "Narrow (50%)", action: call("setTableWidth", 50), pressed: (formats) => formats.tableWidth === "narrow" },
        { label: "Medium (75%)", action: call("setTableWidth", 75), pressed: (formats) => formats.tableWidth === "medium" },
        { label: "Wide (100%)", action: call("setTableWidth", 100), pressed: (formats) => formats.tableWidth === "wide" },
        { label: "Reset width", action: call("setTableWidth", null) },
    ], "max-md:hidden");

    createMenu("More formatting", "More", [
        { label: "Bullet list", action: call("toggleBulletList"), format: "bulletList" },
        { label: "Numbered list", action: call("toggleOrderedList"), format: "orderedList" },
        { label: "Task list", action: call("toggleTaskList"), format: "taskList" },
        { label: "Code block", action: call("toggleCodeBlock"), format: "codeBlock" },
        { label: "Paragraph", action: call("setParagraph"), format: "paragraph" },
        { label: "Heading 1", action: call("setHeading", 1), class: "font-bold", format: "heading1" },
        { label: "Heading 2", action: call("setHeading", 2), class: "font-bold", format: "heading2" },
        { label: "Heading 3", action: call("setHeading", 3), class: "font-bold", format: "heading3" },
        { label: "Font size", heading: true },
        { label: "Font color", heading: true },
        { label: "Highlight", action: call("toggleHighlight"), format: "highlight" },
        { label: "Link", action: call("setLink"), format: "link" },
        { label: "Align left", action: call("setTextAlign", "left"), format: "alignLeft" },
        { label: "Align center", action: call("setTextAlign", "center"), format: "alignCenter" },
        { label: "Align right", action: call("setTextAlign", "right"), format: "alignRight" },
        { label: "Justify", action: call("setTextAlign", "justify"), format: "alignJustify" },
        { label: "Insert table", action: call("insertTable") },
        { label: "Table options", heading: true, tableOnly: true },
        { label: "Add row above", action: call("addTableRowBefore"), tableOnly: true },
        { label: "Add row below", action: call("addTableRowAfter"), tableOnly: true },
        { label: "Delete row", action: call("deleteTableRow"), tableOnly: true },
        { label: "Add column left", action: call("addTableColumnBefore"), tableOnly: true },
        { label: "Add column right", action: call("addTableColumnAfter"), tableOnly: true },
        { label: "Delete column", action: call("deleteTableColumn"), tableOnly: true },
        { label: "Delete table", action: call("deleteTable"), tableOnly: true, class: "text-red-600 hover:bg-red-50 dark:hover:bg-red-950" },
        { label: "Table width", heading: true, tableOnly: true },
        { label: "Narrow (50%)", action: call("setTableWidth", 50), tableOnly: true, pressed: (formats) => formats.tableWidth === "narrow" },
        { label: "Medium (75%)", action: call("setTableWidth", 75), tableOnly: true, pressed: (formats) => formats.tableWidth === "medium" },
        { label: "Wide (100%)", action: call("setTableWidth", 100), tableOnly: true, pressed: (formats) => formats.tableWidth === "wide" },
        { label: "Reset width", action: call("setTableWidth", null), tableOnly: true },
    ], "md:hidden");

    const morePanel = row.querySelector('[aria-label="More formatting"][role="group"]');

    if (morePanel) {
        const moreFontSize = el("select", {
            id: "more-font-size",
            "aria-label": "Font size",
            class: "mx-3 mb-2 min-h-11 rounded border border-zinc-300 bg-white px-2 py-1 text-sm",
            onchange: (event) => component.setFontSize(event.target.value),
        }, [
            el("option", { value: "mixed", disabled: "disabled", text: "—" }),
            el("option", { value: "12px", text: "12" }),
            el("option", { value: "14px", text: "14" }),
            el("option", { value: "16px", text: "16" }),
            el("option", { value: "18px", text: "18" }),
            el("option", { value: "20px", text: "20" }),
            el("option", { value: "24px", text: "24" }),
            el("option", { value: "28px", text: "28" }),
            el("option", { value: "32px", text: "32" }),
        ]);

        const moreColor = el("input", {
            id: "more-font-color",
            type: "color",
            "aria-label": "Font color",
            class: "mx-3 mb-2 h-11 w-16 cursor-pointer rounded border border-zinc-300 bg-white p-1",
            oninput: (event) => component.setColor(event.target.value),
        });

        fontSizeControls.push(moreFontSize);
        fontColorControls.push(moreColor);
        morePanel.querySelectorAll('p').forEach((heading) => {
            if (heading.textContent === "Font size") {
                heading.after(moreFontSize);
            }

            if (heading.textContent === "Font color") {
                heading.after(moreColor);
            }
        });
    }

    const onPointerDown = (event) => {
        if (!toolbar.contains(event.target)) {
            closeMenus();
        }
    };

    const onKeyDown = (event) => {
        if (event.key === "Escape") {
            closeMenus();
        }
    };

    document.addEventListener("pointerdown", onPointerDown);
    document.addEventListener("keydown", onKeyDown);

    const update = (formats) => {
        for (const [button, resolve] of pressedResolvers) {
            button.setAttribute("aria-pressed", resolve(formats) ? "true" : "false");
        }

        for (const select of fontSizeControls) {
            const validSize = Array.from(select.options).some((option) => option.value === formats.fontSize);

            select.value = validSize ? formats.fontSize : "16px";
        }

        const color = /^#[0-9a-f]{6}$/i.test(String(formats.color)) ? formats.color : "#000000";

        for (const input of fontColorControls) {
            input.value = color;
        }

        for (const wrapper of tableMenuWrappers) {
            wrapper.classList.toggle("hidden", !formats.table);
        }

        for (const node of tableOnlyNodes) {
            node.classList.toggle("hidden", !formats.table);
        }

        if (!formats.table) {
            closeMenus();
        }
    };

    return {
        element: el("div", {}, [help, toolbar]),
        update,
        destroy() {
            document.removeEventListener("pointerdown", onPointerDown);
            document.removeEventListener("keydown", onKeyDown);
            closeMenus();
        },
    };
}

async function renderEditor() {
    const panel = document.getElementById("editor-panel");

    if (!panel || !runtime.db) {
        return;
    }

    const note = uiState.selectedNoteId ? await runtime.db.notes.get(uiState.selectedNoteId) : null;

    if (!note) {
        if (uiState.editor) {
            uiState.editor.destroy();
            uiState.editor = null;
        }

        if (uiState.editorToolbar) {
            uiState.editorToolbar.destroy();
            uiState.editorToolbar = null;
        }

        uiState.editorNoteId = null;
        uiState.editorMount = null;
        uiState.editorDeletedAt = null;

        panel.replaceChildren(
            el("div", {
                class: "flex min-h-[50vh] items-center justify-center rounded-lg border border-dashed p-6 text-sm text-zinc-500",
                text: "Select a note, or capture a thought to start.",
            }),
        );

        updateMobileView();

        return;
    }

    const mountInDom = uiState.editor
        && document.getElementById("note-editor") === uiState.editorMount;
    const stateChanged = uiState.editorDeletedAt !== (note.deleted_at ?? null);

    if (uiState.editorNoteId !== note.portable_id || !mountInDom || stateChanged) {
        if (uiState.editor) {
            uiState.editor.destroy();
        }

        if (uiState.editorToolbar) {
            uiState.editorToolbar.destroy();
        }

        const editor = createTiptapComponent(note.content ?? "", {
            resizable: false,
            label: "Note content",
            onActiveFormatsChange: (formats) => uiState.editorToolbar?.update(formats),
        });
        const toolbar = createTiptapToolbar(editor);

        const titleInput = el("input", {
            id: "note-title",
            type: "text",
            required: "required",
            class: "min-h-11 w-full rounded-lg border px-3 py-2 text-lg font-semibold",
            value: note.title,
            placeholder: "Title",
            "aria-describedby": "note-title-error",
            "aria-invalid": "false",
        });

        const folderSelect = el("select", {
            id: "note-folder",
            class: "min-h-11 w-full rounded-lg border px-3 py-2 text-sm",
        });

        const titleError = el("p", {
            id: "note-title-error",
            role: "alert",
            class: "hidden text-sm text-red-600 dark:text-red-400",
        });

        panel.replaceChildren(
            el("div", { class: "space-y-3" }, [
                el("div", { class: "flex items-center gap-3" }, [
                    el("button", {
                        type: "button",
                        class: "min-h-11 rounded-lg border px-3 py-2 text-sm font-medium lg:hidden",
                        text: "Back to notes",
                        onclick: backToNoteList,
                    }),
                    el("h2", {
                        id: "note-editor-heading",
                        tabindex: "-1",
                        class: "text-xl font-semibold focus:outline-none",
                        text: "Edit note",
                    }),
                ]),
                el("div", { class: "space-y-1" }, [
                    el("label", { for: "note-title", class: "block text-sm font-medium", text: "Title" }),
                    titleInput,
                    titleError,
                ]),
                el("div", { class: "space-y-1" }, [
                    el("label", { for: "note-folder", class: "block text-sm font-medium", text: "Folder" }),
                    folderSelect,
                ]),
                toolbar.element,
                el("div", {
                    id: "note-editor",
                    class: "min-h-[180px] rounded-lg border",
                    "aria-label": "Note content",
                }),
                el("div", { class: "flex flex-wrap items-center gap-2" }, [
                    el("button", {
                        type: "button",
                        class: "min-h-11 rounded-lg bg-black px-4 py-2 text-sm font-medium text-white disabled:opacity-50",
                        text: "Save",
                        onclick: async () => {
                            const title = document.getElementById("note-title").value;
                            const folderId = document.getElementById("note-folder").value || null;
                            const content = uiState.editor ? uiState.editor.getContent() : "";

                            if (title.trim() === "") {
                                showEditorError("A note needs a title.");
                                return;
                            }

                            await saveNote(note.portable_id, {
                                title: title.trim(),
                                content,
                                folder_portable_id: folderId,
                            });

                            document.getElementById("note-title")?.removeAttribute("aria-invalid");
                            document.getElementById("note-title-error")?.classList.add("hidden");
                            uiState.editor?.focus();
                        },
                    }),
                    note.deleted_at
                        ? el("button", {
                            type: "button",
                            class: "min-h-11 rounded-lg border px-3 py-2 text-sm font-medium",
                            text: "Restore",
                            onclick: async () => {
                                await restoreNote(note.portable_id);
                            },
                        })
                        : el("button", {
                            type: "button",
                            class: "min-h-11 rounded-lg border px-3 py-2 text-sm font-medium",
                            text: "Move to Trash",
                            onclick: async () => {
                                await trashNote(note.portable_id);
                            },
                        }),
                    note.deleted_at
                        ? el("button", {
                            type: "button",
                            class: "min-h-11 rounded-lg bg-red-600 px-3 py-2 text-sm font-medium text-white",
                            text: "Delete forever",
                            onclick: async () => {
                                if (window.confirm("Delete this note forever?")) {
                                    await purgeNote(note.portable_id);
                                    uiState.selectedNoteId = null;
                                }
                            },
                        })
                        : null,
                ]),
                note.attachments?.length
                    ? el("div", {
                        class: "rounded-lg bg-zinc-50 p-3 text-sm text-zinc-600 dark:bg-zinc-900 dark:text-zinc-400",
                        text: `${note.attachments.length} attachment${note.attachments.length === 1 ? "" : "s"} on the server. Attachments need a connection to view.`,
                    })
                    : null,
            ]),
        );

        const folders = await runtime.db.folders.where("user_id").equals(runtime.userId).toArray();
        const select = document.getElementById("note-folder");
        select.append(el("option", { value: "", text: "All Notes" }));

        for (const folder of [...folders].sort((a, b) => a.name.localeCompare(b.name))) {
            const option = el("option", { value: folder.portable_id, text: folder.name });

            if (folder.portable_id === note.folder_portable_id) {
                option.selected = true;
            }

            select.append(option);
        }

        uiState.editor = editor;
        uiState.editorToolbar = toolbar;
        uiState.editor.$refs = { editor: document.getElementById("note-editor") };
        uiState.editor.init();
        uiState.editorMount = document.getElementById("note-editor");
        uiState.editorNoteId = note.portable_id;
        uiState.editorDeletedAt = note.deleted_at ?? null;
    } else {
        const titleInput = document.getElementById("note-title");

        if (titleInput && document.activeElement !== titleInput) {
            titleInput.value = note.title;
        }
    }

    updateMobileView();

    if (uiState.focusEditorHeading) {
        document.getElementById("note-editor-heading")?.focus();
        uiState.focusEditorHeading = false;
    }

    if (uiState.focusFolderSelect) {
        document.getElementById("note-folder")?.focus();
        uiState.focusFolderSelect = false;
    }
}

function updateMobileView() {
    const list = document.getElementById("note-list-wrapper");
    const editor = document.getElementById("editor-panel");

    if (list) {
        list.classList.toggle("max-lg:hidden", uiState.mobileView === "editor");
    }

    if (editor) {
        editor.classList.toggle("max-lg:hidden", uiState.mobileView === "list");
    }
}

async function captureThought() {
    const area = document.getElementById("capture-text");
    const parsed = captureToNote(area.value);

    const error = document.getElementById("capture-error");

    if (!parsed) {
        if (error) {
            error.textContent = "Write something to capture.";
            error.classList.remove("hidden");
        }

        area.setAttribute("aria-invalid", "true");
        area.focus();
        return;
    }

    if (error) {
        error.textContent = "";
        error.classList.add("hidden");
    }

    area.setAttribute("aria-invalid", "false");

    const portableId = crypto.randomUUID();

    await runtime.db.notes.put({
        portable_id: portableId,
        user_id: runtime.userId,
        folder_portable_id: null,
        title: parsed.title,
        title_key: titleKeyFor(parsed.title),
        content: parsed.content,
        version: 0,
        deleted_at: null,
        created_at: nowIso(),
        updated_at: nowIso(),
        attachments: [],
        sync_state: "pending",
        last_synced_at: null,
    });

    await queueOutbox(runtime.db, runtime.userId, {
        entity_type: "note",
        entity_portable_id: portableId,
        action: "created",
        payload: { title: parsed.title, content: parsed.content, folder_portable_id: null },
        base_version: 0,
    });

    area.value = "";
    uiState.selectedNoteId = portableId;
    uiState.mobileView = "editor";
    uiState.focusEditorHeading = true;
    await resetOfflineList({ focusHeading: false });
    await renderAll();
    syncNow();
}

function mountShell() {
    if (!root || document.getElementById("offline-shell")) {
        return;
    }

    root.replaceChildren(
        el("a", {
            href: "#main-content",
            class: "fixed start-4 top-4 z-[100] -translate-y-24 rounded-lg bg-black px-4 py-3 text-sm font-medium text-white shadow-lg transition-transform focus:translate-y-0",
            text: "Skip to content",
        }),
        el("div", { id: "offline-shell", class: "mx-auto flex min-h-screen max-w-6xl flex-col gap-6 p-6" }, [
            el("header", { class: "flex flex-wrap items-center justify-between gap-3" }, [
                el("h1", { class: "text-2xl font-bold", text: "Notes" }),
                el("div", { id: "status-bar", class: "flex flex-wrap items-center gap-3 text-sm" }),
            ]),
            el("div", { id: "status-notices", class: "flex flex-col gap-2", role: "status", "aria-live": "polite" }),
            el("div", { id: "update-banner", class: "hidden" }),
            el("main", { id: "main-content", tabindex: "-1", class: "grid flex-1 gap-6 md:grid-cols-4" }, [
                el("aside", { class: "space-y-2 lg:col-span-1", "aria-labelledby": "folders-heading" }, [
                    el("div", { class: "space-y-2" }, [
                        el("label", { for: "capture-text", class: "block text-sm font-medium", text: "Quick capture" }),
                        el("textarea", {
                            id: "capture-text",
                            rows: 3,
                            class: "min-h-11 w-full rounded-lg border px-4 py-2 text-sm",
                            placeholder: "Capture a thought...",
                            "aria-keyshortcuts": "Alt+Shift+N",
                            "aria-describedby": "capture-help capture-error",
                            "aria-invalid": "false",
                        }),
                        el("p", { id: "capture-help", class: "text-xs text-zinc-500", text: "The first line becomes the title." }),
                        el("p", { id: "capture-error", role: "alert", class: "hidden text-sm text-red-600 dark:text-red-400" }),
                        el("button", {
                            type: "button",
                            class: "min-h-11 w-full rounded-lg bg-black px-3 py-2 text-sm font-medium text-white disabled:opacity-50",
                            text: "Capture",
                            onclick: captureThought,
                        }),
                    ]),
                    el("nav", { id: "folder-list", class: "space-y-1" }),
                ]),
                el("section", {
                    class: "min-w-0 md:col-span-3 lg:grid lg:grid-cols-[minmax(16rem,22rem)_minmax(0,1fr)] lg:items-start lg:gap-6",
                    "aria-labelledby": "note-list-heading",
                }, [
                    el("div", { id: "note-list-wrapper", "data-note-list": "", class: "min-w-0" }, [
                        el("div", { class: "mb-6 flex flex-wrap items-center justify-between gap-4" }, [
                            el("h2", {
                                id: "note-list-heading",
                                tabindex: "-1",
                                class: "text-2xl font-bold focus:outline-none",
                                text: uiState.view === "trash" ? "Trash" : "Notes",
                            }),
                        ]),
                        el("div", { class: "mb-6 grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem]" }, [
                            el("div", { class: "space-y-1" }, [
                                el("label", { for: "note-search", class: "block text-sm font-medium", text: "Search notes" }),
                                el("input", {
                                    id: "note-search",
                                    type: "search",
                                    class: "min-h-11 w-full rounded-lg border px-4 py-2",
                                    placeholder: "Search notes...",
                                    value: uiState.search,
                                }),
                            ]),
                            el("div", { class: "space-y-1" }, [
                                el("label", { for: "note-sort", class: "block text-sm font-medium", text: "Sort by" }),
                                el("select", {
                                    id: "note-sort",
                                    class: "min-h-11 w-full rounded-lg border px-4 py-2",
                                }, [
                                    el("option", { value: "newest", text: "Newest" }),
                                    el("option", { value: "oldest", text: "Oldest" }),
                                    el("option", { value: "title_az", text: "Title A-Z" }),
                                    el("option", { value: "title_za", text: "Title Z-A" }),
                                ]),
                            ]),
                        ]),
                        el("p", {
                            id: "notes-list-status",
                            class: "sr-only",
                            role: "status",
                            "aria-live": "polite",
                            "aria-atomic": "true",
                            "data-status-message-id": "0",
                        }),
                        el("div", { id: "note-list", class: "space-y-3 lg:max-h-[70vh] lg:overflow-auto" }),
                    ]),
                    el("div", { id: "editor-panel", class: "min-w-0 rounded-lg border p-4 sm:p-6" }),
                ]),
            ]),
        ]),
    );

    const search = document.getElementById("note-search");
    search.addEventListener("input", () => {
        uiState.search = search.value;
        uiState.mobileView = "list";
        updateMobileView();

        if (searchTimeout !== null) {
            clearTimeout(searchTimeout);
        }

        searchTimeout = setTimeout(async () => {
            searchTimeout = null;
            await resetOfflineList({ focusHeading: false });
            await renderAll();
        }, 200);
    });

    const sort = document.getElementById("note-sort");
    sort.value = uiState.sort;
    sort.addEventListener("change", async () => {
        uiState.sort = sort.value;
        uiState.mobileView = "list";
        updateMobileView();
        if (searchTimeout !== null) {
            clearTimeout(searchTimeout);
        }
        await resetOfflineList();
        await renderAll();
    });
}

async function renderAll() {
    if (!root) {
        return;
    }

    if (!runtime.db) {
        renderSignIn();
        return;
    }

    mountShell();

    const folders = await runtime.db.folders.where("user_id").equals(runtime.userId).toArray();

    await renderFolders(folders);
    await renderList();
    await renderEditor();
    await renderStatus();
}

function renderSignIn() {
    const summary = runtime.userId === null
        ? "Sign in to sync your notes."
        : "Your local notes are still here. Sign in again to sync.";

    root.replaceChildren(
        el("a", {
            href: "#main-content",
            class: "fixed start-4 top-4 z-[100] -translate-y-24 rounded-lg bg-black px-4 py-3 text-sm font-medium text-white shadow-lg transition-transform focus:translate-y-0",
            text: "Skip to content",
        }),
        el("main", {
            id: "main-content",
            tabindex: "-1",
            class: "mx-auto flex min-h-screen max-w-md flex-col items-center justify-center gap-4 p-6 text-center",
        }, [
            el("h1", { class: "text-2xl font-bold", text: "Notes" }),
            el("p", { class: "text-sm text-zinc-500", text: summary }),
            el("a", {
                href: "/login?redirect=/app",
                class: "inline-flex min-h-11 items-center rounded-lg bg-black px-4 py-2 text-sm font-medium text-white",
                text: "Sign in",
            }),
            el("div", { id: "status-bar", class: "flex flex-wrap items-center justify-center gap-3 text-sm" }),
            el("div", { id: "status-notices", class: "flex flex-col gap-2", role: "status", "aria-live": "polite" }),
        ]),
    );

    renderStatus();
}

function renderContainer(html) {
    root.innerHTML = html;
}

function renderUpdateBanner() {
    const banner = document.getElementById("update-banner");
    if (!banner) return;
    banner.classList.remove("hidden");
    banner.replaceChildren(
        el("div", { class: "flex items-center justify-between gap-3 rounded-lg bg-blue-50 px-4 py-2 text-sm text-blue-900 dark:bg-blue-950 dark:text-blue-100" }, [
            el("span", { role: "status", text: "A new version is available." }),
            el("button", {
                type: "button",
                class: "min-h-11 font-semibold underline",
                text: "Update now",
                onclick: () => {
                    navigator.serviceWorker?.getRegistration().then((reg) => reg?.waiting?.postMessage("SKIP_WAITING"));
                    window.location.reload();
                },
            }),
        ]),
    );
}

// ===========================================================================
// Boot
// ===========================================================================

async function boot() {
    if ("serviceWorker" in navigator) {
        navigator.serviceWorker.register("/sw.js").then((registration) => {
            if (registration.waiting) {
                renderUpdateBanner();
            }
            registration.addEventListener("updatefound", () => {
                const worker = registration.installing;
                worker?.addEventListener("statechange", () => {
                    if (worker.state === "installed" && navigator.serviceWorker.controller) {
                        renderUpdateBanner();
                    }
                });
            });
        }).catch(() => {});
    }

    if (navigator.storage?.persist) {
        await navigator.storage.persist().catch(() => {});
    }

    // Pin the per-user database with a lock so only one tab syncs.
    const lockable = async () => {
        let userId = pageUserId() ?? lastUserId();
        let canSync = true;

        if (navigator.onLine) {
            const decision = resolveIdentityForSync({
                currentUserId: userId,
                probedUserId: await fetchPageUserId(),
                online: true,
            });

            if (decision.status === "signed-out") {
                forgetUserId();
                userId = null;
            } else if (decision.status === "changed" || decision.status === "same") {
                userId = decision.userId;
            } else {
                canSync = false;
            }
        }

        if (userId !== null) {
            rememberUserId(userId);
        }

        if (userId === null) {
            setRuntime({ needsSignIn: true });
            renderSignIn();
            return;
        }

        setRuntime({ userId, db: openDb(userId) });

        // Reset any in-flight markers left by a crashed tab.
        await runtime.db.notes.where("user_id").equals(userId).modify((note) => {
            if (note.sync_state === "syncing") {
                note.sync_state = "pending";
            }
        });
        await runtime.db.folders.where("user_id").equals(userId).modify((folder) => {
            if (folder.sync_state === "syncing") {
                folder.sync_state = "pending";
            }
        });
        await runtime.db.outbox.where("user_id").equals(userId).modify((row) => {
            if (row.status === "sending") {
                row.status = "queued";
            }
        });

        await renderAll();

        if (!canSync) {
            setRuntime({ lastError: "Could not confirm the signed in account." });
            return;
        }

        await syncNow({ verifyIdentity: false });
    };

    if (navigator.locks) {
        await navigator.locks.request("notes-boot", lockable);
    } else {
        await lockable();
    }

    window.addEventListener("online", () => {
        setRuntime({ online: true, nextRetryAt: 0 });
        syncNow({ manual: true });
    });

    window.addEventListener("offline", () => {
        setRuntime({ online: false });
    });

    window.addEventListener("keydown", (event) => {
        if (
            event.key.toLowerCase() !== "n"
            || ! event.altKey
            || ! event.shiftKey
            || event.ctrlKey
            || event.metaKey
            || event.repeat
        ) {
            return;
        }

        if (! (event.target instanceof Element)) {
            return;
        }

        if (event.target.closest("input, textarea, select, [contenteditable]")) {
            return;
        }

        if (document.querySelector("dialog[open]")) {
            return;
        }

        const capture = document.getElementById("capture-text");

        if (capture) {
            event.preventDefault();
            capture.focus();
        }
    });
}

boot();
