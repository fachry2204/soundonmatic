import { describe, expect, it } from "vitest";
import { detectReleaseStatus, parseRejectionReason } from "./release-status-finder.js";
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
