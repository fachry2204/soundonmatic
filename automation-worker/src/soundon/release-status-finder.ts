import type { Locator, Page, Request, Response } from "playwright";

export type SoundOnReleaseStatus = "under_review" | "delivery" | "approved" | "not_approved" | "live";
export type ReleaseStatusLookup = { key: string; title: string; artist: string };
export type LastUpdatedLookup = { key: string; title: string; artist: string };
export type ReleaseStatusMatch = {
    found: true;
    status: SoundOnReleaseStatus;
    release_url: string;
    upc: string | null;
    isrcs: string[];
    reason: string | null;
};
export type ReleaseStatusResult = ReleaseStatusMatch | { found: false; error: string; status?: never; release_url?: never };

const statuses: Array<{ value: SoundOnReleaseStatus; label: RegExp }> = [
    // Check the negative form before Approved so "Not Approved" is never
    // misclassified by the shorter positive expression.
    { value: "not_approved", label: /not\s*approved|rejected/i },
    { value: "under_review", label: /(?:under|in)\s*review|reviewing/i },
    { value: "delivery", label: /delivery|delivered/i },
    // Accept the misspelling defensively; the value stored by this app is canonical.
    { value: "approved", label: /approved|aproved/i },
    { value: "live", label: /\blive\b/i },
];

export const detectReleaseStatus = (text: string): SoundOnReleaseStatus | null =>
    statuses.find((status) => status.label.test(text))?.value ?? null;

async function statusFromCandidate(candidate: Locator): Promise<SoundOnReleaseStatus | null> {
    const text = await candidate.evaluate(element => {
        const headers = Array.from(element.closest('table,[role="grid"]')?.querySelectorAll('thead th,thead td,[role="columnheader"]') ?? []);
        const cells = Array.from(element.querySelectorAll(':scope > td,:scope > [role="cell"],:scope > [role="gridcell"]'));
        const column = headers.findIndex(header => /^(?:release\s*)?status$/i.test((header.textContent ?? '').trim()));
        if (column >= 0 && cells[column]) return (cells[column] as HTMLElement).innerText;
        // Avoid reading a release titled "Live" or "Not Approved" as its status.
        return cells.length > 1 ? cells.slice(1).map(cell => (cell as HTMLElement).innerText).join('\n') : (element as HTMLElement).innerText;
    });
    return detectReleaseStatus(text);
}

