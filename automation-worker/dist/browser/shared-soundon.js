import { createHash } from 'node:crypto';
import { closeContext, sessionContext } from './context-manager.js';
let shared;
let identity;
let leases = 0;
let lock = Promise.resolve();
export const soundOnSessionInUse = () => leases > 0;
function fingerprint(state) {
    if (!state || typeof state === 'string')
        throw new Error('SOUNDON_SHARED_SESSION_REQUIRED');
    // Ignore analytics and page-preference cookies that can change between
    // requests; only account/session-bearing values decide whether two jobs
    // can safely share a browser profile.
    const relevant = state.cookies.filter(cookie => /session|auth|token|(?:^|_)sid|uid/i.test(cookie.name));
    const cookies = (relevant.length ? relevant : state.cookies).map(cookie => [cookie.domain, cookie.name, cookie.value].join('\0')).sort();
    const origins = cookies.length ? [] : state.origins.map(origin => [origin.origin, ...origin.localStorage.map(entry => `${entry.name}\0${entry.value}`).sort()]).sort();
    if (!cookies.length && !origins.some(origin => origin.length > 1))
        throw new Error('SOUNDON_SHARED_SESSION_REQUIRED');
    return createHash('sha256').update(JSON.stringify([cookies, origins])).digest('hex');
}
/** Each job owns one page; the authenticated browser context is shared. */
export async function acquireSoundOnPage(state) {
    const token = fingerprint(state);
    const previous = lock;
    let unlock;
    lock = new Promise(resolve => { unlock = resolve; });
    await previous;
    try {
        if (shared && identity !== token)
            throw new Error('SOUNDON_SHARED_SESSION_CHANGED:Wait for active uploads to finish before switching sessions');
        if (!shared) {
            shared = await sessionContext(state);
            identity = token;
        }
        const browser = shared;
        let page;
        try {
            page = await browser.newPage();
        }
        catch (error) {
            if (leases === 0 && shared === browser) {
                shared = undefined;
                identity = undefined;
                await closeContext(browser);
            }
            throw error;
        }
        leases++;
        let released = false;
        return { context: browser, page, release: async () => {
                if (released)
                    return;
                released = true;
                await page.close().catch(() => undefined);
                const before = lock;
                let done;
                lock = new Promise(resolve => { done = resolve; });
                await before;
                try {
                    leases--;
                    if (leases === 0 && shared === browser) {
                        shared = undefined;
                        identity = undefined;
                        await closeContext(browser);
                    }
                }
                finally {
                    done();
                }
            } };
    }
    finally {
        unlock();
    }
}
