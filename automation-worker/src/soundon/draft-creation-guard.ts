const creating = new Set<string>();
const canonical = (value: string) => value.normalize('NFKC').trim().replace(/[’‘`]/g, "'").replace(/\s+/g, ' ').toLocaleLowerCase();

/** Reserve the title + artist across jobs/runs until the create request exits. */
export function reserveDraftCreation(title: string, artist: string): (() => void) | null {
    if (!canonical(title) || !canonical(artist)) throw new Error('DRAFT_LOOKUP_METADATA_REQUIRED');
    const key = JSON.stringify([canonical(title), canonical(artist)]);
    if (creating.has(key)) return null;
    creating.add(key);
    let released = false;
    return () => {
        if (released) return;
        released = true;
        creating.delete(key);
    };
}