const normalize = (value: string): string => value.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase();
const comparable = (value: string): string => normalize(value).replace(/[^\p{L}\p{N}]+/gu, " ").trim();
const unique = (values: string[]): string[] => [...new Set(values)];
const identifiers = (text: string): { upc: string | null; isrcs: string[] } => ({
    upc: text.match(/\b(?:UPC|EAN)\s*[:#]?\s*(\d{12,13})\b/i)?.[1] ?? null,
    isrcs: unique([...text.matchAll(/\b[A-Z]{2}[A-Z0-9]{3}\d{7}\b/gi)].map((match) => match[0].toUpperCase())),
});

export const parseRejectionReason = (text: string): string | null => {
    const lines = text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    const explanationIndex = lines.findIndex((line) => /(?:was|is)\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i.test(line));
    if (explanationIndex >= 0) {
        const reasons: string[] = [];
        for (const line of lines.slice(explanationIndex + 1)) {
            const isBullet = /^[•·*\-–—]/.test(line);
            if (reasons.length && !isBullet) break;
            const cleaned = line.replace(/^[\s•·*\-–—]+/, "").trim();
            if (cleaned) reasons.push(cleaned);
        }
        if (reasons.length) return reasons.join("\n");
    }
    for (let index = 0; index < lines.length; index += 1) {
        const line = lines[index]!;
        const inline = line.match(/^(?:rejection\s+reason|reason(?:\s+for\s+rejection)?|alasan(?:\s+penolakan)?)\s*[:\-]?\s*(.+)$/i)?.[1]?.trim();
        if (inline && !/^(?:not\s*approved|rejected)$/i.test(inline)) return inline;
        if (/^(?:rejection\s+reason|reason(?:\s+for\s+rejection)?|alasan(?:\s+penolakan)?)\s*[:\-]?$/i.test(line)) {
            const next = lines[index + 1]?.trim();
            if (next) return next;
        }
    }
    return null;
};

async function rejectionReasonFrom(page: Page): Promise<string | null> {
    const heading = page.getByText(/the\s+track\s+.+\s+was\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i, { exact: false })
        .filter({ visible: true })
        .first();
    await heading.waitFor({ state: "visible", timeout: 8_000 }).catch(() => undefined);
    if (await heading.count()) {
        let scope = heading;
        for (let level = 0; level <= 6; level += 1) {
            const text = await scope.innerText().catch(() => "");
            const reason = parseRejectionReason(text);
            if (reason) return reason;
            scope = scope.locator("xpath=..");
        }
    }

    const notices = page.locator([
        '[role="alert"]',
        '[class*="alert" i]',
        '[class*="warning" i]',
    ].join(",")).filter({ hasText: /the\s+track\s+.+\s+was\s+not\s+approved\s+due\s+to\s+(?:the\s+)?following\s+reason/i });
    let selected: { reason: string; length: number } | null = null;
    for (let index = 0; index < await notices.count(); index += 1) {
        const text = await notices.nth(index).innerText().catch(() => "");
        const reason = parseRejectionReason(text);
        if (reason && (!selected || text.length < selected.length)) selected = { reason, length: text.length };
    }
    return selected?.reason ?? null;
}

async function releaseUrl(page: Page, candidate: Locator): Promise<string> {
    const links = candidate.locator("a[href]");
    for (let index = 0; index < await links.count(); index += 1) {
        const href = await links.nth(index).getAttribute("href");
        if (href && /release|library|detail/i.test(href)) return new URL(href, page.url()).toString();
    }
    return page.url();
}

async function inspectDetails(page: Page, candidate: Locator, url: string): Promise<{ upc: string | null; isrcs: string[]; reason: string | null }> {
    const valuesFrom = async (scope: Locator, readRejection = false): Promise<{ upc: string | null; isrcs: string[]; reason: string | null }> => {
        const text = await scope.innerText().catch(() => "");
        const inputs = await scope.locator("input, textarea").evaluateAll((elements) =>
            elements.map((element) => (element as HTMLInputElement).value || element.getAttribute("value") || "").join("\n"),
        ).catch(() => "");
        const combined = `${text}\n${inputs}`;
        const scopedReason = readRejection ? await rejectionReasonFrom(page) : null;
        return {
            ...identifiers(combined),
            reason: readRejection ? scopedReason ?? parseRejectionReason(combined) : null,
        };
    };
    const rowValues = await valuesFrom(candidate);
    if (rowValues.upc && rowValues.isrcs.length) return rowValues;
    if (url === page.url()) return rowValues;

    const detail = await page.context().newPage();
    try {
        await detail.goto(url, { waitUntil: "domcontentloaded" });
        await detail.waitForLoadState("networkidle", { timeout: 10_000 }).catch(() => undefined);
        await detail.getByText(/was\s+not\s+approved\s+due\s+to/i, { exact: false })
            .filter({ visible: true }).first()
            .waitFor({ state: "visible", timeout: 8_000 }).catch(() => undefined);
        const detailValues = await valuesFrom(detail.locator("body"), true);
        return { upc: rowValues.upc ?? detailValues.upc, isrcs: unique([...rowValues.isrcs, ...detailValues.isrcs]), reason: detailValues.reason ?? rowValues.reason };
    } finally {
        await detail.close();
    }
}

async function selectStatus(page: Page, label: RegExp): Promise<boolean> {
    const tab = page.getByRole("tab", { name: label }).filter({ visible: true }).first();
    const button = page.getByRole("button", { name: label }).filter({ visible: true }).first();
    const target = await tab.count() ? tab : button;
    if (!await target.count()) return false;
    await target.click({ timeout: 5_000 });
    await page.waitForTimeout(1_000);
    return true;
}

async function search(page: Page, title: string): Promise<void> {
    let input = page.getByPlaceholder(/search|cari|find release/i).filter({ visible: true }).first();
    if (!await input.count()) input = page.getByRole("textbox").filter({ visible: true }).first();
    if (!await input.count()) throw new Error("SOUNDON_SEARCH_UNCONFIRMED:search_input_missing");
    const pending = new Set<Request>();
    let failedRequest = false;
    let completedRequests = 0;
    const onRequest = (request: Request) => {
        if (!['fetch', 'xhr'].includes(request.resourceType())) return;
        const path = new URL(request.url()).pathname;
        if (/analytics|telemetry|collect|monitor|report|\/log\b|\/event\b/i.test(path)) return;
        pending.add(request);
    };
    const onFinished = (request: Request) => { if (pending.delete(request)) completedRequests++; };
    const onFailed = (request: Request) => { if (pending.delete(request)) failedRequest = true; };
    const onResponse = (response: Response) => { if (pending.has(response.request()) && response.status() >= 400) failedRequest = true; };
    page.on('request', onRequest);
    page.on('requestfinished', onFinished);
    page.on('requestfailed', onFailed);
    page.on('response', onResponse);
    // A fast read can observe the previous search results while SoundOn is
    // debouncing or fetching. Only trust a matching result or an explicit
    // empty state, never the mere absence of a row after a fixed delay.
    const started = Date.now();
    let lastEmpty = '';
    let emptySince = started;
    try {
        await input.fill(title);
        await input.press("Enter").catch(() => undefined);
        while (Date.now() - started < 20_000) {
        const state = await page.evaluate((query) => {
            const visible = (element: Element) => {
                const node = element as HTMLElement;
                return !!(node.offsetWidth || node.offsetHeight || node.getClientRects().length);
            };
            const busy = Array.from(document.querySelectorAll('[aria-busy="true"], [role="progressbar"], .semi-spin-spinning, .ant-spin-spinning'))
                .some(visible);
            const clean = (value: string) => value.normalize('NFKC').toLocaleLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
            const wanted = clean(query);
            const rows = Array.from(document.querySelectorAll('tr, [role="row"]')).filter(visible);
            const matching = rows.some(row => {
                const text = (row as HTMLElement).innerText || '';
                const lines = text.split(/\r?\n/).map(clean);
                return lines.includes(wanted) || (` ${clean(text)} `).includes(` ${wanted} `);
            });
            const empty = Array.from(document.querySelectorAll('.semi-empty, .ant-empty, [role="status"], td, [class*="empty" i]'))
                .filter(visible).some(element => /\b(no (?:data|results?|releases?|records?)|nothing found|no matching releases?)\b/i.test((element as HTMLElement).innerText || ''));
            const currentQuery = new URL(location.href).searchParams.get('searchKey');
            return { busy, matching, empty, queryBound: currentQuery !== null, queryApplied: currentQuery === null || clean(currentQuery) === wanted,
                hasRows: rows.some(row => row.querySelector('td,[role="cell"],[role="gridcell"]')),
                rows: rows.map(row => (row as HTMLElement).innerText || '').join('\n'), path: location.pathname };
        }, title);
        if (/login|sign-in/i.test(state.path)) throw new Error('AUTH_SESSION_EXPIRED');
        if (!state.busy && state.queryApplied && state.matching && !pending.size && !failedRequest) return;
        const snapshot = JSON.stringify([state.rows, state.empty, state.queryApplied]);
        if (state.busy || pending.size || snapshot !== lastEmpty) emptySince = Date.now();
        lastEmpty = snapshot;
        // SoundOn searches individual words, so a valid result page can list
        // similar titles before the exact match on a later page. Its applied
        // URL query plus a finished request and settled rows confirms that
        // search; requiring the full phrase here prevented pagination.
        const settledResults = state.empty || (state.queryBound && state.hasRows && completedRequests > 0);
        if (!state.busy && !pending.size && !failedRequest && state.queryApplied && settledResults && Date.now() - started >= 2500 && Date.now() - emptySince >= 1500) return;
        await page.waitForTimeout(200);
        }
        throw new Error('search timeout');
    } catch (error) {
        if (error instanceof Error && error.message === 'AUTH_SESSION_EXPIRED') throw error;
        console.warn(JSON.stringify({ event: 'soundon_search_unconfirmed', query: title,
            rows: (await page.locator('tr:visible,[role="row"]:visible').allInnerTexts().catch(() => [])).slice(0, 3) }));
        throw new Error(`SOUNDON_SEARCH_UNCONFIRMED:SoundOn belum menampilkan hasil atau informasi kosong untuk pencarian "${title}"`);
    } finally {
        page.off('request', onRequest);
        page.off('requestfinished', onFinished);
        page.off('requestfailed', onFailed);
        page.off('response', onResponse);
    }
}

async function nextPage(page: Page): Promise<boolean> {
    const candidates = page.locator([
        'li[class*="next" i]:visible button',
        'li[class*="next" i]:visible a',
        'button[aria-label*="next" i]:visible',
        'a[aria-label*="next" i]:visible',
        'button[title*="next" i]:visible',
        'a[title*="next" i]:visible',
        '[class*="pagination" i] button:visible',
        '[class*="pagination" i] a:visible',
        '.semi-page-next:visible',
        '.semi-pagination-next:visible',
    ].join(","));
    const count = await candidates.count();
    let next: Locator | null = null;
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
    if (!next) return false;
    const parent = next.locator("xpath=..");
    const disabled = await next.isDisabled().catch(() => false)
        || (await next.getAttribute("aria-disabled")) === "true"
        || /disabled/i.test(`${await next.getAttribute("class") ?? ""} ${await parent.getAttribute("class").catch(() => "")}`);
    if (disabled) return false;

    const before = await page.locator('tr:visible, [role="row"]:visible, article:visible, li:visible, [class*="card" i]:visible').allInnerTexts().then((rows) => rows.slice(0, 8).join("\n")).catch(() => "");
    await next.click({ timeout: 5_000 });
    for (let attempt = 0; attempt < 40; attempt++) {
        await page.waitForTimeout(200);
        const after = await page.locator('tr:visible, [role="row"]:visible, article:visible, li:visible, [class*="card" i]:visible').allInnerTexts().then((rows) => rows.slice(0, 8).join("\n")).catch(() => "");
        if (normalize(after) !== normalize(before)) return true;
    }
    throw new Error('SOUNDON_SEARCH_UNCONFIRMED:Halaman hasil SoundOn tidak berubah setelah tombol berikutnya ditekan.');
}

export async function candidateFor(page: Page, title: string, artist: string, strictArtist = false): Promise<Locator | null> {
    const wantedTitle = comparable(title);
    const wantedArtist = comparable(artist);
    // Do not union every possible row/card selector: a single release is often
    // represented by a <tr>, nested role=row, and card-like descendants at the
    // same time. Counting all of those made a unique title look ambiguous and
    // produced false "not found" results. Prefer one semantic row type at a
    // time, falling back only when the page has no table/list rows.
    let rows = page.locator("tr:visible");
    if (!await rows.count()) rows = page.locator('[role="row"]:visible');
    if (!await rows.count()) rows = page.locator("article:visible");
    if (!await rows.count()) rows = page.locator("li:visible");
    if (!await rows.count()) rows = page.locator('[class*="card" i]:visible');
    if (!await rows.count()) rows = page.locator("div:visible");
    const index = await rows.evaluateAll((elements, wanted) => {
        const __name = (window as any).__name || ((fn: any) => fn);
        const normalizeBrowser = (value: string) => value.normalize("NFKC").trim().replace(/\s+/g, " ").toLocaleLowerCase();
        const comparableBrowser = (value: string) => normalizeBrowser(value).replace(/[^\p{L}\p{N}]+/gu, " ").trim();
        const limit = Math.min(elements.length, 500);
        const titleMatches: number[] = [];
        for (let rowIndex = 0; rowIndex < limit; rowIndex += 1) {
            const element = elements[rowIndex] as HTMLElement;
            if (!element || !(element.offsetWidth || element.offsetHeight || element.getClientRects().length)) continue;
            const raw = element.innerText || element.textContent || "";
            const lines = raw.split(/\r?\n/).map(comparableBrowser).filter(Boolean);
            // In some SoundOn tables each cell is separated by tabs/spaces in
            // innerText rather than newlines. Match the title as a bounded
            // phrase across the row text as well as as a standalone line.
            const rowText = ` ${comparableBrowser(raw)} `;
            const cells = Array.from(element.querySelectorAll(':scope > td, :scope > [role="cell"], :scope > [role="gridcell"]'));
            const titleNodes = Array.from(element.querySelectorAll('.so-album-preview-item-title-text,.so-album-preview-item-title,[data-release-title]'));
            // Drafts include a checkbox column; All Releases may omit it. Use
            // the title component/header instead of assuming cell zero.
            const headers = Array.from(element.closest('table,[role="grid"]')?.querySelectorAll('thead th,thead td,[role="columnheader"]') ?? []);
            const titleColumn = headers.findIndex(header => /^(?:title|release title|album title)$/i.test((header.textContent ?? '').trim()));
            const titleParts = titleNodes.length
                ? titleNodes.flatMap(node => [node.textContent ?? '', node.getAttribute('title') ?? '', node.getAttribute('data-release-title') ?? ''])
                : (titleColumn >= 0 ? [cells[titleColumn]].filter(Boolean) : cells).flatMap(cell => ((cell as HTMLElement).innerText || '').split(/\r?\n|\t/));
            if (!cells.length && !titleNodes.length) titleParts.push(...raw.split(/\r?\n|\t/));
            if (!titleParts.map(comparableBrowser).includes(wanted.title)) continue;
            titleMatches.push(rowIndex);
            // CSS ellipsis usually preserves textContent. Other layouts expose
            // the full artist only through a title or accessible label.
            const fullNames = Array.from(element.querySelectorAll('[title],[aria-label],[artistname],.so-artist-preview-item-artist-name,.semi-tag-content'))
                .flatMap(node => [node.textContent ?? '', node.getAttribute('title') ?? '', node.getAttribute('aria-label') ?? '', node.getAttribute('artistname') ?? ''])
                .map(comparableBrowser);
            const artistMatches = lines.some((line) =>
                line === wanted.artist || line.replace(/^primary artists?\s*/, "") === wanted.artist,
            ) || fullNames.includes(wanted.artist) || (wanted.artist !== "" && rowText.includes(` ${wanted.artist} `));
            if (!wanted.artist || artistMatches) return rowIndex;
        }
        // Never substitute a different artist based on a unique title alone.
        return !wanted.strictArtist && !wanted.artist && titleMatches.length === 1 ? (titleMatches[0] ?? -1) : -1;
    }, { title: wantedTitle, artist: wantedArtist, strictArtist });
    return index >= 0 ? rows.nth(index) : null;
}

export async function findReleaseStatuses(page: Page, lookups: ReleaseStatusLookup[], strictArtist = false): Promise<Record<string, ReleaseStatusResult>> {
    const matches: Record<string, ReleaseStatusResult> = {};

    // The duplicate gate only needs to know whether an exact title + artist
    // exists. Scanning every SoundOn page once for every Pending release made
    // a batch of 49 take hours. A library search filters globally, so use one
    // bounded search per release and do not open details or paginate here.
    if (strictArtist) {
        for (const lookup of lookups) {
            if (!normalize(lookup.title) || !normalize(lookup.artist)) continue;
            await search(page, lookup.title);
            const candidate = await candidateFor(page, lookup.title, lookup.artist, true);
            if (!candidate) continue;
            const status = await statusFromCandidate(candidate) ?? "under_review";
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

    const titlesSeen = new Set<string>();
    const inspectAcrossPages = async (lookup: ReleaseStatusLookup, knownStatus?: SoundOnReleaseStatus, paginate = true): Promise<boolean> => {
        for (let pageNumber = 1; pageNumber <= 100; pageNumber += 1) {
            const candidate = await candidateFor(page, lookup.title, lookup.artist, Boolean(normalize(lookup.artist)) || strictArtist);
            if (!candidate && await candidateFor(page, lookup.title, '')) titlesSeen.add(lookup.key);
            if (candidate) {
                const detectedValue = await statusFromCandidate(candidate) ?? knownStatus;
                const detected = detectedValue ? statuses.find((item) => item.value === detectedValue) : undefined;
                if (!detected) throw new Error(`SOUNDON_STATUS_UNCONFIRMED:Judul dan artis ditemukan, tetapi status SoundOn tidak dikenali: ${lookup.title} — ${lookup.artist}`);
                if (detected) {
                    const url = await releaseUrl(page, candidate);
                    const values = ["delivery", "approved", "live", "not_approved"].includes(detected.value)
                        ? await inspectDetails(page, candidate, url)
                        : { upc: null, isrcs: [], reason: null };
                    matches[lookup.key] = { found: true, status: detected.value, release_url: url, ...values };
                    return true;
                }
            }
            if (!paginate || !await nextPage(page)) break;
            if (pageNumber === 100) throw new Error('SOUNDON_SEARCH_UNCONFIRMED:Batas halaman pemeriksaan tercapai; hasil belum dapat dipastikan.');
        }
        return false;
    };

    const searchTerms = (lookup: ReleaseStatusLookup): string[] => unique([lookup.title.trim(), lookup.artist.trim()].filter(Boolean));

    // One uncertain release must not discard verified results for the rest of
    // a batch. Search each title globally before walking its filtered pages.
    let statusFiltered = false;
    for (const lookup of lookups) {
        if (!normalize(lookup.title)) continue;
        try {
            if (statusFiltered) {
                if (!await selectStatus(page, /^all\s*(?:releases|status)$/i)) {
                    throw new Error('SOUNDON_SEARCH_UNCONFIRMED:Filter status SoundOn belum dapat dikembalikan ke All Releases.');
                }
                statusFiltered = false;
            }
            if (await inspectAcrossPages(lookup, undefined, false)) continue;
            let uncertain: unknown = null;
            for (const term of searchTerms(lookup)) {
                try { await search(page, term); }
                catch (error) {
                    if (!(error instanceof Error) || !error.message.startsWith('SOUNDON_SEARCH_UNCONFIRMED:')) throw error;
                    uncertain = error;
                    continue;
                }
                if (await inspectAcrossPages(lookup)) break;
            }
            for (const status of statuses) {
                if (matches[lookup.key]) break;
                if (!await selectStatus(page, status.label)) continue;
                statusFiltered = true;
                for (const term of searchTerms(lookup)) {
                    await search(page, term);
                    if (await inspectAcrossPages(lookup, status.value)) break;
                }
            }
            if (!matches[lookup.key] && titlesSeen.has(lookup.key)) {
                throw new Error(`SOUNDON_ARTIST_UNCONFIRMED:Judul ditemukan, tetapi nama artis lengkap belum cocok: ${lookup.title} — ${lookup.artist}`);
            }
            if (!matches[lookup.key] && uncertain) throw uncertain;
        } catch (error) {
            if (page.isClosed() || /AUTH_SESSION_EXPIRED/.test(error instanceof Error ? error.message : '')) throw error;
            matches[lookup.key] = { found: false, error: error instanceof Error ? error.message : 'SOUNDON_SEARCH_UNCONFIRMED' };
        }
    }
    return matches;
}

/** Find SoundOn's Last Updated Date for matching releases from Soundfresh. */
export async function findLastUpdatedDates(page: Page, lookups: LastUpdatedLookup[]): Promise<Record<string, string>> {
    const result: Record<string, string> = {};
    await selectStatus(page, /under\s*review/i);

    for (const item of lookups) {
        if (!normalize(item.title) || !normalize(item.artist)) continue;
        await search(page, item.title);
        let candidate = await candidateFor(page, item.title, item.artist, true);
        if (!candidate) continue;

        const updated = await candidate.evaluate((element) => {
            const row = element.closest("tr, [role=row]") ?? element;
            const table = row.closest("table");
            const headers = table
                ? Array.from(table.querySelectorAll("thead th")).map((header) => (header.textContent || "").replace(/\s+/g, " ").trim().toLowerCase())
                : [];
            const index = headers.findIndex((header) => /last\s*updated\s*date|last\s*updated|updated\s*date/.test(header));
            const cells = Array.from(row.querySelectorAll(":scope > td, :scope > [role=cell]"));
            if (index >= 0 && cells[index]) return (cells[index]?.textContent || "").replace(/\s+/g, " ").trim();

            const lines = ((row as HTMLElement).innerText || row.textContent || "").split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
            for (let position = 0; position < lines.length; position += 1) {
                const match = lines[position]?.match(/^last\s*updated(?:\s*date)?\s*:?\s*(.*)$/i);
                if (!match) continue;
                return (match[1] || lines[position + 1] || "").replace(/\s+/g, " ").trim();
            }
            return "";
        }).catch(() => "");
        if (updated) result[item.key] = updated;
    }

    return result;
}
