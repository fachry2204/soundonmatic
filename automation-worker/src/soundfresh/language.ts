import type { Page } from 'playwright';

/** Use the site's own language selector; browser locale alone does not change
 * a language saved in the Soundfresh account/session. Never touch release Title Language. */
export async function ensureSoundfreshEnglish(page: Page): Promise<void> {
    const translatedRelease = async () => {
        // The header language abbreviation may stay stale or disappear after
        // switching. Verify the actual release UI, not only that abbreviation.
        return await page.getByRole('heading', { name: /^View Release$/i }).filter({ visible: true }).count() > 0
            && await page.getByRole('button', { name: /^(Edit Release|Review Release|Verify Release)$/i })
                .or(page.getByRole('link', { name: /^(Edit Release|Review Release|Verify Release)$/i }))
                .filter({ visible: true }).count() > 0;
    };
    if (await translatedRelease()) return;
    const indicator = page.getByText(/^(EN|ID)$/, { exact: true }).filter({ visible: true }).first();
    await indicator.waitFor({ state: 'visible', timeout: 10000 }).catch(() => undefined);
    if (await indicator.count()) {
        if ((await indicator.innerText()).trim() === 'EN') return;
        await indicator.click();
    } else {
        const languageMenu = page.getByRole('button', { name: /^(language|bahasa|select language)$/i })
            .or(page.getByRole('link', { name: /^(language|bahasa|select language)$/i })).filter({ visible: true }).first();
        if (!await languageMenu.count()) throw new Error('SOUNDFRESH_UI_CHANGED:language_selector');
        await languageMenu.click();
    }
    const english = page.getByText('English', { exact: true }).filter({ visible: true }).first();
    await english.waitFor({ state: 'visible', timeout: 5000 });
    await english.click();
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) {
        if (await page.getByText('EN', { exact: true }).filter({ visible: true }).count()
            || await translatedRelease()) return;
        await page.waitForTimeout(250);
    }
    throw new Error('SOUNDFRESH_LANGUAGE_NOT_CONFIRMED:English dipilih tetapi indikator EN maupun tampilan View Release berbahasa Inggris belum terkonfirmasi');
}
