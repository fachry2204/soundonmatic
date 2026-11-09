/** Split buttons have a primary action and a separate menu arrow. Never use
 * screen coordinates or click the arrow to review/verify a release. */
export function primaryReleaseAction(page, label) {
    return page.getByRole('button', { name: label, exact: true })
        .or(page.getByRole('link', { name: label, exact: true }))
        .and(page.locator(':not(.dropdown-toggle-split):not([data-bs-toggle="dropdown"]):not([data-toggle="dropdown"]):not([aria-haspopup="menu"])'))
        .filter({ visible: true }).first();
}
export async function clickReleaseAction(action) {
    const page = action.page();
    const confirm = async (dialog) => {
        if (dialog.type() === 'confirm' || dialog.type() === 'alert')
            await dialog.accept();
        else
            await dialog.dismiss();
    };
    page.on('dialog', confirm);
    try {
        await action.click({ timeout: 20000 });
    }
    finally {
        page.off('dialog', confirm);
    }
}
