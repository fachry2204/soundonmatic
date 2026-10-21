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
