import { describe, expect, it, vi } from 'vitest';
import { confirmAlbumDraft } from './album-draft-confirmation.js';

describe('album draft save confirmation', () => {
    it('retries transient draft lookup without creating a new draft', async () => {
        const draft = { found: true as const, draft_id: '123', draft_url: 'https://www.soundon.global/draft/123' };
        const lookup = vi.fn().mockRejectedValueOnce(new Error('DRAFT_LOOKUP_UNCONFIRMED:loading')).mockResolvedValueOnce(null).mockResolvedValueOnce(draft);
        const pause = vi.fn().mockResolvedValue(undefined);
        expect(await confirmAlbumDraft(lookup, pause)).toEqual(draft);
        expect(lookup).toHaveBeenCalledTimes(3);
        expect(pause).toHaveBeenCalledTimes(2);
    });
    it('fails closed after unconfirmed results and does not retry authentication errors', async () => {
        await expect(confirmAlbumDraft(async () => null, async () => {})).rejects.toThrow('DRAFT_SAVE_NOT_CONFIRMED');
        const lookup = vi.fn().mockRejectedValue(new Error('AUTH_SESSION_EXPIRED'));
        await expect(confirmAlbumDraft(lookup, async () => {})).rejects.toThrow('AUTH_SESSION_EXPIRED');
        expect(lookup).toHaveBeenCalledTimes(1);
    });
});
