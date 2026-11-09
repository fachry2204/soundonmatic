import type { Dialog, Locator, Page } from 'playwright';

/** Split buttons have a primary action and a separate menu arrow. Never use
 * screen coordinates or click the arrow to review/verify a release. */
export function primaryReleaseAction(page: Page, label: 'Review Release' | 'Verify Release' | 'Upload Release'): Locator {
    return page.getByRole('button', { name: label, exact: true })
        .or(page.getByRole('link', { name: label, exact: true }))
        .and(page.locator(':not(.dropdown-toggle-split):not([data-bs-toggle="dropdown"]):not([data-toggle="dropdown"]):not([aria-haspopup="menu"])'))
        .filter({ visible: true }).first();
}

export async function clickReleaseAction(action: Locator): Promise<void> {
    const page = action.page();
    const confirm = async (dialog: Dialog) => {
        if (dialog.type() === 'confirm' || dialog.type() === 'alert') await dialog.accept();
        else await dialog.dismiss();
    };
    page.on('dialog', confirm);
    try { await action.click({ timeout: 20000 }); }
    finally { page.off('dialog', confirm); }
}
