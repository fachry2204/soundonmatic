import { describe, expect, it, vi } from "vitest";
import { verifySoundfreshRelease } from "./verify-release.js";

describe("verifySoundfreshRelease", () => {
    it("fills UPC and every track ISRC before verifying", async () => {
        const upc = { count: vi.fn(async () => 1), fill: vi.fn(async () => undefined) };
        const isrc1 = { count: vi.fn(async () => 1), fill: vi.fn(async () => undefined) };
        const isrc2 = { count: vi.fn(async () => 1), fill: vi.fn(async () => undefined) };
        const verify = { count: vi.fn(async () => 1), click: vi.fn(async () => undefined) };
        const inputs = { filter: vi.fn(() => ({ count: vi.fn(async () => 3), nth: vi.fn((index: number) => [upc, isrc1, isrc2][index]) })) };
        const dialog = {
            count: vi.fn(async () => 1), waitFor: vi.fn(async () => undefined), isVisible: vi.fn(async () => false),
            locator: vi.fn(),
            getByRole: vi.fn(() => ({ filter: vi.fn(() => ({ last: vi.fn(() => verify) })) })),
        };
        const page = {
            url: vi.fn(() => "https://cms.soundfresh.id/admin/releases/123"),
            getByRole: vi.fn((role: string) => role === "dialog"
                ? { filter: vi.fn(() => ({ first: vi.fn(() => dialog) })) }
                : { filter: vi.fn(() => ({ first: vi.fn(() => verify), last: vi.fn(() => verify) })) }),
            locator: vi.fn((selector: string) => {
                if (selector === 'input[name="upc"]') return { last: vi.fn(() => ({ ...upc, count: vi.fn(async () => 1) })) };
                if (selector === 'input[name^="isrc["]') return { count: vi.fn(async () => 2), nth: vi.fn((index: number) => [isrc1, isrc2][index]) };
                return inputs;
            }),
            getByText: vi.fn((pattern: RegExp) => {
                const input = pattern.test("UPC") ? upc : pattern.test("Track 1 ISRC") ? isrc1 : isrc2;
                return { filter: vi.fn(() => ({ first: vi.fn(() => ({
                    count: vi.fn(async () => 1),
                    locator: vi.fn(() => ({ filter: vi.fn(() => ({ first: vi.fn(() => ({ ...input, count: vi.fn(async () => 1) })) })) })),
                })) })) };
            }),
            waitForTimeout: vi.fn(async () => undefined),
        } as any;

        await verifySoundfreshRelease(page, {
            upc: "1234567890123",
            isrcs: ["IDABC2612345", "IDABC2612346"],
        });

        expect(upc.fill).toHaveBeenCalledWith("1234567890123");
        expect(isrc1.fill).toHaveBeenCalledWith("IDABC2612345");
        expect(isrc2.fill).toHaveBeenCalledWith("IDABC2612346");
        expect(verify.click).toHaveBeenCalledTimes(2);
    });
});
