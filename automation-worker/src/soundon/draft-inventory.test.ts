import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { readDraftInventory } from './draft-inventory.js';

describe('draft inventory', () => {
    it('reads all pages and rejects an inconsistent row count', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.goto('data:text/html,<!doctype html>');
            await page.setContent(`<button role="tab" aria-selected="true">Drafts 2</button><input id="title">
                <table><tbody id="rows"><tr class="semi-table-row" data-row-key="7709712260272785414"><td aria-colindex="2"><span class="so-album-preview-item-title-text">Ember City</span></td><td aria-colindex="3"><span class="semi-tag-content">Phonk Forge</span></td><td aria-colindex="4">10</td></tr></tbody></table>
                <div id="info">Showing 1 to 1 of 2</div><button aria-label="Next" onclick="document.querySelector('#rows').innerHTML='<tr class=semi-table-row data-row-key=7709712260272785415><td aria-colindex=2><span class=so-album-preview-item-title-text>Other</span></td><td aria-colindex=3><span class=semi-tag-content>Artist</span></td><td aria-colindex=4>1</td></tr>';document.querySelector('#info').textContent='Showing 2 to 2 of 2';this.disabled=true">Next</button>`);
            const items = await readDraftInventory(page);
            expect(items.map(item => item.title)).toEqual(['Ember City', 'Other']);
            await page.locator('#info').evaluate(node => { node.textContent = 'Showing 1 to 2 of 3'; });
            await expect(readDraftInventory(page)).rejects.toThrow('DRAFT_INVENTORY_UNCONFIRMED:filtered_or_stale');
        } finally { await browser.close(); }
    }, 15000);
});
