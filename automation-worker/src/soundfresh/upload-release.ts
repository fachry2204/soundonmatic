import type { Page } from "playwright";

export async function moveSoundfreshReleaseToUploading(page: Page): Promise<void> {
    const action = page.getByRole("button", { name: /^Upload Release$/i }).filter({ visible: true }).first()
        .or(page.getByRole("link", { name: /^Upload Release$/i }).filter({ visible: true }).first());
    if (!await action.count()) {
        const body = await page.locator("body").innerText().catch(() => "");
        if (/\buploading\b/i.test(body)) return;
        const actions = (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 30);
        throw new Error(`SOUNDFRESH_UI_CHANGED:upload_release_button:buttons=${JSON.stringify(actions)}:url=${page.url()}`);
    }

    await action.click({ timeout: 20_000 });
    await page.waitForTimeout(400);
    const dialog = page.getByRole("dialog").filter({ visible: true }).first()
        .or(page.locator('.modal.show:visible, [class*="modal" i]:visible').first());
    if (await dialog.count()) {
        const confirm = dialog.getByRole("button", { name: /^(Upload Release|Upload|Confirm|Yes|OK)$/i }).filter({ visible: true }).last();
        if (await confirm.count()) await confirm.click({ timeout: 20_000 });
        await dialog.waitFor({ state: "hidden", timeout: 20_000 }).catch(() => undefined);
    }
    await page.waitForTimeout(700);
    if (await action.isVisible().catch(() => false)) {
        const error = await page.locator('[role="alert"], .alert-danger, .text-danger, [class*="error" i]').first().innerText().catch(() => "");
        throw new Error(`SOUNDFRESH_UPLOAD_RELEASE_REJECTED:${error || "Upload Release action remained available"}`);
    }
}
