import type { DraftMatch } from './draft-finder.js';

/** Retry only read-only lookups after saving; never create a second draft. */
export async function confirmAlbumDraft(lookup: () => Promise<DraftMatch | null>, pause: () => Promise<void>): Promise<DraftMatch> {
    let lastError: Error | null = null;
    for (let attempt = 0; attempt < 3; attempt++) {
        try {
            const result = await lookup();
            if (result?.draft_id && result.draft_url) return result;
            lastError = new Error('DRAFT_SAVE_NOT_CONFIRMED');
        } catch (error) {
            if (!(error instanceof Error) || !error.message.startsWith('DRAFT_LOOKUP_UNCONFIRMED:')) throw error;
            lastError = error;
        }
        if (attempt < 2) await pause();
    }
    throw new Error(`DRAFT_SAVE_NOT_CONFIRMED:Draft EP/album belum dapat dikonfirmasi. Jangan membuat draft baru sebelum memeriksa SoundOn. ${lastError?.message ?? ''}`);
}
