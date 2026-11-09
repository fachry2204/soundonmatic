import type { Page } from "playwright";
import { ensureSoundfreshEnglish } from './language.js';
import { primaryReleaseAction, clickReleaseAction } from './release-action.js';

function reviewStatus(page: Page) {
    // Ignore sidebar/tab names: only a visible release status badge counts.
    return page.locator('.badge, [data-release-status], [role="status"]')
        .filter({ hasText: /^\s*Under\s*Review\s*$/i }).filter({ visible: true }).first();
}

export async function moveSoundfreshReleaseToUnderReview(page: Page, aggregator: string, draftId: string): Promise<void> {
    await ensureSoundfreshEnglish(page);
    const action = primaryReleaseAction(page, 'Review Release');
    if (!await action.count()) {
        if (await reviewStatus(page).count()) return;
        throw new Error(`SOUNDFRESH_UI_CHANGED:review_release_button:url=${page.url()}`);
    }

    await clickReleaseAction(action);
    await page.waitForTimeout(400);
    const dialog = page.getByRole("dialog")
        .or(page.locator('.modal.show, .swal2-popup')).filter({ visible: true }).first();
    // Current Soundfresh can apply Review Release directly. Do not click the
    // page action a second time or accidentally submit an unrelated form.
    await Promise.race([
        dialog.waitFor({ state: 'visible', timeout: 10000 }),
        reviewStatus(page).waitFor({ state: 'visible', timeout: 10000 }),
    ]).catch(() => undefined);
    if (await reviewStatus(page).count()) return;
    if (!await dialog.count()) throw new Error('SOUNDFRESH_REVIEW_RELEASE_NOT_CONFIRMED:status Under Review belum terlihat');
    const scope = dialog;

    const aggregatorSelect = scope.getByRole('combobox', { name: /aggregator/i })
        .or(scope.locator('select[name*="aggregator" i], select[id*="aggregator" i]')).first();
    if (await aggregatorSelect.count()) {
        await aggregatorSelect.selectOption({ label: aggregator }).catch(async () => aggregatorSelect.selectOption(aggregator));
    } else {
        const aggregatorInput = scope.getByRole('textbox', { name: /aggregator/i })
            .or(scope.locator('input[name*="aggregator" i], input[id*="aggregator" i]')).first();
        if (await aggregatorInput.count()) await aggregatorInput.fill(aggregator);
    }

    const referenceInput = scope.getByRole('textbox', { name: /draft|reference|soundon/i })
        .or(scope.locator('input[name*="draft" i], input[name*="reference" i], input[id*="draft" i], input[id*="reference" i]')).first();
    if (await referenceInput.count()) await referenceInput.fill(draftId);

    const confirm = scope.getByRole("button", { name: /^(Review Release|Review|Save|Confirm|Submit|Yes|OK)$/i }).filter({ visible: true }).last();
    if (!await confirm.count()) throw new Error("SOUNDFRESH_UI_CHANGED:review_release_confirm");
    await clickReleaseAction(confirm);
    if (await dialog.count()) await dialog.waitFor({ state: "hidden", timeout: 20_000 }).catch(() => undefined);
    await page.waitForTimeout(800);

    await reviewStatus(page).waitFor({ state: 'visible', timeout: 15000 }).catch(() => undefined);
    if (!await reviewStatus(page).count()) {
        const error = await page.locator('[role="alert"], .alert-danger, .text-danger, [class*="error" i]').first().innerText().catch(() => "");
        throw new Error(`SOUNDFRESH_REVIEW_RELEASE_REJECTED:${error || "Status Under Review belum terkonfirmasi setelah Review Release"}`);
    }
}
