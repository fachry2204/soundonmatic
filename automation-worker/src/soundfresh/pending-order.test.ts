import { describe, expect, it } from 'vitest';
import { oldestUploadedFirst, uploadedAtIso } from './pending-order.js';

describe('Soundfresh pending upload priority', () => {
    it('orders all pages by Uploaded Add oldest first and leaves missing dates last', () => {
        const items = [
            { id: 'new', uploaded_at: uploadedAtIso('November 13, 2026') },
            { id: 'unknown', uploaded_at: uploadedAtIso('') },
            { id: 'old', uploaded_at: uploadedAtIso('November 9, 2026') },
            { id: 'middle', uploaded_at: uploadedAtIso('November 10, 2026') },
        ];
        expect(oldestUploadedFirst(items).map(item => item.id)).toEqual(['old', 'middle', 'new', 'unknown']);
        expect(oldestUploadedFirst(items).slice(0, 2).map(item => item.id)).toEqual(['old', 'middle']);
    });
});
