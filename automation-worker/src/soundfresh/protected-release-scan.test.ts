import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { scanProtectedReleases } from './protected-release-scan.js';

describe('protected Soundfresh scan', () => {
    it('reads all protected tabs and refuses an inconsistent count', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.goto('data:text/html,<!doctype html>');
            const statuses = [['pending', 'Pending'], ['review', 'Under Review'], ['revision', 'Revision'], ['rejected', 'Rejected'], ['uploading', 'Uploading']];
            const tabs = statuses.map(([id, label]) => `<button role="tab" onclick="document.querySelectorAll('.dataTables_wrapper').forEach(e=>e.hidden=true);document.getElementById('${id}_wrapper').hidden=false">${label} (1)</button>`).join('');
            const tables = statuses.map(([id]) => `<div class="dataTables_wrapper" id="${id}_wrapper" hidden><table id="${id}"><tbody><tr><td>Ember City<br>Phonk Forge</td><td><a href="https://example.com/releases/${id}">View</a></td></tr></tbody></table><span id="${id}_next" class="disabled">Next</span></div>`).join('');
            await page.setContent(tabs + tables);
            const result = await scanProtectedReleases(page);
            expect(result.uploading.items).toEqual([{ title: 'Ember City', artist: 'Phonk Forge' }]);
            await page.getByRole('tab', { name: /uploading/i }).evaluate(node => { node.textContent = 'Uploading (2)'; });
            await expect(scanProtectedReleases(page)).rejects.toThrow('SOUNDFRESH_SCAN_INCOMPLETE:uploading:count_mismatch');
        } finally { await browser.close(); }
    }, 15000);
});
