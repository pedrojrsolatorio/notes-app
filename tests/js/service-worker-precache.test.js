import assert from "node:assert/strict";
import test from "node:test";

const listeners = {};
const cache = {
    addAll: async (paths) => {
        cache.addAllCalls.push(...paths);
    },
    add: async (path) => {
        cache.addCalls.push(path);
    },
    put: async () => {},
    match: async () => undefined,
};

cache.addAllCalls = [];
cache.addCalls = [];

const shell = `
<link rel="stylesheet" href="http://localhost:8000/build/assets/app.css">
<script src="/build/assets/app.js"></script>
<script src="https://cdn.example.com/build/assets/remote.js"></script>
`;

globalThis.self = {
    location: { origin: "http://localhost:8000" },
    addEventListener: (name, listener) => {
        listeners[name] = listener;
    },
};
globalThis.caches = {
    open: async () => cache,
    keys: async () => [],
    delete: async () => {},
};
globalThis.fetch = async () => new Response(shell, { status: 200 });

await import("../../public/sw.js");

test("[AC-17] precaches absolute and relative build assets from the app shell", async () => {
    let installPromise;

    listeners.install({ waitUntil: (promise) => { installPromise = promise; } });
    await installPromise;

    assert.ok(cache.addAllCalls.includes("/app"));
    assert.deepEqual(cache.addCalls, ["/build/assets/app.css", "/build/assets/app.js"]);
});
