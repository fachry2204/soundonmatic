import type { Page } from 'playwright';

/** Use the site's own language selector; browser locale alone does not change
 * a language saved in the Soundfresh account/session. Never touch release Title Language. */
export async function ensureSoundfreshEnglish(page: Page): Promise<void> {
    const languageMenu = () => page.getByRole('button', { name: /^(language|bahasa|select language)$/i })
        .or(page.getByRole('link', { name: /^(language|bahasa|select language)$/i }))
        .or(page.locator('button,a,[role="button"]').filter({ has: page.locator(
            '.ri-translate,.ri-translate-2,.ti-language,.fa-language,[data-lucide="languages"],[data-icon="language"]',
        ) }))
        .filter({ visible: true }).first();
    const translatedRelease = async () => {
        // The header language abbreviation may stay stale or disappear after
        // switching. Verify the actual release UI, not only that abbreviation.
        const detailEnglish = await page.getByRole('heading', { name: /^View Release$/i }).filter({ visible: true }).count() > 0
            && await page.getByRole('button', { name: /^(Edit Release|Review Release|Verify Release)$/i })
                .or(page.getByRole('link', { name: /^(Edit Release|Review Release|Verify Release)$/i }))
                .filter({ visible: true }).count() > 0;
        if (detailEnglish) return true;
        // Collection/status scans open the list, not View Release. Multiple
        // translated column headings are evidence of English even if the
        // responsive header hides the EN abbreviation. Never use song titles
        // or Title Language metadata as a UI-language signal.
        const headers = await page.getByRole('columnheader')
            .filter({ hasText: /^\s*(?:Release\s*Status|Release\s*Date|Uploaded\s*By|Uploaded\s*At|Number\s*of\s*Tracks)\s*$/i })
            .filter({ visible: true }).count();
        return headers >= 2;
    };
    if (await translatedRelease()) return;
    // Icon text (e.g. Material Symbols "translate EN") can be included in
    // the accessible name, so exact getByText('EN') alone is insufficient.
    const indicator = page.getByText(/^(EN|ID)$/, { exact: true })
        .or(page.getByRole('button', { name: /^(?:translate|g_translate|language)?\s*(?:EN|ID)$/i }))
        .or(page.getByRole('link', { name: /^(?:translate|g_translate|language)?\s*(?:EN|ID)$/i }))
        .filter({ visible: true }).first();
    const readyDeadline = Date.now() + 10000;
    while (!await indicator.count() && Date.now() < readyDeadline) {
        if (await translatedRelease()) return;
        if (await languageMenu().count()) break;
        await page.waitForTimeout(200);
    }
    if (await indicator.count()) {
        if (/^(?:translate|g_translate|language)?\s*EN$/i.test((await indicator.innerText()).trim())) return;
        await indicator.click();
    } else {
        const menu = languageMenu();
        if (!await menu.count()) throw new Error('SOUNDFRESH_UI_CHANGED:language_selector');
        await menu.click();
    }
    const english = page.getByText('English', { exact: true }).filter({ visible: true }).first();
    await english.waitFor({ state: 'visible', timeout: 5000 });
    await english.click();
    const deadline = Date.now() + 15000;
    while (Date.now() < deadline) {
        if (await page.getByText('EN', { exact: true }).filter({ visible: true }).count()
            || await page.getByRole('button', { name: /^(?:translate|g_translate|language)\s*EN$/i })
                .or(page.getByRole('link', { name: /^(?:translate|g_translate|language)\s*EN$/i })).filter({ visible: true }).count()
            || await translatedRelease()) return;
        await page.waitForTimeout(250);
    }
    throw new Error('SOUNDFRESH_LANGUAGE_NOT_CONFIRMED:English dipilih tetapi indikator EN maupun tampilan View Release berbahasa Inggris belum terkonfirmasi');
}
