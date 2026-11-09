import { primaryReleaseAction, clickReleaseAction } from './release-action.js';
const uploadingStatus = (page) => page.locator('.badge,[data-release-status],[role="status"]')
    .filter({ hasText: /^\s*(Uploading|Upload DSP)\s*$/i }).filter({ visible: true }).first();
export async function moveSoundfreshReleaseToUploading(page) {
    const action = primaryReleaseAction(page, 'Upload Release');
    if (!await action.count()) {
        if (await uploadingStatus(page).count())
            return;
        const actions = (await page.getByRole("button").allInnerTexts().catch(() => [])).slice(0, 30);
        throw new Error(`SOUNDFRESH_UI_CHANGED:upload_release_button:buttons=${JSON.stringify(actions)}:url=${page.url()}`);
    }
    await clickReleaseAction(action);
    await page.waitForTimeout(400);
    const dialog = page.getByRole("dialog").or(page.locator('.modal.show,.swal2-popup')).filter({ visible: true }).first();
    await Promise.race([
        dialog.waitFor({ state: 'visible', timeout: 10000 }),
        uploadingStatus(page).waitFor({ state: 'visible', timeout: 10000 }),
    ]).catch(() => undefined);
    if (await uploadingStatus(page).count())
        return;
    if (await dialog.count()) {
        const confirm = dialog.getByRole("button", { name: /^(Upload Release|Upload|Confirm|Yes|OK)$/i }).filter({ visible: true }).last();
        if (await confirm.count())
            await clickReleaseAction(confirm);
        await dialog.waitFor({ state: "hidden", timeout: 20_000 }).catch(() => undefined);
    }
    await page.waitForTimeout(700);
    await uploadingStatus(page).waitFor({ state: 'visible', timeout: 15000 }).catch(() => undefined);
    if (!await uploadingStatus(page).count()) {
        const error = await page.locator('[role="alert"], .alert-danger, .text-danger, [class*="error" i]').first().innerText().catch(() => "");
        throw new Error(`SOUNDFRESH_UPLOAD_RELEASE_REJECTED:${error || "Status Uploading/Upload DSP belum terkonfirmasi"}`);
    }
}
