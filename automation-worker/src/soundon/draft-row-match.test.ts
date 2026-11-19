import { describe, expect, it } from 'vitest';
import { readDraftTableRow, uniqueDraftRow } from './draft-row-match.js';

describe('unique draft row', () => {
    const expected = { title: 'Ember City', artist: 'Phonk Forge', trackCount: 10 };
    const row = { title: 'Ember City', artist: 'Phonk Forge', trackCount: '10' };
    it('matches the title, artist and track count together', () => {
        expect(uniqueDraftRow([row], expected)).toEqual(row);
        expect(uniqueDraftRow([{ ...row, trackCount: '9' }], expected)).toBeNull();
        expect(uniqueDraftRow([{ ...row, artist: 'Other' }], expected)).toBeNull();
    });
    it('fails closed for multiple candidates or missing metadata', () => {
        expect(uniqueDraftRow([row, row], expected)).toBeNull();
        expect(uniqueDraftRow([row], { ...expected, trackCount: 0 })).toBeNull();
    });
    it('reads the separate table cells and keeps the row key opaque', () => {
        const cells: Record<string, string> = {
            '[aria-colindex="2"] .so-album-preview-item-title-text': 'Ember City',
            '[aria-colindex="3"] .semi-tag-content': 'Phonk Forge',
            '[aria-colindex="4"]': '10',
        };
        const row = {
            querySelector: (selector: string) => cells[selector] ? { textContent: cells[selector] } : null,
            getAttribute: (name: string) => name === 'data-row-key' ? '7709712260272785414' : null,
        } as unknown as Element;
        expect(readDraftTableRow(row)).toEqual({ title: 'Ember City', artist: 'Phonk Forge', trackCount: '10', rowKey: '7709712260272785414' });
    });
});
