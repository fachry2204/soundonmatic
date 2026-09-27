import {
    chromium,
    type BrowserContext,
    type BrowserContextOptions,
} from "playwright";
import { mkdir } from "node:fs/promises";
import { resolve } from "node:path";
const contextBrowsers = new WeakMap<BrowserContext, Awaited<ReturnType<typeof chromium.launch>>>();
const launchOptions = () => ({
    headless: process.env.BROWSER_HEADLESS !== "false",
    ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE
        ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE }
        : {}),
});
export async function context(
    platform: "soundfresh" | "soundon",
): Promise<BrowserContext> {
    const dir = resolve("storage", "profiles", platform);
    await mkdir(dir, { recursive: true });
    const browserContext = await chromium.launchPersistentContext(dir, {
        ...launchOptions(),
        acceptDownloads: true,
    });
    await browserContext.addInitScript("window.__name = window.__name || function(fn) { return fn; };");
    return browserContext;
}
export async function sessionContext(
    state?: BrowserContextOptions["storageState"],
): Promise<BrowserContext> {
    const browser = await chromium.launch(launchOptions());
    try {
        const browserContext = await browser.newContext({
            storageState: state,
            acceptDownloads: true,
        });
        await browserContext.addInitScript("window.__name = window.__name || function(fn) { return fn; };");
        contextBrowsers.set(browserContext, browser);

        return browserContext;
    } catch (error) {
        await browser.close().catch(() => undefined);
        throw error;
    }
}

/** Close the isolated context and the Chromium process that owns it. */
export async function closeContext(browserContext: BrowserContext): Promise<void> {
    const browser = contextBrowsers.get(browserContext);
    contextBrowsers.delete(browserContext);

    await browserContext.close().catch(() => undefined);
    if (browser) {
        await browser.close().catch(() => undefined);
    }
}
