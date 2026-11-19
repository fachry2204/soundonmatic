/** Soundfresh's Uploaded At/Add date is independent of the planned release date. */
export function uploadedAtIso(value) {
    const text = value.replace(/\s+/g, ' ').trim();
    if (!text || !/\d/.test(text))
        return null;
    const time = Date.parse(text);
    return Number.isFinite(time) ? new Date(time).toISOString() : null;
}
export function earliestReleaseFirst(items) {
    const priority = (value) => {
        if (!value || !value.trim())
            return Number.POSITIVE_INFINITY;
        const date = Date.parse(value);
        return Number.isFinite(date) ? date : Number.POSITIVE_INFINITY;
    };
    return items.map((item, index) => ({ item, index }))
        .sort((a, b) => priority(a.item.release_date) - priority(b.item.release_date) || a.index - b.index)
        .map(({ item }) => item);
}
