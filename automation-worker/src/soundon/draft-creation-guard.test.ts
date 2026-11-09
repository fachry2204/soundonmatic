import { describe, expect, it } from 'vitest';
import { readFileSync } from 'node:fs';
import { reserveDraftCreation } from './draft-creation-guard.js';

describe('draft creation reservation', () => {
    it('blocks the same title and artist across jobs, including punctuation and whitespace variants', () => {
        const release = reserveDraftCreation('  My Song ', 'Starkid’s');
        try {
            expect(release).not.toBeNull();
            expect(reserveDraftCreation('my   song', "STARKID'S")).toBeNull();
        } finally { release?.(); }
        const retry = reserveDraftCreation('My Song', "Starkid's");
        expect(retry).not.toBeNull();
        retry?.();
    });
    it('does not block a different artist or release a newer reservation twice', () => {
        const first = reserveDraftCreation('A Song', 'Artist A');
        const other = reserveDraftCreation('A Song', 'Artist B');
        expect(other).not.toBeNull();
        first?.();
        const newer = reserveDraftCreation('A Song', 'Artist A');
        first?.();
        try { expect(reserveDraftCreation('A Song', 'Artist A')).toBeNull(); }
        finally { newer?.(); other?.(); }
    });
    it('requires title and artist and cannot bypass the final check using duplicates_checked', () => {
        expect(() => reserveDraftCreation('', 'Artist')).toThrow('DRAFT_LOOKUP_METADATA_REQUIRED');
        const server = readFileSync(new URL('../server.ts', import.meta.url), 'utf8');
        const createRoute = server.split('app.post("/v1/soundon/drafts/create"')[1]?.split('app.post("/v1/soundon/drafts/find"')[0] ?? '';
        expect(createRoute).toContain('reserveDraftCreation(duplicateTitle, duplicateArtist)');
        expect(createRoute).toContain('await findDraft(page, duplicateTitle, duplicateArtist)');
        expect(createRoute).not.toContain('body.duplicates_checked');
        expect(createRoute).toContain('finally { releaseReservation(); }');
    });
});
