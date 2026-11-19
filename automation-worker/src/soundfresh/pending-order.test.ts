import { describe, expect, it } from 'vitest';
import { earliestReleaseFirst, uploadedAtIso } from './pending-order.js';

describe('Soundfresh pending upload priority', () => {
    it('orders by release date, preserves ties and leaves invalid dates last', () => {
        const items = [
            { id: 'late', release_date: 'November 28, 2026', uploaded_at: uploadedAtIso('November 9, 2026') },
            { id: 'unknown', release_date: null, uploaded_at: uploadedAtIso('') },
            { id: 'near', release_date: 'November 20, 2026', uploaded_at: uploadedAtIso('November 13, 2026') },
            { id: 'same', release_date: '2026-11-20', uploaded_at: null },
            { id: 'invalid', release_date: 'not a date', uploaded_at: null },
        ];
        expect(earliestReleaseFirst(items).map(item => item.id)).toEqual(['near', 'same', 'late', 'unknown', 'invalid']);
    });
});
