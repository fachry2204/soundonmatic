import type { Locator, Page } from "playwright";

async function rejectReasonEditor(modal: Locator): Promise<Locator | null> {
    // Reject may use an AJAX-loaded textarea, Quill contenteditable, or a
    // TinyMCE/CKEditor iframe. Never fill a different form elsewhere on page.
    const candidates = modal.locator('textarea,[contenteditable="true"],[contenteditable="plaintext-only"],input[name*="reason" i],input[id*="reason" i]')
        .filter({ visible: true });
    if (await candidates.count() === 1 && await candidates.first().isEditable()) return candidates.first();
    const named = modal.getByRole('textbox', { name: /reason|alasan|description|deskripsi/i }).filter({ visible: true });
    if (await named.count() === 1 && await named.first().isEditable()) return named.first();
    const frames = modal.locator('iframe:visible');
    for (let index = 0; index < await frames.count(); index++) {
        const editor = modal.frameLocator('iframe:visible').nth(index)
            .locator('body[contenteditable="true"],[contenteditable="true"],[role="textbox"]').filter({ visible: true });
        if (await editor.count() === 1 && await editor.first().isEditable()) return editor.first();
    }
    const textbox = modal.getByRole('textbox').filter({ visible: true });
    return await textbox.count() === 1 && await textbox.first().isEditable() ? textbox.first() : null;
}

export async function rejectSoundfreshRelease(page: Page, reason: string): Promise<void> {
    // Current Soundfresh pages expose Reject Release as a primary action.
    // Use the button directly; only fall back to the legacy Verify dropdown
    // when that direct action is genuinely absent.
    let rejectButton = page.getByRole("button", { name: /^Reject Release$/i }).filter({ visible: true }).first();
    if (!await rejectButton.count()) {
        rejectButton = page.getByText(/^Reject Release$/i, { exact: true }).filter({ visible: true }).first();
    }
    if (!await rejectButton.count()) {
        // Soundfresh exposes the arrow as a separate "Toggle Dropdown"
        // button. Prefer it over the main Verify Release action.
        let dropdown = page.getByRole("button", { name: /Toggle Dropdown/i }).filter({ visible: true }).first();
        if (!await dropdown.count()) dropdown = page.locator([
            '[data-bs-toggle="dropdown"]',
            '[data-toggle="dropdown"]',
            'button.dropdown-toggle',
            'button[aria-haspopup="menu"]',
            'button[aria-expanded]',
        ].join(",")).filter({ hasText: /Verify Release/i }).filter({ visible: true }).first();
        if (!await dropdown.count()) {
            dropdown = page.locator('.btn-group').filter({ has: page.getByRole('button', { name: /^(Review Release|Verify Release|Upload Release)$/i }) })
                .locator('.dropdown-toggle-split,[data-bs-toggle="dropdown"],[data-toggle="dropdown"]')
                .filter({ visible: true }).first();
        }
        if (!await dropdown.count()) {
            dropdown = page.getByText(/Toggle Dropdown/i).filter({ visible: true }).first();
        }
        if (!await dropdown.count()) {
            const buttons = (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 20);
            throw new Error(`SOUNDFRESH_UI_CHANGED:verify_release_dropdown:buttons=${JSON.stringify(buttons)}:url=${page.url()}`);
        }
        // Do not toggle an already-open menu closed. Soundfresh sometimes
        // restores the Verify Release menu in its expanded state on reload.
        const expanded = typeof (dropdown as any).getAttribute === "function"
            ? await dropdown.getAttribute("aria-expanded").catch(() => null)
            : null;
        if (expanded !== "true") {
            await dropdown.click({ timeout: 20_000 });
        }
        await page.waitForTimeout(300);
        // getByText resolves the deepest visible text node; broad ancestor
        // selectors can accidentally choose the whole header btn-group,
        // which is then blocked when the confirmation modal opens.
        rejectButton = page.getByText(/Reject\s+Release/i).filter({ visible: true }).first();
    }
    if (!await rejectButton.count()) {
        const buttons = (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 20);
        throw new Error(`SOUNDFRESH_UI_CHANGED:reject_release_button:buttons=${JSON.stringify(buttons)}:url=${page.url()}`);
    }
    await rejectButton.click({ timeout: 20_000 });

    const modal = page.locator('dialog[open],[role="dialog"],[role="alertdialog"],.modal.show,.swal2-popup')
        .filter({ hasText: /reject|tolak|penolakan/i }).filter({ visible: true }).last();
    await modal.waitFor({ state: "visible", timeout: 10_000 });

    const deadline = Date.now() + 10_000;
    let reasonInput: Locator | null = null;
    do {
        reasonInput = await rejectReasonEditor(modal);
        if (reasonInput) break;
        await page.waitForTimeout(150);
    } while (Date.now() < deadline && await modal.isVisible().catch(() => false));
    if (!reasonInput) {
        const controls = await modal.locator('input,textarea,[contenteditable],iframe').evaluateAll(elements => elements.map(element => ({
            tag: element.tagName.toLowerCase(), type: element.getAttribute('type'), name: element.getAttribute('name'),
            role: element.getAttribute('role'), editable: element.getAttribute('contenteditable'),
        }))).catch(() => []);
        throw new Error(`SOUNDFRESH_UI_CHANGED:reject_reason_input:controls=${JSON.stringify(controls)}:url=${page.url()}`);
    }
    await reasonInput.fill(reason);

    const submit = modal.getByRole("button", { name: /^(?:Reject|Reject Release|Submit)$/i }).filter({ visible: true }).last();
    if (!await submit.count()) throw new Error(`SOUNDFRESH_UI_CHANGED:reject_submit:url=${page.url()}`);
    await submit.click({ timeout: 20_000 });
    await modal.waitFor({ state: "hidden", timeout: 20_000 }).catch(() => undefined);
    if (await modal.isVisible().catch(() => false)) {
        const error = await modal.locator('[role="alert"], .alert-danger, .text-danger, [class*="error" i]').first().innerText().catch(() => "");
        throw new Error(`SOUNDFRESH_REJECT_FAILED:${error || "rejection dialog remained open"}`);
    }
}
