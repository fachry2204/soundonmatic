import { describe, expect, it } from "vitest";
import { chromium } from "playwright";
import { assertNoValidationErrors, advanceWizardStep, setScopedTime, setSearchField, selectAlbumTrack, waitForUpload, waitForEntityCard, skipBlockedEntity, assertNoSkippedFields } from "./draft-driver.js";
describe("SoundOn validation", () => {
    it("closes a blocked editor, allows the next field, but does not report complete metadata", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent('<input aria-label="Next field"><dialog open class="so-form-modal-dialog"><button onclick="this.closest(\'dialog\').close()">Cancel</button></dialog>');
            await skipBlockedEntity(page, 'Contributors', 'Test artist');
            await page.getByLabel('Next field').fill('continued');
            expect(await page.getByLabel('Next field').inputValue()).toBe('continued');
            expect(() => assertNoSkippedFields(page)).toThrow('Isian dilewati: Contributors: Test artist');
        }
        finally {
            await browser.close();
        }
    }, 10000);
    it("does not count search results in an open artist dialog as a saved credit", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><div class="soundon-formik-field-label">Primary artists</div><button>Add</button><dialog open><span>Husni</span></dialog><div id="saved"></div></section>`);
            expect(await waitForEntityCard(page, 'Primary artists', 'Husni', 50)).toBe(false);
            await page.locator('#saved').evaluate(el => el.textContent = 'Husni');
            expect(await waitForEntityCard(page, 'Primary artists', 'Husni', 50)).toBe(true);
            expect(await waitForEntityCard(page, 'Primary artists', 'Hus', 50)).toBe(false);
        }
        finally {
            await browser.close();
        }
    }, 10000);
    it("waits for queued audio even without an upload percentage", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="so-audio-preview-card-status-waiting">Waiting</div>`);
            await page.evaluate(() => setTimeout(() => {
                document.querySelector('div').className = 'so-audio-preview-card-status-success';
                document.querySelector('div').textContent = 'Upload complete';
            }, 1000));
            await waitForUpload(page, "audio");
            expect(await page.locator('div').innerText()).toBe('Upload complete');
        }
        finally {
            await browser.close();
        }
    }, 10000);
    it("waits until SoundOn finishes processing audio", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="so-audio-preview-card-status-processing">Processing audio</div>`);
            await page.evaluate(() => setTimeout(() => {
                document.querySelector('div').className = 'so-audio-preview-card-status-success';
                document.querySelector('div').textContent = 'Upload complete';
            }, 1000));
            await waitForUpload(page, "audio");
            expect(await page.locator('div').innerText()).toBe('Upload complete');
        }
        finally {
            await browser.close();
        }
    }, 10000);
    it("returns SoundOn audio processing error verbatim", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<div class="so-audio-preview-card-status-error">Audio file could not be processed: invalid codec</div>`);
            await expect(waitForUpload(page, "audio"))
                .rejects.toThrow("AUDIO_UPLOAD_FAILED:Audio file could not be processed: invalid codec");
        }
        finally {
            await browser.close();
        }
    }, 10000);
    it("opens only the requested album track even when every panel starts open", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent([1, 2].map(n => `<details open><summary onclick="event.preventDefault()"><span>track-${n}.wav</span><button onclick="window.deleted=true">Delete</button><button onclick="const card=this.closest('details');setTimeout(()=>card.toggleAttribute('open'),100)">Toggle</button></summary><div data-formik-id="tracks.${n}.title"><input value="Title ${n}"></div></details>`).join(""));
            await selectAlbumTrack(page, "track-2.wav");
            expect(await page.locator("input:visible").count()).toBe(1);
            expect(await page.locator("input:visible").inputValue()).toBe("Title 2");
            await page.locator("input:visible").fill("Updated track 2");
            expect(await page.locator("input").first().inputValue()).toBe("Title 1");
            await selectAlbumTrack(page, "track-1.wav");
            expect(await page.locator("input:visible").inputValue()).toBe("Title 1");
            expect(await page.evaluate(() => window.deleted)).toBeUndefined();
        }
        finally {
            await browser.close();
        }
    }, 10000);
    it("does not wait for a search input removed after artist selection", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span>
                <button onclick="document.querySelector('dialog').showModal()">Add</button></section>
                <dialog class="so-form-modal-dialog"><h4>Add artist</h4><div class="semi-select-content-wrapper">Choose</div>
                <input type="text"><div class="so-artist-preview-item-artist-name" onclick="document.querySelector('input').remove()">Husni</div>
                <button onclick="document.querySelector('#saved').textContent='Husni';this.closest('dialog').close();document.querySelector('#next-editor').showModal()">Submit</button></dialog>
                <dialog id="next-editor" class="so-form-modal-dialog"><h4>Add songwriter</h4><input type="text" value="Another person"><button>Submit</button></dialog>`);
            await setSearchField(page, "Primary artists", "Husni");
            expect(await page.locator("#saved").innerText()).toBe("Husni");
        }
        finally {
            await browser.close();
        }
    }, 8000);
    it("replaces a stale modal name before submitting a new artist", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section><span class="soundon-formik-field-label">Primary artists</span><span id="saved"></span>
                <button onclick="document.querySelector('dialog').showModal()">Add</button></section>
                <dialog class="so-form-modal-dialog"><h4>Add primary artist</h4>
                <div class="semi-select-content-wrapper">Choose</div><label>Artist name</label><input type="text" value="Sandy Andika">
                <div class="semi-select-option-list-outer-bottom-slot" onclick="document.querySelector('input').value='Sandy Andika'">Add “Randy Wahyuda” as an artist</div>
                <button onclick="document.querySelector('#saved').textContent=document.querySelector('input').value;this.closest('dialog').close()">Submit</button></dialog>`);
            await setSearchField(page, "Primary artists", "Randy Wahyuda");
            expect(await page.locator("#saved").innerText()).toBe("Randy Wahyuda");
        }
        finally {
            await browser.close();
        }
    }, 10000);
    it("commits pre-release time through the SoundOn option instead of transient input text", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<section id="card"><div id="field"><span>Pre-release time</span>
                <input type="text" value=""><button role="combobox" onclick="document.querySelector('[role=option]').hidden=false">Select time</button></div>
                <div role="option" hidden onclick="document.querySelector('[role=combobox]').textContent='12:00 AM';this.hidden=true;document.querySelector('#card').dataset.committed='00:00'">12:00 AM</div></section>`);
            await setScopedTime(page.locator('#card'), 'Pre-release time');
            expect(await page.locator('#card').getAttribute('data-committed')).toBe('00:00');
        }
        finally {
            await browser.close();
        }
    }, 10000);
    it("reports field errors instead of bypassing them with Later", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent(`<button onclick="document.querySelector('.semi-modal-wrap').hidden=false">Next</button>
                <div class="semi-modal-wrap" hidden><h3>4 errors detected</h3>
                <button onclick="window.bypassed=true">Later</button>
                <button onclick="this.parentElement.hidden=true;document.querySelector('[role=alert]').hidden=false">Fix now</button></div>
                <div role="alert" hidden>Primary artist must have a performing role</div>`);
            await expect(advanceWizardStep(page)).rejects.toThrow("SOUNDON_FORM_VALIDATION:4 errors detected:Primary artist must have a performing role");
            expect(await page.evaluate(() => window.bypassed)).toBeUndefined();
        }
        finally {
            await browser.close();
        }
    }, 15000);
    it("does not reject a valid form", async () => {
        const browser = await chromium.launch({ headless: true, executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE });
        try {
            const page = await browser.newPage();
            await page.setContent("<p>Track information</p>");
            await expect(assertNoValidationErrors(page)).resolves.toBeUndefined();
        }
        finally {
            await browser.close();
        }
    }, 15000);
});
