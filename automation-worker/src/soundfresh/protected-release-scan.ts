import type { Page } from 'playwright';

export type ProtectedStatus = 'pending' | 'under_review' | 'revision' | 'rejected' | 'uploading';
export type ProtectedScans = Record<ProtectedStatus, { complete: true; items: Array<{ title: string; artist: string }> }>;

const statuses: Array<[ProtectedStatus, RegExp]> = [
    ['pending', /\bpending\b/i], ['under_review', /under\s*review/i],
    ['revision', /\brevision\b/i], ['rejected', /\brejected\b/i], ['uploading', /\buploading\b/i],
];

/** Full status-tab scan. Counts and every page must agree; otherwise throw. */
export async function scanProtectedReleases(page: Page): Promise<ProtectedScans> {
    const result = {} as ProtectedScans;
    for (const [status, name] of statuses) {
        const tableId = status === 'under_review' ? 'review' : status;
        const tab = page.getByRole('tab', { name }).filter({ visible: true }).first();
        if (!await tab.count()) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:tab_missing`);
        const tabText = await tab.innerText();
        const count = tabText.match(/\(([\d,]+)\)/)?.[1];
        if (count === undefined) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:count_missing`);
        const expected = Number(count.replace(/,/g, ''));
        await tab.click();
        const table = page.locator(`table#${tableId}`).filter({ visible: true }).first();
        await table.waitFor({ state: 'visible', timeout: 20_000 });
        const wrapper = table.locator('xpath=ancestor::div[contains(@class,"dataTables_wrapper") or contains(@class,"dt-container")]').first();
        if (!await wrapper.count()) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:wrapper_missing`);
        const search = wrapper.locator('input[type="search"]:visible').first();
        if (await search.count()) await search.fill('');
        const items: Array<{ title: string; artist: string }> = [];
        const seen = new Set<string>();
        let pages = 0;
        while (true) {
            if (++pages > 10000) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:page_limit`);
            await page.waitForTimeout(300);
            const busy = wrapper.locator('.dataTables_processing:visible, .dt-processing:visible');
            if (await busy.count()) await busy.first().waitFor({ state: 'hidden', timeout: 20_000 });
            const rows = await table.locator('tbody tr').all();
            let first = '';
            for (const row of rows) {
                const cells = await row.locator('td').allInnerTexts();
                if (cells.length === 1 && /no data|no matching|tidak ada/i.test(cells[0] ?? '')) continue;
                const lines = (cells[0] ?? '').split(/\r?\n/).map(x => x.trim()).filter(Boolean);
                const title = lines[0] ?? '';
                const artist = lines[1] ?? '';
                const href = await row.locator('a[href*="/releases/"]').last().getAttribute('href').catch(() => null);
                if (!title || !artist || !href) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:row_invalid`);
                const key = new URL(href, page.url()).pathname;
                if (seen.has(key)) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:duplicate_page`);
                if (!first) first = key;
                seen.add(key);
                items.push({ title, artist });
            }
            const next = wrapper.locator('[id$="_next"]').first();
            if (!await next.count()) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:pagination_missing`);
            const disabled = /disabled/i.test(await next.getAttribute('class') ?? '') || await next.getAttribute('aria-disabled') === 'true';
            if (disabled) break;
            if (!first) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:empty_page`);
            await next.click();
            await page.waitForFunction(({ tableId, previous }) => {
                const link = document.querySelector(`table#${tableId} tbody tr a[href*="/releases/"]`) as HTMLAnchorElement | null;
                return !!link && new URL(link.href).pathname !== previous;
            }, { tableId, previous: first }, { timeout: 20_000 });
        }
        if (items.length !== expected) throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:count_mismatch`);
        result[status] = { complete: true, items };
    }
    // A concurrent status change can invalidate an otherwise complete scan.
    for (const [status, name] of statuses) {
        const current = (await page.getByRole('tab', { name }).filter({ visible: true }).first().innerText())
            .match(/\(([\d,]+)\)/)?.[1];
        if (current === undefined || Number(current.replace(/,/g, '')) !== result[status].items.length) {
            throw new Error(`SOUNDFRESH_SCAN_INCOMPLETE:${status}:changed_during_scan`);
        }
    }
    return result;
}
