import { authSelectors } from '../selectors/auth.js';
/** Only dismiss SoundOn's optional setup prompt, never an OTP/CAPTCHA challenge. */
export async function dismissSoundOnTwoStepPrompt(page) {
    const dialogs = page.locator('[role="dialog"], [aria-modal="true"], dialog, .semi-modal, .so-form-modal-dialog').filter({ visible: true });
    for (let i = 0; i < await dialogs.count(); i++) {
        const dialog = dialogs.nth(i);
        const text = (await dialog.innerText().catch(() => '')).replace(/\s+/g, ' ');
        if (!/(?:two|2)[-\s]*(?:step|factor)|2fa/i.test(text)
            || /enter (?:the |your )?(?:(?:\d+[- ]?digit|verification|security) )?code|one.time password|captcha/i.test(text)
            || await dialog.locator('input[autocomplete="one-time-code"]').count())
            continue;
        const skip = dialog.getByRole('button', { name: /^(?:not\s*now|skip)$/i })
            .or(dialog.getByRole('link', { name: /^(?:not\s*now|skip)$/i })).filter({ visible: true }).first();
        if (await skip.count()) {
            try {
                await skip.click({ timeout: 3_000, noWaitAfter: true });
                return true;
            }
            catch (error) {
                if (page.isClosed())
                    return false;
                throw error;
            }
        }
    }
    return false;
}
export async function sessionStateOnPage(page, platform) {
    if (platform === 'soundon')
        await dismissSoundOnTwoStepPrompt(page);
    const path = new URL(page.url()).pathname;
    const password = platform === 'soundon' ? page.getByPlaceholder(authSelectors.soundon.password, { exact: true })
        : page.getByLabel(authSelectors.soundfresh.password, { exact: true });
    const challenge = await page.getByText(authSelectors.challenge).first().isVisible().catch(() => false);
    if (challenge)
        return 'manual_auth';
    if (await password.isVisible().catch(() => false))
        return 'expired';
    const authenticated = platform === 'soundfresh' ? authSelectors.soundfresh.authenticatedUrl : authSelectors.soundon.authenticatedUrl;
    if (authenticated.test(path))
        return 'valid';
    // A login URL without its form can be a loading/error/intermediate page.
    return 'unknown';
}
/** SPA login forms can render after navigation and the initial loading screen. */
export async function waitForSessionStateOnPage(page, platform, timeoutMs = 15_000) {
    const deadline = Date.now() + timeoutMs;
    let status = await sessionStateOnPage(page, platform);
    while (status === 'unknown' && Date.now() < deadline) {
        await page.waitForTimeout(Math.min(250, Math.max(1, deadline - Date.now())));
        status = await sessionStateOnPage(page, platform);
    }
    return status;
}
