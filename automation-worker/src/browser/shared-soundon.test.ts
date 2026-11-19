import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocked = vi.hoisted(() => ({ pages: [] as Array<{ close: ReturnType<typeof vi.fn> }>, closed: vi.fn(), create: vi.fn() }));
vi.mock('./context-manager.js', () => ({
    sessionContext: mocked.create,
    closeContext: mocked.closed,
}));
import { acquireSoundOnPage } from './shared-soundon.js';

describe('shared SoundOn browser', () => {
    beforeEach(() => {
        mocked.pages.length = 0; mocked.closed.mockReset(); mocked.create.mockReset();
        mocked.create.mockImplementation(async () => ({ newPage: async () => {
            const page = { close: vi.fn().mockResolvedValue(undefined) }; mocked.pages.push(page); return page;
        } }));
    });
    const state = { cookies: [{ name: 'session', value: 'same', domain: '.soundon.global', path: '/', expires: -1, httpOnly: true, secure: true, sameSite: 'Lax' as const }], origins: [] };
    it('shares one context across three pages and only closes it after the last release', async () => {
        const [a, b, c] = await Promise.all([acquireSoundOnPage(state), acquireSoundOnPage(state), acquireSoundOnPage(state)]);
        expect(mocked.create).toHaveBeenCalledTimes(1);
        expect(new Set([a.page, b.page, c.page]).size).toBe(3);
        await b.release(); expect(mocked.pages[1]?.close).toHaveBeenCalledTimes(1); expect(mocked.closed).not.toHaveBeenCalled();
        await Promise.all([a.release(), c.release()]); expect(mocked.closed).toHaveBeenCalledTimes(1);
    });
    it('refuses to mix a different session into active uploads', async () => {
        const a = await acquireSoundOnPage(state);
        const other = { ...state, cookies: [{ ...state.cookies[0]!, value: 'different' }] };
        await expect(acquireSoundOnPage(other)).rejects.toThrow('SOUNDON_SHARED_SESSION_CHANGED');
        await a.release();
    });
});
