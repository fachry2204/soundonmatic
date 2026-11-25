import { describe, expect, it } from 'vitest';
import { chromium } from 'playwright';
import { continueWithCurrentReleaseDate, selectCalendarDate, setScopedTime, setTikTokPreRelease } from './draft-driver.js';

describe('SoundOn calendar selection', () => {
    it('dismisses a release-date warning before opening the calendar', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="semi-input-wrapper" onclick="document.querySelector('.semi-datepicker').hidden=false"><input readonly></div>
                <div class="semi-datepicker" hidden><div class="semi-datepicker-navigation-month">Dec 2026</div>
                <div class="semi-datepicker-day" title="2026-12-08" onclick="document.querySelector('input').value='2026-12-08';this.parentElement.hidden=true">8</div></div>
                <div role="dialog" style="position:fixed;inset:0;background:white"><h2>Release Date Too Soon</h2>
                <button onclick="this.parentElement.remove()">No, continue with this date</button></div>`);
            await continueWithCurrentReleaseDate(page);
            expect(await selectCalendarDate(page.locator('input'), '2026-12-08')).toBe(true);
            expect(await page.locator('input').inputValue()).toBe('2026-12-08');
        } finally { await browser.close(); }
    }, 15000);
    it('persists TikTok date and time with a readonly wrapper without changing another card', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage({ viewport: { width: 900, height: 480 } });
            await page.setContent(`<div><span>Pre-release date</span><div><input id="other" value="2026-11-24"></div></div>
                <section style="height:240px;overflow:auto"><div style="height:1500px"></div><article>
                <h3>TikTok pre-release</h3><label><input type="radio" checked>Turn on TikTok pre-release</label>
                <label><input type="radio">Skip for now</label>
                <div><span>Pre-release date</span><div class="semi-input-wrapper" onclick="document.querySelector('.semi-datepicker').hidden=false">
                <input id="tiktok" readonly style="pointer-events:none"></div></div>
                <div><span>Pre-release time</span><button role="combobox" aria-controls="times" onclick="document.querySelector('#times').hidden=false">Choose time</button></div>
                </article></section><div class="semi-datepicker" hidden><div class="semi-datepicker-navigation-month">Dec 2026</div>
                <div class="semi-datepicker-day" title="2026-12-02" onclick="document.querySelector('#tiktok').value='2026-12-02';this.parentElement.hidden=true">2</div></div>
                <div id="times" hidden><div role="option" onclick="document.querySelector('[role=combobox]').textContent='12:00 AM';this.parentElement.hidden=true">12:00 AM</div></div>`);
            await setTikTokPreRelease(page, '2026-12-02');
            expect(await page.locator('#tiktok').inputValue()).toBe('2026-12-02');
            expect(await page.locator('#other').inputValue()).toBe('2026-11-24');
            expect(await page.getByRole('combobox').innerText()).toBe('12:00 AM');
        } finally { await browser.close(); }
    }, 15000);

    it('does not bypass readonly or accept a disabled TikTok date when calendar selection fails', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<article><h3>TikTok pre-release</h3>
                <label><input type="radio" checked>Turn on TikTok pre-release</label><label><input type="radio">Skip for now</label>
                <div><span>Pre-release date</span><div class="semi-input-wrapper" onclick="document.querySelector('.semi-datepicker').hidden=false"><input readonly></div></div>
                <div><span>Pre-release time</span><button role="combobox">Choose time</button></div></article>
                <div class="semi-datepicker" hidden><div class="semi-datepicker-navigation-month">Dec 2026</div>
                <div class="semi-datepicker-day semi-datepicker-day-disabled" title="2026-12-02">2</div></div>`);
            await expect(setTikTokPreRelease(page, '2026-12-02')).rejects.toThrow('SOUNDON_FIELD_NOT_PERSISTED:Pre-release date');
            expect(await page.locator('input[readonly]').inputValue()).toBe('');
            expect(await page.getByRole('combobox').innerText()).toBe('Choose time');
        } finally { await browser.close(); }
    }, 15000);

    it('switches TikTok to Skip for now when SoundOn warns that pre-release is too soon', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<article><h3>TikTok pre-release</h3>
                <label><input id="on" type="radio" name="choice" checked>Turn on TikTok pre-release</label>
                <label><input id="skip" type="radio" name="choice">Skip for now</label>
                <div><span>Pre-release date</span><div class="semi-input-wrapper" onclick="document.querySelector('.semi-datepicker').hidden=false"><input id="date" readonly></div></div>
                <div><span>Pre-release time</span><button role="combobox">Choose time</button></div></article>
                <div class="semi-datepicker" hidden><div class="semi-datepicker-navigation-month">Dec 2026</div>
                <div class="semi-datepicker-day" title="2026-12-02" onclick="document.querySelector('#date').value='2026-12-02';this.parentElement.hidden=true;document.querySelector('[role=dialog]').hidden=false">2</div></div>
                <div role="dialog" hidden><h2>Pre-Release Date Too Soon</h2><p>We recommend that the pre-release date is at least 7 days before the official release date.</p>
                <button>No, continue with this date</button><button onclick="this.parentElement.hidden=true">Yes</button></div>`);
            await setTikTokPreRelease(page, '2026-12-02');
            expect(await page.locator('#skip').isChecked()).toBe(true);
            expect(await page.getByRole('combobox').innerText()).toBe('Choose time');
            expect(await page.getByRole('dialog').isVisible()).toBe(false);
        } finally { await browser.close(); }
    }, 15000);

    it('opens a readonly Semi date through its pointer-enabled wrapper in a scrolled panel', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage({ viewport: { width: 900, height: 480 } });
            await page.setContent(`<section style="height:220px;overflow:auto"><div style="height:1600px"></div>
                <div class="semi-datepicker-input" style="width:260px;padding:8px" onmousedown="window.pointer=event.isTrusted"
                    onclick="if(window.pointer)document.querySelector('.semi-datepicker').hidden=false">
                    <span class="semi-input-wrapper"><input readonly aria-invalid="true" style="pointer-events:none"></span>
                </div></section><div class="semi-datepicker" hidden>
                <div class="semi-datepicker-navigation-month">Dec 2026</div>
                <div class="semi-datepicker-day" title="2026-12-02" onclick="document.querySelector('input').value='2026-12-02';this.parentElement.hidden=true">2</div></div>`);
            expect(await selectCalendarDate(page.locator('input'), '2026-12-02')).toBe(true);
            expect(await page.locator('input').inputValue()).toBe('2026-12-02');
            expect(await page.locator('input').getAttribute('readonly')).not.toBeNull();
            expect(await page.evaluate(() => (window as any).pointer)).toBe(true);
        } finally { await browser.close(); }
    }, 20000);

    it('reopens the seventh track time dropdown without selecting another track option', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(Array.from({ length: 7 }, (_, index) => `<section id="track-${index}"><div><span>Pre-release time</span>
                <button role="combobox" aria-controls="times-${index}" onclick="this.dataset.opens=Number(this.dataset.opens||0)+1;if(Number(this.dataset.opens)>${index === 6 ? 1 : 0})document.querySelector('#times-${index}').hidden=false">Choose time</button></div></section>
                <div id="times-${index}" hidden><div role="option" onclick="document.querySelector('#track-${index} button').textContent='12:00 AM';this.parentElement.hidden=true">12:00 AM</div></div>`).join('')
                + '<div role="option" onclick="window.wrong=true">12:00 AM</div>');
            await setScopedTime(page.locator('#track-6'), 'Pre-release time');
            expect(await page.locator('#track-6 button').innerText()).toBe('12:00 AM');
            expect(await page.locator('#track-6 button').getAttribute('data-opens')).toBe('2');
            expect(await page.locator('#track-0 button').innerText()).toBe('Choose time');
            expect(await page.evaluate(() => (window as any).wrong)).toBeUndefined();
        } finally { await browser.close(); }
    }, 20000);
    it('scrolls and commits midnight from an off-screen time dropdown', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage({ viewport: { width: 900, height: 480 } });
            await page.setContent(`<div style="height:1500px"></div><section><div><span>Pre-release time</span>
                <button role="combobox" onclick="document.querySelector('#options').hidden=false">Choose time</button></div></section>
                <div id="options" hidden><div style="height:900px"></div><div role="option" onmousedown="window.pointer=event.isTrusted"
                onclick="if(window.pointer){document.querySelector('[role=combobox]').textContent='12:00 AM';this.parentElement.hidden=true}">12:00 AM</div></div>`);
            await setScopedTime(page.locator('section'), 'Pre-release time');
            expect(await page.getByRole('combobox').innerText()).toBe('12:00 AM');
        } finally { await browser.close(); }
    }, 15000);
    it('commits exact dates in three off-screen calendars on one profile', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        const context = await browser.newContext({ viewport: { width: 900, height: 480 } });
        try {
            await Promise.all([27, 28, 29].map(async day => {
                const page = await context.newPage();
                const iso = `2026-11-${day}`;
                await page.setContent(`<div style="height:1500px"></div><input readonly onclick="document.querySelector('.semi-datepicker').hidden=false">
                    <div class="semi-datepicker" hidden><div class="semi-datepicker-navigation-month">Nov 2026</div>
                    <div class="semi-datepicker-day" title="2026-10-${day}" onclick="window.wrong=true">${day}</div>
                    <div style="height:900px"></div>
                    <div tabindex="0" role="gridcell" class="semi-datepicker-day" title="${iso}" aria-disabled="false"
                    onmousedown="window.pointer=event.isTrusted" onclick="if(window.pointer){document.querySelector('input').value='${iso}';this.parentElement.hidden=true}">${day}</div></div>`);
                expect(await selectCalendarDate(page.locator('input'), iso)).toBe(true);
                expect(await page.locator('input').inputValue()).toBe(iso);
                expect(await page.evaluate(() => (window as any).wrong)).toBeUndefined();
            }));
        } finally { await browser.close(); }
    }, 20000);

    it('does not select disabled dates or a matching day from another month', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<input onclick="document.querySelector('.semi-datepicker').hidden=false"><div class="semi-datepicker" hidden>
                <div class="semi-datepicker-navigation-month">Nov 2026</div>
                <div class="semi-datepicker-day" title="2026-10-27" onclick="window.wrong=true">27</div>
                <div class="semi-datepicker-day" title="2026-11-27" aria-disabled="true" onclick="window.wrong=true">27</div></div>`);
            expect(await selectCalendarDate(page.locator('input'), '2026-11-27')).toBe(false);
            expect(await page.evaluate(() => (window as any).wrong)).toBeUndefined();
        } finally { await browser.close(); }
    }, 10000);

    it('rejects a pending date discarded by the controlled form on blur', async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<input onblur="this.value=''" onclick="document.querySelector('.semi-datepicker').hidden=false">
                <button>Next field</button><div class="semi-datepicker" hidden><div class="semi-datepicker-navigation-month">Nov 2026</div>
                <div class="semi-datepicker-day" title="2026-11-27" onclick="document.querySelector('input').value='2026-11-27';this.parentElement.hidden=true">27</div></div>`);
            expect(await selectCalendarDate(page.locator('input'), '2026-11-27')).toBe(false);
        } finally { await browser.close(); }
    }, 10000);
});
