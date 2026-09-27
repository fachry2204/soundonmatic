import type { Locator, Page } from "playwright";

export type VerifyReleaseIdentifiers = {
    upc: string;
    isrcs: string[];
};

async function inputFollowingLabel(page: Page, label: RegExp): Promise<Locator | null> {
    const labelNode = page.getByText(label, { exact: false }).filter({ visible: true }).first();
    if (!await labelNode.count()) return null;
    const input = labelNode.locator("xpath=following::input[1]").filter({ visible: true }).first();
    return await input.count() ? input : null;
}

export async function verifySoundfreshRelease(page: Page, identifiers: VerifyReleaseIdentifiers): Promise<void> {
    const verifyButton = page.getByRole("button", { name: /^Verify Release$/i }).filter({ visible: true }).first();
    if (!await verifyButton.count()) {
        const pageText = await page.locator("body").innerText().catch(() => "");
        if (/verified|approved|published|release\s+verified/i.test(pageText)) return;
        const buttons = (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 20);
        throw new Error(`SOUNDFRESH_UI_CHANGED:verify_release_button:buttons=${JSON.stringify(buttons)}:url=${page.url()}`);
    }
    await verifyButton.click({ timeout: 20_000 });

    let modal = page.getByRole("dialog").filter({ visible: true }).first();
    if (!await modal.count()) modal = page.locator('.modal.show:visible, [class*="modal" i]:visible').first();
    await modal.waitFor({ state: "visible", timeout: 10_000 }).catch(() => undefined);
    if (!await modal.count()) throw new Error(`SOUNDFRESH_UI_CHANGED:verify_dialog:url=${page.url()}`);
    await page.waitForTimeout(300);

    // The current Soundfresh modal exposes visible text labels without a
    // for/id association, and its fields may be portalled outside the dialog
    // node. Resolve the first input following each visible label.
    const namedUpc = page.locator('input[name="upc"]').last();
    const namedIsrcs = page.locator('input[name^="isrc["]');
    const visibleInputs = page.locator("input").filter({ visible: true });
    const upcInput = await namedUpc.count()
        ? namedUpc
        : await inputFollowingLabel(page, /^UPC$/i) ?? (await visibleInputs.count() ? visibleInputs.nth(0) : null);
    if (!upcInput) {
        const controls = await page.locator('input, textarea, [contenteditable="true"]').evaluateAll((elements) => elements.slice(0, 20).map((element) => ({
            tag: element.tagName.toLowerCase(),
            type: element.getAttribute("type"),
            name: element.getAttribute("name"),
            placeholder: element.getAttribute("placeholder"),
            role: element.getAttribute("role"),
        }))).catch(() => []);
        throw new Error(`SOUNDFRESH_UI_CHANGED:verify_upc_input:frames=${page.frames().length}:controls=${JSON.stringify(controls)}:url=${page.url()}`);
    }
    await upcInput.fill(identifiers.upc);

    for (let index = 0; index < identifiers.isrcs.length; index += 1) {
        const namedInput = (await namedIsrcs.count()) > index ? namedIsrcs.nth(index) : null;
        const input = namedInput ?? await inputFollowingLabel(page, new RegExp(`^Track\\s+${index + 1}\\s+ISRC\\b`, "i"))
            ?? ((await visibleInputs.count()) > index + 1 ? visibleInputs.nth(index + 1) : null);
        if (!input) throw new Error(`SOUNDFRESH_UI_CHANGED:verify_isrc_input_${index + 1}:url=${page.url()}`);
        await input.fill(identifiers.isrcs[index]!);
    }

    const submit = modal.getByRole("button", { name: /^Verify Release$/i }).filter({ visible: true }).last();
    if (!await submit.count()) throw new Error(`SOUNDFRESH_UI_CHANGED:verify_submit:url=${page.url()}`);
    await submit.click({ timeout: 20_000 });
    await modal.waitFor({ state: "hidden", timeout: 20_000 }).catch(() => undefined);
    if (await modal.isVisible().catch(() => false)) {
        const error = await modal.locator('[role="alert"], .alert-danger, .text-danger, [class*="error" i]').first().innerText().catch(() => "");
        throw new Error(`SOUNDFRESH_VERIFY_REJECTED:${error || "verification dialog remained open"}`);
    }
}
