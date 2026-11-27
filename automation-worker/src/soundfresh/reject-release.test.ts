import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { chromium, type Browser, type Page } from 'playwright';
import { rejectSoundfreshRelease } from './reject-release.js';

let browser: Browser;
beforeAll(async () => { browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE }); });
afterAll(async () => { await browser?.close(); });
async function fixture(html: string, run: (page: Page) => Promise<void>) {
    const page = await browser.newPage();
    try { await page.setContent(html); await run(page); }
    finally { await page.close(); }
}
const submit = `<button onclick="window.sent=this.closest('[role=dialog],.modal,.swal2-popup').querySelector('textarea,input,[contenteditable]')?.value;this.closest('[role=dialog],.modal,.swal2-popup').remove()">Reject Release</button>`;

describe('rejectSoundfreshRelease', () => {
    it('rejects a Pending release after its AJAX reason field loads without invoking Review Release', async () => {
        await fixture(`<span class="badge">Pending</span><button onclick="window.reviewed=true">Review Release</button>
            <textarea id="unrelated">keep this value</textarea><div role="dialog"><h2>Export</h2><input value="do not change"></div>
            <button onclick="document.querySelector('#reject').hidden=false;setTimeout(()=>document.querySelector('#fields').innerHTML='<textarea name=reason></textarea>',300)">Reject Release</button>
            <div id="reject" role="dialog" hidden><h2>Reject Release</h2><div id="fields">Loading...</div>${submit}</div>`, async page => {
            const reason = 'UPC sudah digunakan untuk rilisan lain.\nMohon periksa dan perbaiki data sebelum mengirim ulang.';
            await rejectSoundfreshRelease(page, reason);
            expect(await page.evaluate(() => (window as any).sent)).toBe(reason);
            expect(await page.locator('#unrelated').inputValue()).toBe('keep this value');
            expect(await page.locator('[role=dialog] input').inputValue()).toBe('do not change');
            expect(await page.evaluate(() => (window as any).reviewed)).toBeUndefined();
        });
    });

    it('uses the split dropdown without invoking Verify Release', async () => {
        await fixture(`<button onclick="window.verified=true">Verify Release</button>
            <button aria-expanded="true">Toggle Dropdown</button><a onclick="document.querySelector('#reject').hidden=false">Reject Release</a>
            <div id="reject" class="modal show" hidden><h2>Reject Release</h2><textarea></textarea>${submit}</div>`, async page => {
            await rejectSoundfreshRelease(page, 'Mohon perbaiki UPC.');
            expect(await page.evaluate(() => (window as any).sent)).toBe('Mohon perbaiki UPC.');
            expect(await page.evaluate(() => (window as any).verified)).toBeUndefined();
        });
    });

    it('fills a rich text reason editor without a textbox role', async () => {
        await fixture(`<button onclick="document.querySelector('#reject').hidden=false">Reject Release</button>
            <div id="reject" class="swal2-popup" hidden><h2>Reject Release</h2><div contenteditable="true" class="ql-editor"></div>
            <button onclick="window.sent=document.querySelector('.ql-editor').innerText;this.parentElement.remove()">Submit</button></div>`, async page => {
            const reason = 'Alasan lengkap untuk client.\n\nUPC sudah digunakan pada rilisan lain.\nMohon periksa data dan kirim kembali setelah diperbaiki.';
            await rejectSoundfreshRelease(page, reason);
            // Browsers serialize rich-text paragraph spacing differently;
            // every nonempty line must still be copied fully and in order.
            expect((await page.evaluate(() => (window as any).sent) as string).split(/\n+/)).toEqual(reason.split(/\n+/));
        });
    });

    it('fills the reason inside an iframe editor', async () => {
        await fixture(`<button onclick="document.querySelector('#reject').hidden=false">Reject Release</button>
            <div id="reject" role="dialog" hidden><h2>Reject Release</h2><iframe title="Rich Text Area" srcdoc="<body contenteditable='true'></body>"></iframe>
            <button onclick="window.sent=document.querySelector('iframe').contentDocument.body.innerText;this.parentElement.remove()">Reject</button></div>`, async page => {
            await rejectSoundfreshRelease(page, 'Mohon periksa identitas rilisan ini.');
            expect(await page.evaluate(() => (window as any).sent)).toBe('Mohon periksa identitas rilisan ini.');
        });
    });

    it('waits for a readonly reason field to become editable', async () => {
        await fixture(`<button onclick="document.querySelector('#reject').hidden=false;setTimeout(()=>document.querySelector('#reason').readOnly=false,250)">Reject Release</button>
            <div id="reject" role="dialog" hidden><h2>Reject Release</h2><input id="reason" name="reason" readonly>${submit}</div>`, async page => {
            await rejectSoundfreshRelease(page, 'Perbaiki data rilisan.');
            expect(await page.evaluate(() => (window as any).sent)).toBe('Perbaiki data rilisan.');
        });
    });

    it('reports missing editor structure without leaking values or submitting a blank reason', async () => {
        await fixture(`<textarea>PRIVATE_DO_NOT_COPY</textarea><button onclick="document.querySelector('#reject').hidden=false">Reject Release</button>
            <div id="reject" role="dialog" hidden><h2>Reject Release</h2><input type="hidden" name="csrf" value="PRIVATE_TOKEN">
            <button onclick="window.sent=true">Reject</button></div>`, async page => {
            let error = '';
            try { await rejectSoundfreshRelease(page, 'Perbaiki data.'); } catch (e) { error = String(e); }
            expect(error).toContain('reject_reason_input:controls=');
            expect(error).not.toContain('PRIVATE');
            expect(await page.evaluate(() => (window as any).sent)).toBeUndefined();
        });
    }, 20000);
});
