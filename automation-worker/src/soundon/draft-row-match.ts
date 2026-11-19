/** Data read from one SoundOn Drafts table row, not from the entire page. */
export type DraftRow = { title: string; artist: string; trackCount: string };

const normalize = (value: string): string => value.normalize('NFKC')
    .trim().replace(/[’‘`]/g, "'").replace(/\s+/g, ' ').toLocaleLowerCase();

/** Return a row only if exactly one candidate agrees on all three fields. */
export function uniqueDraftRow(rows: DraftRow[], expected: { title: string; artist: string; trackCount: number }): DraftRow | null {
    if (!normalize(expected.title) || !normalize(expected.artist)
        || !Number.isSafeInteger(expected.trackCount) || expected.trackCount < 1) return null;
    const matches = rows.filter(row => normalize(row.title) === normalize(expected.title)
        && normalize(row.artist) === normalize(expected.artist)
        && /^\d+$/.test(row.trackCount.trim())
        && Number(row.trackCount.trim()) === expected.trackCount);
    return matches.length === 1 ? matches[0] ?? null : null;
}

/** Parse only a complete table row. The row key is retained as an opaque
 * identifier; it is not assumed to be a SoundOn draft ID. */
export function readDraftTableRow(row: Element): (DraftRow & { rowKey: string }) | null {
    const title = row.querySelector('[aria-colindex="2"] .so-album-preview-item-title-text')?.textContent?.trim() ?? '';
    const artist = row.querySelector('[aria-colindex="3"] .semi-tag-content')?.textContent?.trim() ?? '';
    const trackCount = row.querySelector('[aria-colindex="4"]')?.textContent?.trim() ?? '';
    const rowKey = row.getAttribute('data-row-key')?.trim() ?? '';
    if (!title || !artist || !/^\d+$/.test(trackCount) || !rowKey) return null;
    return { title, artist, trackCount, rowKey };
}
