import type { Page } from 'playwright';

export type InventoryDraft = { draft_id: string; title: string; artist: string; track_count: number };

/** Read every Drafts page and reject incomplete or changing inventories. */
export async function readDraftInventory(page: Page): Promise<InventoryDraft[]> {
    if (/\/login|sign-in/i.test(page.url())) throw new Error('AUTH_SESSION_EXPIRED');
    const tab = page.getByRole('tab', { name: /^drafts\b/i }).filter({ visible: true }).first();
    await tab.waitFor({ state: 'visible', timeout: 20000 });
    if (await tab.getAttribute('aria-selected') !== 'true') await tab.click();
    const tabCountText = (await tab.innerText()).match(/drafts?\s*([\d,]+)/i)?.[1];
    if (tabCountText === undefined) throw new Error('DRAFT_INVENTORY_UNCONFIRMED:tab_count');
    const tabCount = Number(tabCountText.replace(/,/g, ''));
    const search = page.locator('#title:visible').first();
    if (await search.count()) { await search.fill(''); await search.press('Enter'); }
    const items: InventoryDraft[] = [];
    const seen = new Set<string>();
    let expected: number | null = null;
    for (let pages = 0; pages < 10000; pages++) {
        const summary = page.getByText(/^Showing\s+\d+\s+to\s+\d+\s+of\s+\d+/i).filter({ visible: true }).first();
        await summary.waitFor({ state: 'visible', timeout: 20000 });
        const text = await summary.innerText();
        const numbers = text.match(/Showing\s+(\d+)\s+to\s+(\d+)\s+of\s+(\d+)/i);
        if (!numbers) throw new Error('DRAFT_INVENTORY_UNCONFIRMED:summary');
        const start = Number(numbers[1]), end = Number(numbers[2]), total = Number(numbers[3]);
        if (expected === null) expected = total;
        if (total !== tabCount) throw new Error('DRAFT_INVENTORY_UNCONFIRMED:filtered_or_stale');
        if (total !== expected || start !== items.length + 1 || end < start) throw new Error('DRAFT_INVENTORY_UNCONFIRMED:pagination');
        // Semi UI applies semi-table-row to its header as well as data rows.
        const rows = await page.locator('tr.semi-table-row[data-row-key]:visible').all();
        if (rows.length !== end - start + 1) throw new Error('DRAFT_INVENTORY_UNCONFIRMED:row_count');
        for (const row of rows) {
            const id = (await row.getAttribute('data-row-key')) ?? '';
            const title = (await row.locator('[aria-colindex="2"] .so-album-preview-item-title-text').innerText()).trim();
            const artist = (await row.locator('[aria-colindex="3"] .semi-tag-content').innerText()).trim();
            const tracks = (await row.locator('[aria-colindex="4"]').innerText()).trim();
            if (!/^\d{10,}$/.test(id) || !title || !artist || !/^\d+$/.test(tracks) || seen.has(id)) throw new Error('DRAFT_INVENTORY_UNCONFIRMED:row_invalid');
            seen.add(id); items.push({ draft_id: id, title, artist, track_count: Number(tracks) });
        }
        if (items.length === total) return items;
        const next = page.getByRole('button', { name: /^next$/i }).filter({ visible: true }).first();
        if (!await next.count() || await next.isDisabled()) throw new Error('DRAFT_INVENTORY_UNCONFIRMED:next_missing');
        await next.click();
        await page.waitForFunction(({ old }) => Array.from(document.querySelectorAll('body *')).some(el => {
            const text = (el as HTMLElement).innerText ?? '';
            const m = text.match(/^Showing\s+(\d+)\s+to\s+\d+\s+of\s+\d+$/i);
            return !!m && Number(m[1]) > old;
        }), { old: start }, { timeout: 20000 });
    }
    throw new Error('DRAFT_INVENTORY_UNCONFIRMED:page_limit');
}
