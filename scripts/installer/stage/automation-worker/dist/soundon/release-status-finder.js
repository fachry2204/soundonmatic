const statuses = [
    // Check the negative form before Approved so "Not Approved" is never
    // misclassified by the shorter positive expression.
    { value: "not_approved", label: /not\s*approved|rejected/i },
    { value: "under_review", label: /(?:under|in)\s*review|reviewing/i },
    { value: "delivery", label: /delivery|delivered/i },
    // Accept the misspelling defensively; the value stored by this app is canonical.
    { value: "approved", label: /approved|aproved/i },
    { value: "live", label: /\blive\b/i },
];
export const detectReleaseStatus = (text) => statuses.find((status) => status.label.test(text))?.value ?? null;
const normalize = (value) => value.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase();
const comparable = (value) => normalize(value).replace(/[^\p{L}\p{N}]+/gu, " ").trim();
const unique = (values) => [...new Set(values)];
const identifiers = (text) => ({
    upc: text.match(/\b(?:UPC|EAN)\s*[:#]?\s*(\d{12,13})\b/i)?.[1] ?? null,
    isrcs: unique([...text.matchAll(/\b[A-Z]{2}[A-Z0-9]{3}\d{7}\b/gi)].map((match) => match[0].toUpperCase())),
});
export const parseRejectionReason = (text) => {
    const lines = text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    const explanationIndex = lines.findIndex((line) => /(?:was|is)\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i.test(line));
    if (explanationIndex >= 0) {
        const reasons = [];
        for (const line of lines.slice(explanationIndex + 1)) {
            const isBullet = /^[•·*\-–—]/.test(line);
            if (reasons.length && !isBullet)
                break;
            const cleaned = line.replace(/^[\s•·*\-–—]+/, "").trim();
            if (cleaned)
                reasons.push(cleaned);
        }
        if (reasons.length)
            return reasons.join("\n");
    }
    for (let index = 0; index < lines.length; index += 1) {
        const line = lines[index];
        const inline = line.match(/^(?:rejection\s+reason|reason(?:\s+for\s+rejection)?|alasan(?:\s+penolakan)?)\s*[:\-]?\s*(.+)$/i)?.[1]?.trim();
        if (inline && !/^(?:not\s*approved|rejected)$/i.test(inline))
            return inline;
        if (/^(?:rejection\s+reason|reason(?:\s+for\s+rejection)?|alasan(?:\s+penolakan)?)\s*[:\-]?$/i.test(line)) {
            const next = lines[index + 1]?.trim();
            if (next)
                return next;
        }
    }
    return null;
};
async function rejectionReasonFrom(page) {
    const heading = page.getByText(/the\s+track\s+.+\s+was\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i, { exact: false })
        .filter({ visible: true })
        .first();
    await heading.waitFor({ state: "visible", timeout: 8_000 }).catch(() => undefined);
    if (await heading.count()) {
        let scope = heading;
        for (let level = 0; level <= 6; level += 1) {
            const text = await scope.innerText().catch(() => "");
            const reason = parseRejectionReason(text);
            if (reason)
                return reason;
            scope = scope.locator("xpath=..");
        }
    }
    const notices = page.locator([
        '[role="alert"]',
        '[class*="alert" i]',
        '[class*="warning" i]',
    ].join(",")).filter({ hasText: /the\s+track\s+.+\s+was\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i });
    let selected = null;
    for (let index = 0; index < await notices.count(); index += 1) {
        const text = await notices.nth(index).innerText().catch(() => "");
        const reason = parseRejectionReason(text);
        if (reason && (!selected || text.length < selected.length))
            selected = { reason, length: text.length };
    }
    return selected?.reason ?? null;
}
async function releaseUrl(page, candidate) {
    const links = candidate.locator("a[href]");
    for (let index = 0; index < await links.count(); index += 1) {
        const href = await links.nth(index).getAttribute("href");
        if (href && /release|library|detail/i.test(href))
            return new URL(href, page.url()).toString();
    }
    return page.url();
}
async function inspectDetails(page, candidate, url) {
    const valuesFrom = async (scope, readRejection = false) => {
        const text = await scope.innerText().catch(() => "");
        const inputs = await scope.locator("input, textarea").evaluateAll((elements) => elements.map((element) => element.value || element.getAttribute("value") || "").join("\n")).catch(() => "");
        const combined = `${text}\n${inputs}`;
        const scopedReason = readRejection ? await rejectionReasonFrom(page) : null;
        return {
            ...identifiers(combined),
            reason: readRejection ? scopedReason ?? parseRejectionReason(combined) : null,
        };
    };
    const rowValues = await valuesFrom(candidate);
    if (rowValues.upc && rowValues.isrcs.length)
        return rowValues;
    if (url === page.url())
        return rowValues;
    const detail = await page.context().newPage();
    try {
        await detail.goto(url, { waitUntil: "domcontentloaded" });
        await detail.waitForLoadState("networkidle", { timeout: 10_000 }).catch(() => undefined);
        await detail.getByText(/was\s+not\s+approved\s+due\s+to/i, { exact: false })
            .filter({ visible: true }).first()
            .waitFor({ state: "visible", timeout: 8_000 }).catch(() => undefined);
        const detailValues = await valuesFrom(detail.locator("body"), true);
        return { upc: rowValues.upc ?? detailValues.upc, isrcs: unique([...rowValues.isrcs, ...detailValues.isrcs]), reason: detailValues.reason ?? rowValues.reason };
    }
    finally {
        await detail.close();
    }
}
async function selectStatus(page, label) {
    const tab = page.getByRole("tab", { name: label }).filter({ visible: true }).first();
    const button = page.getByRole("button", { name: label }).filter({ visible: true }).first();
    const target = await tab.count() ? tab : button;
    if (!await target.count())
        return false;
    await target.click({ timeout: 5_000 });
    await page.waitForTimeout(1_000);
    return true;
}
async function search(page, title) {
    let input = page.getByPlaceholder(/search|cari/i).filter({ visible: true }).first();
    if (!await input.count())
        input = page.getByRole("textbox").filter({ visible: true }).first();
    if (!await input.count())
        return;
    await input.fill(title);
    await input.press("Enter").catch(() => undefined);
    const exactTitle = page.getByText(title, { exact: true }).filter({ visible: true }).first();
    await exactTitle.waitFor({ state: "visible", timeout: 1_200 }).catch(() => undefined);
    // SoundOn applies an additional debounce after the input value changes.
    await page.waitForTimeout(300);
}
async function nextPage(page) {
    const candidates = page.locator([
        'li[class*="next" i]:visible button',
        'li[class*="next" i]:visible a',
        'button[aria-label*="next" i]:visible',
        'a[aria-label*="next" i]:visible',
        'button[title*="next" i]:visible',
        'a[title*="next" i]:visible',
        '[class*="pagination" i] button:visible',
        '[class*="pagination" i] a:visible',
    ].join(","));
    const count = await candidates.count();
    let next = null;
    for (let index = 0; index < count; index += 1) {
        const item = candidates.nth(index);
        const text = normalize(await item.innerText().catch(() => ""));
        const aria = normalize((await item.getAttribute("aria-label")) ?? "");
        const title = normalize((await item.getAttribute("title")) ?? "");
        const classes = `${await item.getAttribute("class") ?? ""} ${await item.locator("xpath=..").getAttribute("class").catch(() => "")}`;
        if (/next/.test(`${aria} ${title}`) || /^(next|›|»|>)$/.test(text) || /\bnext\b/i.test(classes)) {
            next = item;
            break;
        }
    }
    if (!next)
        return false;
    const parent = next.locator("xpath=..");
    const disabled = await next.isDisabled().catch(() => false)
        || (await next.getAttribute("aria-disabled")) === "true"
        || /disabled/i.test(`${await next.getAttribute("class") ?? ""} ${await parent.getAttribute("class").catch(() => "")}`);
    if (disabled)
        return false;
    const before = await page.locator('tr:visible, [role="row"]:visible, article:visible, li:visible, [class*="card" i]:visible').allInnerTexts().then((rows) => rows.slice(0, 8).join("\n")).catch(() => "");
    await next.click({ timeout: 5_000 });
    await page.waitForTimeout(700);
    const after = await page.locator('tr:visible, [role="row"]:visible, article:visible, li:visible, [class*="card" i]:visible').allInnerTexts().then((rows) => rows.slice(0, 8).join("\n")).catch(() => "");
    return normalize(after) !== normalize(before);
}
async function candidateFor(page, title, artist, strictArtist = false) {
    const wantedTitle = comparable(title);
    const wantedArtist = comparable(artist);
    let rows = page.locator('tr, [role="row"], article, li, [class*="card" i]');
    if (!await rows.count())
        rows = page.locator("div");
    const index = await rows.evaluateAll((elements, wanted) => {
        const __name = window.__name || ((fn) => fn);
        const normalizeBrowser = (value) => value.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase();
        const comparableBrowser = (value) => normalizeBrowser(value).replace(/[^\p{L}\p{N}]+/gu, " ").trim();
        const limit = Math.min(elements.length, 500);
        const titleMatches = [];
        for (let rowIndex = 0; rowIndex < limit; rowIndex += 1) {
            const element = elements[rowIndex];
            if (!element || !(element.offsetWidth || element.offsetHeight || element.getClientRects().length))
                continue;
            const raw = element.innerText || element.textContent || "";
            const lines = raw.split(/\r?\n/).map(comparableBrowser).filter(Boolean);
            if (!lines.includes(wanted.title))
                continue;
            titleMatches.push(rowIndex);
            const artistMatches = lines.some((line) => line === wanted.artist || line.replace(/^primary artists?\s*/, "") === wanted.artist);
            if (!wanted.artist || artistMatches)
                return rowIndex;
        }
        // Soundfresh and SoundOn often format artist credits differently.
        // An exact title is safe when the filtered result contains only one row.
        return !wanted.strictArtist && titleMatches.length === 1 ? (titleMatches[0] ?? -1) : -1;
    }, { title: wantedTitle, artist: wantedArtist, strictArtist });
    return index >= 0 ? rows.nth(index) : null;
}
export async function findReleaseStatuses(page, lookups, strictArtist = false) {
    const matches = {};
    // The duplicate gate only needs to know whether an exact title + artist
    // exists. Scanning every SoundOn page once for every Pending release made
    // a batch of 49 take hours. A library search filters globally, so use one
    // bounded search per release and do not open details or paginate here.
    if (strictArtist) {
        for (const lookup of lookups) {
            if (!normalize(lookup.title) || !normalize(lookup.artist))
                continue;
            await search(page, lookup.title);
            const candidate = await candidateFor(page, lookup.title, lookup.artist, true);
            if (!candidate)
                continue;
            const raw = await candidate.innerText().catch(() => "");
            const status = detectReleaseStatus(raw) ?? "under_review";
            matches[lookup.key] = {
                found: true,
                status,
                release_url: await releaseUrl(page, candidate),
                upc: null,
                isrcs: [],
                reason: null,
            };
        }
        return matches;
    }
    const inspectCandidate = async (lookup) => {
        const candidate = await candidateFor(page, lookup.title, lookup.artist, strictArtist);
        if (!candidate)
            return false;
        const raw = await candidate.innerText().catch(() => "");
        const detectedValue = detectReleaseStatus(raw);
        const detected = detectedValue ? statuses.find((status) => status.value === detectedValue) : undefined;
        if (!detected)
            return false;
        const url = await releaseUrl(page, candidate);
        const values = ["delivery", "approved", "live", "not_approved"].includes(detected.value)
            ? await inspectDetails(page, candidate, url)
            : { upc: null, isrcs: [], reason: null };
        matches[lookup.key] = { found: true, status: detected.value, release_url: url, ...values };
        return true;
    };
    const inspectAcrossPages = async (lookup, knownStatus) => {
        for (let pageNumber = 1; pageNumber <= 100; pageNumber += 1) {
            const candidate = await candidateFor(page, lookup.title, lookup.artist, strictArtist);
            if (candidate) {
                const raw = await candidate.innerText().catch(() => "");
                const detectedValue = knownStatus ?? detectReleaseStatus(raw);
                const detected = detectedValue ? statuses.find((item) => item.value === detectedValue) : undefined;
                if (detected) {
                    const url = await releaseUrl(page, candidate);
                    const values = ["delivery", "approved", "live", "not_approved"].includes(detected.value)
                        ? await inspectDetails(page, candidate, url)
                        : { upc: null, isrcs: [], reason: null };
                    matches[lookup.key] = { found: true, status: detected.value, release_url: url, ...values };
                    return true;
                }
            }
            if (!await nextPage(page))
                break;
        }
        return false;
    };
    const searchTerms = (lookup) => unique([
        lookup.title.trim(),
        `${lookup.title} ${lookup.artist}`.trim(),
        lookup.artist.trim(),
    ].filter(Boolean));
    // Some SoundOn layouts show all releases in one view and expose status only
    // as a row badge (without tabs). Inspect the already-rendered rows before
    // touching the search input; filtering can temporarily detach those rows.
    for (const lookup of lookups) {
        if (!normalize(lookup.title))
            continue;
        await inspectAcrossPages(lookup);
    }
    // Search only the titles that were not part of the currently rendered page.
    for (const lookup of lookups) {
        if (matches[lookup.key] || !normalize(lookup.title))
            continue;
        for (const term of searchTerms(lookup)) {
            await search(page, term);
            if (await inspectAcrossPages(lookup))
                break;
        }
    }
    for (const status of statuses) {
        if (!await selectStatus(page, status.label))
            continue;
        for (const lookup of lookups) {
            if (matches[lookup.key] || !normalize(lookup.title))
                continue;
            for (const term of searchTerms(lookup)) {
                await search(page, term);
                if (await inspectAcrossPages(lookup, status.value))
                    break;
            }
        }
    }
    return matches;
}
