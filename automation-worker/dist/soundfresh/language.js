/** Use the site's own language selector; browser locale alone does not change
 * a language saved in the Soundfresh account/session. Never touch release Title Language. */
export async function ensureSoundfreshEnglish(page) {
    const indicator = page.getByText(/^(EN|ID)$/, { exact: true }).filter({ visible: true }).first();
    await indicator.waitFor({ state: 'visible', timeout: 10000 }).catch(() => undefined);
    if (await indicator.count()) {
        if ((await indicator.innerText()).trim() === 'EN')
            return;
        await indicator.click();
    }
    else {
        const languageMenu = page.getByRole('button', { name: /^(language|bahasa|select language)$/i })
            .or(page.getByRole('link', { name: /^(language|bahasa|select language)$/i })).filter({ visible: true }).first();
        if (!await languageMenu.count())
            throw new Error('SOUNDFRESH_UI_CHANGED:language_selector');
        await languageMenu.click();
    }
    const english = page.getByText('English', { exact: true }).filter({ visible: true }).first();
    await english.waitFor({ state: 'visible', timeout: 5000 });
    await english.click();
    await page.getByText('EN', { exact: true }).filter({ visible: true }).first()
        .waitFor({ state: 'visible', timeout: 15000 });
}
