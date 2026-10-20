const clean = (value) => value.replace(/\s+/g, " ").trim();
/** Collect every SoundOn Under Review row and its Last Updated Date. */
export async function listSoundOnReviewReleases(page) {
    const tab = page.getByRole("tab", { name: /under\s*review/i }).or(page.getByRole("button", { name: /under\s*review/i })).first();
    if (await tab.count()) {
        await tab.click();
        await page.waitForTimeout(500);
    }
    const table = page.locator("table:visible").filter({ has: page.locator("tbody tr") }).first();
    if (!(await table.count()))
        throw new Error("SOUNDON_REVIEW_TABLE_NOT_FOUND");
    await table.locator("tbody tr").first().waitFor({ timeout: 20_000 });
    const tableId = await table.getAttribute("id");
    const wrapper = table.locator('xpath=ancestor::div[contains(@class,"dataTables_wrapper") or contains(@class,"dt-container")]').first();
    const length = tableId
        ? page.locator('select[name="' + tableId + '_length"]:visible')
        : page.locator('select[name$="_length"]:visible');
    if (await length.count()) {
        const values = await length.first().locator("option").evaluateAll((options) => options.map((option) => Number(option.value))
            .filter((value) => Number.isFinite(value) && value > 0));
        const largest = Math.max(0, ...values);
        if (largest)
            await length.first().selectOption(String(largest));
    }
    const releases = [];
    const seen = new Set();
    for (let pageIndex = 0; pageIndex < 10_000; pageIndex += 1) {
        const headers = (await table.locator("thead th").allInnerTexts()).map((value) => clean(value).toLowerCase());
        let titleIndex = headers.findIndex((value) => /title|album\s*name|release\s*name/.test(value));
        if (titleIndex < 0)
            titleIndex = headers.findIndex((value) => /^(album|release)$/.test(value));
        const artistIndex = headers.findIndex((value) => /artist/.test(value));
        const dateIndex = headers.findIndex((value) => /release\s*date|release\s*day/.test(value));
        const updatedIndex = headers.findIndex((value) => /last\s*updated\s*date|last\s*updated|updated\s*date/.test(value));
        const rows = await table.locator("tbody tr").all();
        for (const row of rows) {
            const cells = await row.locator(":scope > td").allInnerTexts();
            if (!cells.length)
                continue;
            const titleCell = row.locator(":scope > td").nth(titleIndex >= 0 ? titleIndex : 0);
            const title = clean((await titleCell.locator("a").first().textContent().catch(() => null)) || cells[titleIndex >= 0 ? titleIndex : 0] || "");
            const artist = clean(cells[artistIndex >= 0 ? artistIndex : 1] || "");
            if (!title || /no data|no records/i.test(title))
                continue;
            const href = await titleCell.locator("a[href]").first().getAttribute("href").catch(() => null);
            const url = href ? new URL(href, page.url()).toString() : "";
            const key = url || (title.toLowerCase() + "|" + artist.toLowerCase());
            if (seen.has(key))
                continue;
            seen.add(key);
            releases.push({
                title,
                artist,
                release_date: dateIndex >= 0 ? clean(cells[dateIndex] || "") : "",
                last_updated: updatedIndex >= 0 ? clean(cells[updatedIndex] || "") : "",
                release_url: url,
            });
        }
        const next = wrapper.locator('[id$="_next"]:visible, a.paginate_button.next:visible, button.dt-paging-button.next:visible, [aria-label*="Next" i]:visible').last();
        if (!(await next.count()))
            break;
        const classes = (await next.getAttribute("class") || "") + " " + (await next.locator("xpath=..").getAttribute("class").catch(() => ""));
        if ((await next.getAttribute("aria-disabled")) === "true" || /disabled/.test(classes))
            break;
        const before = clean(await table.locator("tbody tr").first().innerText().catch(() => ""));
        const advanced = await table.evaluate((element) => {
            const jquery = window.jQuery;
            if (!jquery?.fn?.DataTable?.isDataTable(element))
                return false;
            const dataTable = jquery(element).DataTable();
            const info = dataTable.page.info();
            if (info.page >= info.pages - 1)
                return false;
            dataTable.page(info.page + 1).draw("page");
            return true;
        }).catch(() => false);
        if (!advanced)
            await next.click({ timeout: 5_000 });
        let changed = false;
        for (let attempt = 0; attempt < 80; attempt += 1) {
            const current = clean(await table.locator("tbody tr").first().innerText().catch(() => ""));
            if (current && current !== before) {
                changed = true;
                break;
            }
            await page.waitForTimeout(100);
        }
        if (!changed)
            throw new Error("SOUNDON_REVIEW_PAGINATION_STALLED");
    }
    return releases;
}
