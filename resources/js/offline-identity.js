export function resolveIdentityForSync({ currentUserId, probedUserId, online = true }) {
    if (!online) {
        return { status: "offline", userId: currentUserId };
    }

    if (probedUserId === undefined) {
        return { status: "unknown", userId: currentUserId };
    }

    if (probedUserId === null) {
        return { status: "signed-out", userId: null };
    }

    if (probedUserId !== currentUserId) {
        return { status: "changed", userId: probedUserId };
    }

    return { status: "same", userId: currentUserId };
}
