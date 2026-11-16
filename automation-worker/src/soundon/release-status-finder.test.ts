import { describe, expect, it } from "vitest";
import { chromium } from "playwright";
import { detectReleaseStatus, findReleaseStatuses, parseRejectionReason } from "./release-status-finder.js";

describe("parseRejectionReason", () => {
    it("reads every rejection bullet from the SoundOn release warning", () => {
        const text = [
            "The track Karma Tak Lagi Bisa Dipercaya was not approved due to the following reason(s):",
            "· Instrumental: track with lyrics cannot be marked as instrumental",
            "· Cover art: text is unreadable",
            "Track Information",
            "This text must not be included",
        ].join("\n");

        expect(parseRejectionReason(text)).toBe([
            "Instrumental: track with lyrics cannot be marked as instrumental",
            "Cover art: text is unreadable",
        ].join("\n"));
    });
});

describe("detectReleaseStatus", () => {
    it("detects Live when the row starts with the release title", () => {
        expect(detectReleaseStatus("Ampuni Aku\nA. Robi Firdaus\n2026-09-06\nLive")).toBe("live");
    });
});

describe("findReleaseStatuses", () => {
    it('reports an unrecognized status instead of saying a matching release is absent', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<table><tbody><tr><td>Love Me Too Late<br>Nexvira</td><td>New Status</td></tr></tbody></table>');
            await expect(findReleaseStatuses(page, [{ key: '4070', title: 'Love Me Too Late', artist: 'Nexvira' }])).rejects.toThrow('SOUNDON_STATUS_UNCONFIRMED');
        } finally { await browser.close(); }
    }, 10000);
    it('falls back to artist and selects the exact title and artist among unrelated results', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<input placeholder="Find release by album name or artist name">
                <table><thead><tr><th>Title</th><th>Status</th></tr></thead><tbody></tbody></table>
                <script>document.querySelector('input').addEventListener('input', e => {
                    const query = e.target.value.toLowerCase();
                    setTimeout(() => { document.querySelector('tbody').innerHTML = query === 'nexvira'
                        ? '<tr><td>Click<br>Nexvira</td><td>Delivered</td></tr><tr><td>Love Me Too Late<br>Other Artist</td><td>Live</td></tr><tr><td>Love Me Too Late<br>Nexvira</td><td>Under Review</td></tr>'
                        : '<tr><td class="semi-empty">No results</td></tr>'; }, 100);
                });</script>`);
            const matches = await findReleaseStatuses(page, [{ key: '4070', title: 'Love Me Too Late', artist: 'Nexvira' }]);
            expect(matches['4070']?.status).toBe('under_review');
            expect(await page.locator('input').inputValue()).toBe('Nexvira');
        } finally { await browser.close(); }
    }, 20000);

    it('recognizes the matching title and artist in the third row', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<table><tbody><tr><td>Another</td><td>Live</td></tr><tr><td>Other</td><td>Live</td></tr>
                <tr><td>Kekasih di Balik Bayang<br>Naraya Project</td><td>Under Review</td></tr></tbody></table>`);
            const matches = await findReleaseStatuses(page, [{ key: '4209', title: 'Kekasih di Balik Bayang', artist: 'Naraya Project' }]);
            expect(matches['4209']?.status).toBe('under_review');
        } finally { await browser.close(); }
    }, 10000);
    it("waits for delayed SoundOn search results and recognizes the delivered release", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`
                <input placeholder="Find release by album name or artist name">
                <table><thead><tr><th>Title</th><th>Release Status</th></tr></thead><tbody></tbody></table>
                <script>
                document.querySelector('input').addEventListener('input', () => {
                    setTimeout(() => { document.querySelector('tbody').innerHTML = '<tr><td>Broken Porch Wood<br>Rankin Dale Whitsett</td><td>Delivered</td></tr>'; }, 2500);
                });
                </script>`);
            const matches = await findReleaseStatuses(page, [{ key: '4166', title: 'Broken Porch Wood', artist: 'Rankin Dale Whitsett' }]);
            expect(matches['4166']?.status).toBe('delivery');
        } finally { await browser.close(); }
    }, 20000);

    it("finds a unique title when SoundOn renders nested row and card wrappers", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.route("http://soundon.test/**", (route) => route.fulfill({ body: "" }));
            await page.goto("http://soundon.test/");
            await page.setContent(`
                <div class="release-card" role="row">
                    <table><tbody><tr>
                        <td><a href="/library/release/123">Meski Jauh Kau Tetap Di Hati</a></td>
                        <td>Inonok feat. Another Artist</td>
                        <td>Under Review</td>
                    </tr></tbody></table>
                </div>
            `);

            const matches = await findReleaseStatuses(page, [{
                key: "release-123",
                title: "Meski Jauh Kau Tetap Di Hati",
                artist: "Inonok",
            }]);

            expect(matches["release-123"]?.status).toBe("under_review");
            expect(matches["release-123"]?.release_url).toBe("http://soundon.test/library/release/123");
        } finally {
            await browser.close();
        }
    });
});
