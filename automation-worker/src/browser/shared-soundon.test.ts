import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const mocked = vi.hoisted(() => ({ pages: [] as Array<{ close: ReturnType<typeof vi.fn> }>, closed: vi.fn(), create: vi.fn() }));
vi.mock('./context-manager.js', () => ({
    sessionContext: mocked.create,
    closeContext: mocked.closed,
}));
import { acquireSoundOnPage, closeIdleSoundOnContext } from './shared-soundon.js';

describe('shared SoundOn browser', () => {
    afterEach(async () => { await closeIdleSoundOnContext(); });
    beforeEach(() => {
        mocked.pages.length = 0; mocked.closed.mockReset(); mocked.create.mockReset();
        mocked.create.mockImplementation(async (initialState) => ({ storageState: vi.fn().mockResolvedValue(initialState), newPage: async () => {
            const page = { close: vi.fn().mockResolvedValue(undefined) }; mocked.pages.push(page); return page;
        } }));
    });
    const state = { cookies: [{ name: 'session', value: 'same', domain: '.soundon.global', path: '/', expires: -1, httpOnly: true, secure: true, sameSite: 'Lax' as const }], origins: [] };
    it('bounds status-check waiting without closing an active upload', async () => {
        const active = await acquireSoundOnPage(state);
        try {
            const other = { ...state, cookies: [{ ...state.cookies[0]!, value: 'other' }] };
            await expect(acquireSoundOnPage(other, { waitTimeoutMs: 20 })).rejects.toThrow('SOUNDON_SHARED_SESSION_WAIT_TIMEOUT');
            expect(mocked.closed).not.toHaveBeenCalled();
            expect(mocked.create).toHaveBeenCalledTimes(1);
        } finally { await active.release(); }
    });
    it('shares one context across three pages and retains it between operations', async () => {
        const [a, b, c] = await Promise.all([acquireSoundOnPage(state), acquireSoundOnPage(state), acquireSoundOnPage(state)]);
        expect(mocked.create).toHaveBeenCalledTimes(1);
        expect(new Set([a.page, b.page, c.page]).size).toBe(3);
        await b.release(); expect(mocked.pages[1]?.close).toHaveBeenCalledTimes(1); expect(mocked.closed).not.toHaveBeenCalled();
        await Promise.all([a.release(), c.release()]); expect(mocked.closed).not.toHaveBeenCalled();
        const next = await acquireSoundOnPage(state);
        expect(next.context).toBe(a.context);
        expect(mocked.create).toHaveBeenCalledTimes(1);
        await next.release();
        expect(await closeIdleSoundOnContext()).toBe(true);
        expect(mocked.closed).toHaveBeenCalledTimes(1);
    });
    it('waits for active uploads before opening a different session', async () => {
        const a = await acquireSoundOnPage(state);
        const other = { ...state, cookies: [{ ...state.cookies[0]!, value: 'different' }] };
        let acquired = false;
        const pending = acquireSoundOnPage(other).then(value => { acquired = true; return value; });
        await new Promise(resolve => setTimeout(resolve, 25));
        expect(acquired).toBe(false);
        await a.release();
        const b = await pending;
        expect(mocked.create).toHaveBeenCalledTimes(2);
        await b.release();
    });
    it('accepts the current rotated session from the existing context', async () => {
        const rotated = { ...state, cookies: [{ ...state.cookies[0]!, value: 'rotated' }] };
        const a = await acquireSoundOnPage(state);
        vi.mocked(a.context.storageState).mockResolvedValue(rotated);
        const b = await acquireSoundOnPage(rotated);
        expect(mocked.create).toHaveBeenCalledTimes(1);
        await Promise.all([a.release(), b.release()]);
    });
    it('does not close a profile while another upload is active', async () => {
        const a = await acquireSoundOnPage(state);
        expect(await closeIdleSoundOnContext()).toBe(false);
        expect(mocked.closed).not.toHaveBeenCalled();
        await a.release();
        expect(await closeIdleSoundOnContext()).toBe(true);
    });
});
