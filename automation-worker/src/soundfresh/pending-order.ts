/** Soundfresh's Uploaded At/Add date is independent of the planned release date. */
export function uploadedAtIso(value: string): string | null {
    const text = value.replace(/\s+/g, ' ').trim();
    if (!text || !/\d/.test(text)) return null;
    const time = Date.parse(text);
    return Number.isFinite(time) ? new Date(time).toISOString() : null;
}

export function oldestUploadedFirst<T extends { uploaded_at: string | null }>(items: T[]): T[] {
    return items.map((item, index) => ({ item, index }))
        .sort((a, b) => (a.item.uploaded_at ?? '\uffff').localeCompare(b.item.uploaded_at ?? '\uffff') || a.index - b.index)
        .map(({ item }) => item);
}
