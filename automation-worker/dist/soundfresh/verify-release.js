import { ensureSoundfreshEnglish } from './language.js';
import { primaryReleaseAction, clickReleaseAction } from './release-action.js';
async function inputFollowingLabel(page, label) {
    const labelNode = page.getByText(label, { exact: false }).filter({ visible: true }).first();
    if (!await labelNode.count())
        return null;
    const input = labelNode.locator("xpath=following::input[1]").filter({ visible: true }).first();
    return await input.count() ? input : null;
}
export async function verifySoundfreshRelease(page, identifiers) {
    await ensureSoundfreshEnglish(page);
    const verifyButton = primaryReleaseAction(page, 'Verify Release');
    if (!await verifyButton.count()) {
        // Sidebar labels such as Published do not prove these identifiers saved.
        const buttons = (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 20);
        throw new Error(`SOUNDFRESH_UI_CHANGED:verify_release_button:buttons=${JSON.stringify(buttons)}:url=${page.url()}`);
    }
    await clickReleaseAction(verifyButton);
    const modal = page.getByRole("dialog").or(page.locator('.modal.show, .swal2-popup'))
        .filter({ visible: true }).first();
    await modal.waitFor({ state: "visible", timeout: 10_000 }).catch(() => undefined);
    if (!await modal.count())
        throw new Error(`SOUNDFRESH_UI_CHANGED:verify_dialog:url=${page.url()}`);
    await page.waitForTimeout(300);
    // The current Soundfresh modal exposes visible text labels without a
    // for/id association, and its fields may be portalled outside the dialog
    // node. Resolve the first input following each visible label.
    const namedUpc = page.locator('input[name="upc"]').filter({ visible: true }).last();
    const namedIsrcs = page.locator('input[name^="isrc["]').filter({ visible: true });
    const upcInput = await namedUpc.count()
        ? namedUpc
        : await inputFollowingLabel(page, /^UPC$/i);
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
        const input = namedInput ?? await inputFollowingLabel(page, new RegExp(`^Track\\s+${index + 1}\\s+ISRC\\b`, "i"));
        if (!input)
            throw new Error(`SOUNDFRESH_UI_CHANGED:verify_isrc_input_${index + 1}:url=${page.url()}`);
        await input.fill(identifiers.isrcs[index]);
    }
    const submit = modal.getByRole("button", { name: /^Verify Release$/i }).filter({ visible: true }).last();
    if (!await submit.count())
        throw new Error(`SOUNDFRESH_UI_CHANGED:verify_submit:url=${page.url()}`);
    await clickReleaseAction(submit);
    await modal.waitFor({ state: "hidden", timeout: 20_000 }).catch(() => undefined);
    if (await modal.isVisible().catch(() => false)) {
        const error = await modal.locator('[role="alert"], .alert-danger, .text-danger, [class*="error" i]').first().innerText().catch(() => "");
        throw new Error(`SOUNDFRESH_VERIFY_REJECTED:${error || "verification dialog remained open"}`);
    }
}
