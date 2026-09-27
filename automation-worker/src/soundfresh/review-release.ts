import type { Page } from "playwright";

export async function moveSoundfreshReleaseToUnderReview(page: Page, aggregator: string, draftId: string): Promise<void> {
    const action = page.getByRole("button", { name: /^Review Release$/i }).filter({ visible: true }).first()
        .or(page.getByRole("link", { name: /^Review Release$/i }).filter({ visible: true }).first());
    if (!await action.count()) {
        const body = await page.locator("body").innerText().catch(() => "");
        if (/\bunder\s*review\b/i.test(body)) return;
        throw new Error(`SOUNDFRESH_UI_CHANGED:review_release_button:url=${page.url()}`);
    }

    await action.click({ timeout: 20_000 });
    await page.waitForTimeout(400);
    const dialog = page.getByRole("dialog").filter({ visible: true }).first()
        .or(page.locator('.modal.show:visible, [class*="modal" i]:visible').first());
    const scope = await dialog.count() ? dialog : page;

    const aggregatorSelect = scope.getByLabel(/aggregator/i).first()
        .or(scope.locator('select[name*="aggregator" i], select[id*="aggregator" i]').first());
    if (await aggregatorSelect.count()) {
        await aggregatorSelect.selectOption({ label: aggregator }).catch(async () => aggregatorSelect.selectOption(aggregator));
    } else {
        const aggregatorInput = scope.getByLabel(/aggregator/i).first()
            .or(scope.locator('input[name*="aggregator" i], input[id*="aggregator" i]').first());
        if (await aggregatorInput.count()) await aggregatorInput.fill(aggregator);
    }

    const referenceInput = scope.getByLabel(/draft|reference|soundon/i).first()
        .or(scope.locator('input[name*="draft" i], input[name*="reference" i], input[id*="draft" i], input[id*="reference" i]').first());
    if (await referenceInput.count()) await referenceInput.fill(draftId);

    const confirm = scope.getByRole("button", { name: /^(Review Release|Review|Save|Confirm|Submit|Yes|OK)$/i }).filter({ visible: true }).last();
    if (!await confirm.count()) throw new Error("SOUNDFRESH_UI_CHANGED:review_release_confirm");
    await confirm.click({ timeout: 20_000 });
    if (await dialog.count()) await dialog.waitFor({ state: "hidden", timeout: 20_000 }).catch(() => undefined);
    await page.waitForTimeout(800);

    if (await action.isVisible().catch(() => false)) {
        const error = await page.locator('[role="alert"], .alert-danger, .text-danger, [class*="error" i]').first().innerText().catch(() => "");
        throw new Error(`SOUNDFRESH_REVIEW_RELEASE_REJECTED:${error || "Review Release action remained available"}`);
    }
}
