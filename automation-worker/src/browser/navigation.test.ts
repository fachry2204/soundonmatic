import { describe, expect, it, vi } from 'vitest';
import type { Page } from 'playwright';
import { navigateToReadPage } from './navigation.js';

describe('read navigation recovery', () => {
    it('retries a timed out confirmation page without repeating form submission', async () => {
        const error = Object.assign(new Error('slow navigation'), { name: 'TimeoutError' });
        const goto = vi.fn().mockRejectedValueOnce(error).mockResolvedValueOnce(null);
        await navigateToReadPage({ goto } as unknown as Page, 'https://www.soundon.global/library/list?type=draft');
        expect(goto).toHaveBeenCalledTimes(2);
        expect(goto).toHaveBeenLastCalledWith('https://www.soundon.global/library/list?type=draft', { waitUntil: 'domcontentloaded', timeout: 60_000 });
    });

    it('bounds retries and propagates other failures immediately', async () => {
        const timeout = Object.assign(new Error('slow navigation'), { name: 'TimeoutError' });
        const goto = vi.fn().mockRejectedValue(timeout);
        await expect(navigateToReadPage({ goto } as unknown as Page, 'https://example.test')).rejects.toBe(timeout);
        expect(goto).toHaveBeenCalledTimes(2);
        const failure = new Error('page closed');
        goto.mockClear().mockRejectedValue(failure);
        await expect(navigateToReadPage({ goto } as unknown as Page, 'https://example.test')).rejects.toBe(failure);
        expect(goto).toHaveBeenCalledTimes(1);
    });
    it('retries a connection reset before entering the verification form', async () => {
        const goto = vi.fn().mockRejectedValueOnce(new Error('page.goto: net::ERR_CONNECTION_RESET')).mockResolvedValueOnce(null);
        await navigateToReadPage({ goto } as unknown as Page, 'https://cms.soundfresh.id/admin/releases/4296');
        expect(goto).toHaveBeenCalledTimes(2);
    });
});
