import { createHash } from 'node:crypto';
import { closeContext, sessionContext } from './context-manager.js';
let shared;
const identities = new Set();
const idleWaiters = new Set();
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
    const origins = state.origins.map(origin => [origin.origin, ...origin.localStorage
            .filter(entry => !cookies.length || /session|auth|token|(?:^|_)sid|uid/i.test(entry.name))
            .map(entry => `${entry.name}\0${entry.value}`).sort()]).filter(origin => origin.length > 1).sort();
    if (!cookies.length && !origins.some(origin => origin.length > 1))
        throw new Error('SOUNDON_SHARED_SESSION_REQUIRED');
    return createHash('sha256').update(JSON.stringify([cookies, origins])).digest('hex');
}
/** Each job owns one page; the authenticated browser context is shared. */
export async function acquireSoundOnPage(state) {
    const token = fingerprint(state);
    while (true) {
        const previous = lock;
        let unlock;
        lock = new Promise(resolve => { unlock = resolve; });
        await previous;
        try {
            if (shared && !identities.has(token)) {
                // Cookies can rotate within the same active browser context. Trust
                // its current snapshot as well as previously observed snapshots;
                // never merge an unverified different session into that context.
                const liveToken = fingerprint(await shared.storageState({ indexedDB: true }));
                identities.add(liveToken);
                if (!identities.has(token)) {
                    let wake;
                    const idle = new Promise(resolve => { wake = resolve; idleWaiters.add(resolve); });
                    unlock();
                    let timer;
                    try {
                        await Promise.race([idle, new Promise((_, reject) => {
                                timer = setTimeout(() => reject(new Error('SOUNDON_SHARED_SESSION_WAIT_TIMEOUT:Menunggu sesi SoundOn aktif selesai terlalu lama.')), 1_200_000);
                            })]);
                    }
                    finally {
                        idleWaiters.delete(wake);
                        if (timer)
                            clearTimeout(timer);
                    }
                    continue;
                }
            }
            if (!shared) {
                shared = await sessionContext(state);
                identities.clear();
                identities.add(token);
            }
            const browser = shared;
            let page;
            try {
                page = await browser.newPage();
            }
            catch (error) {
                if (leases === 0 && shared === browser) {
                    shared = undefined;
                    identities.clear();
                    await closeContext(browser);
                    for (const wake of idleWaiters)
                        wake();
                    idleWaiters.clear();
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
                            identities.clear();
                            await closeContext(browser);
                            for (const wake of idleWaiters)
                                wake();
                            idleWaiters.clear();
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
}
