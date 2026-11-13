import Dexie from "dexie";
import { createTiptapEditor } from "./tiptap.js";

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
    const rows = await db.outbox.where("user_id").equals(userId);

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

async function syncNow({ manual = false } = {}) {
    if (runtime.syncing || runtime.userId === null || runtime.db === null) {
        return;
    }

    if (!runtime.online || (!manual && Date.now() < runtime.nextRetryAt)) {
        return;
    }

    const run = async () => {
        setRuntime({ syncing: true, lastError: null });

        try {
            const summary = await push(runtime.db, runtime.userId);
            await pull(runtime.db, runtime.userId);

            if (summary.rejected > 0 || summary.conflicts > 0) {
                renderAll();
            }

            setRuntime({ syncing: false, needsSignIn: false, nextRetryAt: 0 });
            renderAll();
        } catch (error) {
            if (error instanceof NeedsSignInError) {
                setRuntime({
                    syncing: false,
                    needsSignIn: true,
                    lastError: null,
                    nextRetryAt: 0,
                });
            } else {
                const maxAttempts = await db.outbox
                    .where("user_id")
                    .equals(runtime.userId)
                    .toArray()
                    .then((rows) => rows.reduce((max, row) => Math.max(max, row.attempts ?? 0), 0));

                setRuntime({
                    syncing: false,
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

    if (local.last_synced_at == null) {
        await db.notes.delete(portableId);
        await db.outbox.where("user_id").equals(userId)
            .filter((row) => row.entity_type === "note" && row.entity_portable_id === portableId)
            .delete();
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

    if (local.last_synced_at == null) {
        await db.notes.delete(portableId);
        await db.outbox.where("user_id").equals(userId)
            .filter((row) => row.entity_type === "note" && row.entity_portable_id === portableId)
            .delete();
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

    renderAll();
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

        await db.notes.where("folder_portable_id").equals(portableId)
            .modify((note) => {
                note.folder_portable_id = null;
            });
    });

    if (local.last_synced_at == null) {
        await db.outbox.where("user_id").equals(userId)
            .filter((row) => row.entity_type === "folder" && row.entity_portable_id === portableId)
            .delete();
        renderAll();
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

    renderAll();
    syncNow();
}

// ===========================================================================
// Text helpers (same shapes as the server rules)
// ===========================================================================

const TRIM_RE = /^[\p{Z}\x09-\x0D\u0085\uFEFF]+|[\p{Z}\x09-\x0D\u0085\uFEFF]+$/gu;

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
    editor: null,
    editorNoteId: null,
    editorMount: null,
};

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

    bar.replaceChildren();

    const dotColor = runtime.needsSignIn
        ? "bg-red-500"
        : runtime.syncing
            ? "bg-blue-500"
            : runtime.online
                ? "bg-emerald-500"
                : "bg-zinc-400";

    const parts = [
        el("span", { class: `inline-block h-2 w-2 rounded-full ${dotColor}`, "aria-hidden": "true" }),
        el("span", { text: statusText() }),
    ];

    if (runtime.needsSignIn) {
        parts.push(el("a", {
            href: "/login",
            class: "rounded border px-3 py-1 text-sm font-medium underline",
            text: "Sign in to sync",
        }));
    }

    if (lastSyncAt) {
        parts.push(el("span", { class: "text-zinc-500", text: `Last sync ${new Date(lastSyncAt).toLocaleString()}` }));
    }

    parts.push(el("span", { class: "text-zinc-500", text: `${pending} pending` }));

    const retry = el("button", {
        type: "button",
        class: "rounded border px-3 py-1 text-sm font-medium",
        text: runtime.syncing ? "Syncing…" : "Sync now",
        onclick: () => syncNow({ manual: true }),
    });

    if (runtime.syncing) {
        retry.disabled = true;
    }

    parts.push(retry);

    bar.append(...parts);
    renderNotices();
}

async function filteredNotes() {
    const db = runtime.db;
    const userId = runtime.userId;

    let rows = await db.notes.where("user_id").equals(userId).toArray();

    if (uiState.view === "trash") {
        rows = rows.filter((note) => note.deleted_at !== null);
    } else {
        rows = rows.filter((note) => note.deleted_at === null);
    }

    if (uiState.folderId) {
        rows = rows.filter((note) => note.folder_portable_id === uiState.folderId);
    }

    if (uiState.search.trim() !== "") {
        const needle = uiState.search.trim().toLowerCase();

        rows = rows.filter((note) => {
            const haystack = `${note.title} ${plainText(note.content)}`.toLowerCase();
            return haystack.includes(needle);
        });
    }

    const byTime = (a, b) => new Date(b.updated_at ?? b.created_at) - new Date(a.updated_at ?? a.created_at);

    switch (uiState.sort) {
        case "oldest":
            rows.sort((a, b) => -byTime(a, b));
            break;
        case "title_az":
            rows.sort((a, b) => a.title.localeCompare(b.title));
            break;
        case "title_za":
            rows.sort((a, b) => b.title.localeCompare(a.title));
            break;
        default:
            rows.sort(byTime);
    }

    return rows;
}

async function renderFolders(folders) {
    const container = document.getElementById("folder-list");

    if (!container) {
        return;
    }

    container.replaceChildren();

    const item = (label, active, onclick) => el("button", {
        type: "button",
        class: `block w-full rounded-lg px-3 py-2 text-left text-sm ${active ? "bg-black text-white dark:bg-white dark:text-black" : "hover:bg-zinc-100 dark:hover:bg-zinc-800"}`,
        text: label,
        onclick,
    });

    container.append(item("All Notes", !uiState.folderId && uiState.view !== "trash", () => {
        uiState.folderId = null;
        uiState.view = "all";
        renderAll();
    }));

    const sorted = [...folders].sort((a, b) => a.name.localeCompare(b.name));

    for (const folder of sorted) {
        const row = el("div", { class: "flex items-center gap-1" });

        row.append(item(folder.name, uiState.folderId === folder.portable_id, () => {
            uiState.folderId = folder.portable_id;
            uiState.view = "all";
            renderAll();
        }));

        const rename = el("button", {
            type: "button",
            class: "rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800",
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
            class: "rounded px-2 py-1 text-xs text-red-500 hover:bg-red-50 dark:hover:bg-red-950",
            text: "Delete",
            onclick: async () => {
                if (window.confirm(`Delete folder "${folder.name}"? Its notes stay in All Notes.`)) {
                    await deleteFolder(folder.portable_id);
                    if (uiState.folderId === folder.portable_id) {
                        uiState.folderId = null;
                    }
                    renderAll();
                }
            },
        });

        row.append(rename, remove);
        container.append(row);
    }

    container.append(item("Trash", uiState.view === "trash", () => {
        uiState.view = "trash";
        uiState.folderId = null;
        renderAll();
    }));

    const newFolder = el("button", {
        type: "button",
        class: "mt-2 w-full rounded-lg border px-3 py-2 text-sm font-medium",
        text: "+ New folder",
        onclick: async () => {
            const name = window.prompt("Folder name");
            if (name && name.trim() !== "") {
                uiState.folderId = await createFolder(name.trim());
                renderAll();
            }
        },
    });

    container.append(newFolder);
}

async function renderList() {
    const container = document.getElementById("note-list");

    if (!container || !runtime.db) {
        return;
    }

    const notes = await filteredNotes();
    container.replaceChildren();

    if (notes.length === 0) {
        container.append(el("p", {
            class: "p-4 text-sm text-zinc-500",
            text: uiState.search ? "No notes match your search." : "No notes here yet.",
        }));
        return;
    }

    for (const note of notes) {
        const active = note.portable_id === uiState.selectedNoteId;
        const preview = plainText(note.content);
        const item = el("button", {
            type: "button",
            class: `block w-full rounded-lg border p-3 text-left ${active ? "border-black dark:border-white" : "border-zinc-200 dark:border-zinc-800"}`,
            onclick: () => {
                uiState.selectedNoteId = note.portable_id;
                renderAll();
            },
        });

        const heading = el("div", { class: "flex items-center gap-2" });
        heading.append(el("span", { class: "flex-1 truncate font-medium", text: note.title || "Untitled" }));

        if (note.sync_state === "pending" || note.sync_state === "conflict") {
            const dot = note.sync_state === "conflict" ? "bg-red-500" : "bg-amber-500";
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
            uiState.editorNoteId = null;
        }

        panel.replaceChildren(
            el("div", {
                class: "flex min-h-[50vh] items-center justify-center rounded-lg border border-dashed p-6 text-sm text-zinc-500",
                text: "Select a note, or capture a thought to start.",
            }),
        );

        return;
    }

    const mountInDom = uiState.editor
        && document.getElementById("note-editor") === uiState.editorMount;

    if (uiState.editorNoteId !== note.portable_id || !mountInDom) {
        if (uiState.editor) {
            uiState.editor.destroy();
        }

        panel.replaceChildren(
            el("div", { class: "space-y-3" },
                [
                    el("div", { class: "flex items-center gap-2" }, [
                        el("input", {
                            id: "note-title",
                            type: "text",
                            class: "w-full rounded-lg border px-3 py-2 text-lg font-semibold dark:bg-zinc-900 dark:border-zinc-800",
                            value: note.title,
                            placeholder: "Title",
                        }),
                        el("select", {
                            id: "note-folder",
                            class: "rounded-lg border px-2 py-2 text-sm dark:bg-zinc-900 dark:border-zinc-800",
                        }),
                    ]),
                    el("div", { id: "note-editor", class: "min-h-[180px] rounded-lg border p-3 dark:border-zinc-800" }),
                    el("div", { class: "flex flex-wrap items-center gap-2" }, [
                        el("button", {
                            type: "button",
                            class: "rounded-lg bg-black px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-black",
                            text: "Save",
                            onclick: async () => {
                                const title = document.getElementById("note-title").value;
                                const folderId = document.getElementById("note-folder").value || null;
                                const content = uiState.editor ? uiState.editor.getHTML() : "";

                                if (title.trim() === "") {
                                    notice("A note needs a title.");
                                    return;
                                }

                                await saveNote(note.portable_id, {
                                    title: title.trim(),
                                    content,
                                    folder_portable_id: folderId,
                                });
                            },
                        }),
                        note.deleted_at
                            ? el("button", {
                                type: "button",
                                class: "rounded-lg border px-4 py-2 text-sm font-medium",
                                text: "Restore",
                                onclick: async () => {
                                    await restoreNote(note.portable_id);
                                },
                            })
                            : el("button", {
                                type: "button",
                                class: "rounded-lg border px-4 py-2 text-sm font-medium",
                                text: "Move to Trash",
                                onclick: async () => {
                                    await trashNote(note.portable_id);
                                },
                            }),
                        note.deleted_at
                            ? el("button", {
                                type: "button",
                                class: "rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-600",
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

        uiState.editor = createTiptapEditor(document.getElementById("note-editor"), note.content ?? "");
        uiState.editorMount = document.getElementById("note-editor");
        uiState.editorNoteId = note.portable_id;
    } else {
        const titleInput = document.getElementById("note-title");
        if (titleInput && document.activeElement !== titleInput) {
            titleInput.value = note.title;
        }
    }
}

async function renderAll() {
    if (!root) {
        return;
    }

    if (!runtime.db) {
        renderSignIn();
        return;
    }

    const folders = await runtime.db.folders.where("user_id").equals(runtime.userId).toArray();

    root.replaceChildren(
        el("div", { class: "mx-auto flex min-h-screen max-w-6xl flex-col gap-4 p-4" }, [
            el("header", { class: "flex items-center justify-between gap-3" }, [
                el("h1", { class: "text-xl font-bold", text: "Notes" }),
                el("div", { id: "status-bar", class: "flex flex-wrap items-center gap-3 text-sm" }),
            ]),
            el("div", { id: "status-notices", class: "flex flex-col gap-2" }),
            el("div", { id: "update-banner", class: "hidden" }),
            el("main", { class: "grid flex-1 gap-4 md:grid-cols-[220px_1fr]" }, [
                el("aside", { class: "space-y-2" }, [
                    el("div", { class: "space-y-2" }, [
                        el("textarea", {
                            id: "capture-text",
                            rows: 3,
                            class: "w-full rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900 dark:border-zinc-800",
                            placeholder: "Capture a thought…",
                        }),
                        el("button", {
                            type: "button",
                            class: "w-full rounded-lg bg-black px-3 py-2 text-sm font-medium text-white dark:bg-white dark:text-black",
                            text: "Capture",
                            onclick: async () => {
                                const area = document.getElementById("capture-text");
                                const parsed = captureToNote(area.value);

                                if (!parsed) {
                                    notice("Write something to capture.");
                                    return;
                                }

                                const portableId = crypto.randomUUID();

                                await runtime.db.notes.put({
                                    portable_id: portableId,
                                    user_id: runtime.userId,
                                    folder_portable_id: null,
                                    title: parsed.title,
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
                                renderAll();
                                syncNow();
                            },
                        }),
                    ]),
                    el("input", {
                        id: "note-search",
                        type: "search",
                        class: "w-full rounded-lg border px-3 py-2 text-sm dark:bg-zinc-900 dark:border-zinc-800",
                        placeholder: "Search notes…",
                        value: uiState.search,
                    }),
                    el("div", { class: "flex items-center gap-2" }, [
                        el("label", { class: "text-sm text-zinc-500", text: "Sort" }),
                        el("select", {
                            id: "note-sort",
                            class: "rounded-lg border px-2 py-1 text-sm dark:bg-zinc-900 dark:border-zinc-800",
                        }, [
                            el("option", { value: "newest", text: "Newest" }),
                            el("option", { value: "oldest", text: "Oldest" }),
                            el("option", { value: "title_az", text: "Title A to Z" }),
                            el("option", { value: "title_za", text: "Title Z to A" }),
                        ]),
                    ]),
                    el("nav", { id: "folder-list", class: "space-y-1" }),
                ]),
                el("section", { class: "grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.2fr)]" }, [
                    el("div", { id: "note-list", class: "space-y-2 lg:max-h-[70vh] lg:overflow-auto" }),
                    el("div", { id: "editor-panel" }),
                ]),
            ]),
        ]),
    );

    const search = document.getElementById("note-search");
    search.addEventListener("input", () => {
        uiState.search = search.value;
        renderList();
    });

    const sort = document.getElementById("note-sort");
    sort.value = uiState.sort;
    sort.addEventListener("change", () => {
        uiState.sort = sort.value;
        renderList();
    });

    renderFolders(folders);
    renderList();
    renderEditor();
    renderStatus();
}

function renderSignIn() {
    const summary = runtime.userId === null
        ? "Sign in to sync your notes."
        : "Your local notes are still here. Sign in again to sync.";

    root.replaceChildren(
        el("div", { class: "mx-auto flex min-h-screen max-w-md flex-col items-center justify-center gap-4 p-6 text-center" }, [
            el("h1", { class: "text-2xl font-bold", text: "Notes" }),
            el("p", { class: "text-sm text-zinc-500", text: summary }),
            el("a", {
                href: "/login",
                class: "rounded-lg bg-black px-4 py-2 text-sm font-medium text-white dark:bg-white dark:text-black",
                text: "Sign in",
            }),
            el("div", { id: "status-bar", class: "flex flex-wrap items-center justify-center gap-3 text-sm" }),
            el("div", { id: "status-notices", class: "flex flex-col gap-2" }),
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
            el("span", { text: "A new version is available." }),
            el("button", {
                type: "button",
                class: "font-semibold underline",
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

        if (navigator.onLine) {
            // The shell can be a cached copy from another session: when
            // online, always confirm identity against a fresh shell.
            const probed = await fetchPageUserId();

            if (probed === null) {
                // Confirmed signed out: never reopen the previous user.
                forgetUserId();
                userId = null;
            } else if (probed !== undefined) {
                userId = probed;
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
        await syncNow();
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
}

boot();
