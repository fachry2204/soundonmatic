import type { Page } from "playwright";

export type DraftMatch = {
    found: true;
    draft_id: string;
    draft_url: string;
};
export type DraftLookup = { key: string; title: string; artist: string };

const normalize = (value: string): string =>
    value.normalize("NFKC").trim().replace(/[’‘`]/g, "'").replace(/\s+/g, " ").toLocaleLowerCase();

const isSameArtistLine = (line: string, artist: string): boolean => {
    const normalized = normalize(line);
    return normalized === artist
        || normalized.replace(/^primary artists?\s*:?\s*/, "") === artist;
};

const syntheticDraftId = (title: string, artist: string): string => {
    const input = `${title}\u0000${artist}`;
    let hash = 2166136261;
    for (let index = 0; index < input.length; index += 1) {
        hash ^= input.charCodeAt(index);
        hash = Math.imul(hash, 16777619);
    }
    return `existing-${(hash >>> 0).toString(16)}`;
};

const draftIdFromUrl = (value: string): string | null => {
    const url = new URL(value);
    if (!url.pathname.match(/(?:draft|release|publish\/single)/i) && url.searchParams.get("source") !== "draft") return null;
    return url.searchParams.get("id")
        ?? url.pathname.match(/(?:draft|release)\/([^/]+)$/i)?.[1]
        ?? null;
};

async function searchDraftList(page: Page, title: string): Promise<boolean> {
    let search = page
        .getByPlaceholder(/search|cari|find release/i)
        .filter({ visible: true })
        .first();
    if (!(await search.count())) {
        search = page.getByRole("textbox").filter({ visible: true }).first();
    }
    if (!(await search.count())) return false;

    await search.fill(title);
    await search.press("Enter").catch(() => undefined);
    await waitForDraftResults(page);
    return true;
}

async function waitForDraftResults(page: Page): Promise<void> {
    // A 350ms debounce is not evidence of an absent draft. Require settled,
    // meaningful results; a blank shell/loading/error must block creation.
    const started = Date.now();
    let last = '';
    let stableSince = started;
    while (Date.now() - started < 15000) {
        const state = await page.evaluate(() => {
            const visible = (element: Element) => element.getClientRects().length > 0
                && getComputedStyle(element).visibility !== 'hidden';
            const busy = Array.from(document.querySelectorAll('[aria-busy="true"],.semi-spin-spinning,[role="progressbar"]')).some(visible);
            const rows = Array.from(document.querySelectorAll('tr,[role="row"],article,[data-testid*="draft" i],[class*="card" i]'))
                .filter(visible).map(element => (element as HTMLElement).innerText).join('\n');
            const empty = Array.from(document.querySelectorAll('.semi-empty,[role="status"],p,div'))
                .filter(visible).some(element => /^(no (?:data|results?|releases?|drafts?)(?: found)?|nothing found|tidak ada data)[.!]?$/i.test(element.textContent?.trim() ?? ''));
            const error = Array.from(document.querySelectorAll('[role="alert"],.semi-empty'))
                .filter(visible).some(element => /failed|error|try again|gagal/i.test(element.textContent ?? ''));
            return { busy, rows, empty, error };
        });
        if (state.error) throw new Error('DRAFT_LOOKUP_UNCONFIRMED:SoundOn menampilkan error saat mencari draft');
        const snapshot = JSON.stringify([state.rows, state.empty]);
        if (state.busy || snapshot !== last) stableSince = Date.now();
        last = snapshot;
        if (!state.busy && (state.rows.trim() || state.empty)
            && Date.now() - started >= 2500 && Date.now() - stableSince >= 1000) {
            await page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {
                throw new Error('DRAFT_LOOKUP_UNCONFIRMED:Pencarian draft belum selesai memuat data');
            });
            return;
        }
        await page.waitForTimeout(200);
    }
    throw new Error('DRAFT_LOOKUP_UNCONFIRMED:Hasil pencarian draft belum terkonfirmasi; upload baru dihentikan');
}

async function ensureDraftsView(page: Page): Promise<void> {
    const draftsTab = page.getByRole("tab", { name: /^drafts?\b/i }).filter({ visible: true }).first();
    const draftsText = page.getByText(/^drafts?(?:\s+\d+)?$/i, { exact: true }).filter({ visible: true }).first();
    const target = await draftsTab.count() ? draftsTab : draftsText;
    if (!await target.count()) return;

    const selected = await target.getAttribute("aria-selected").catch(() => null);
    const className = await target.getAttribute("class").catch(() => "");
    if (selected === "true" || /active|selected/i.test(className || "")) return;
    await target.click();
    await page.waitForTimeout(2_000);
}

async function referenceForMatchedCandidate(
    page: Page,
    candidate: ReturnType<Page["locator"]>,
    title: string,
    artist: string,
): Promise<DraftMatch> {
    const links = candidate.locator("a[href]");
    for (let linkIndex = 0; linkIndex < await links.count(); linkIndex += 1) {
        const href = await links.nth(linkIndex).getAttribute("href");
        if (!href) continue;
        const absolute = new URL(href, page.url()).toString();
        const draftId = draftIdFromUrl(absolute);
        if (draftId) return { found: true, draft_id: draftId, draft_url: absolute };
    }

    // SoundOn sometimes renders the draft row with JavaScript click handlers
    // and no usable href. A title+artist match is still sufficient to block a
    // duplicate. Store a stable local reference and point operators to the
    // draft list instead of creating the same release again.
    return {
        found: true,
        draft_id: syntheticDraftId(title, artist),
        draft_url: page.url(),
    };
}

export async function findDraft(
    page: Page,
    title: string,
    artist: string,
): Promise<DraftMatch | null> {
    const wantedTitle = normalize(title);
    const wantedArtist = normalize(artist);
    if (!wantedTitle || !wantedArtist) throw new Error("DRAFT_LOOKUP_METADATA_REQUIRED");

    await ensureDraftsView(page);

    // SoundOn's current pagination arrows have no accessible "Next" name.
    // Its draft search is therefore the authoritative and fastest way to
    // inspect every page before creating another release.
    const searched = await searchDraftList(page, title);

    for (let pageNumber = 0; pageNumber < 20; pageNumber += 1) {
        await page.waitForLoadState("domcontentloaded");
        let candidates = page.locator(
            'tr, [role="row"], article, li, [data-testid*="draft" i], [class*="card" i]',
        );
        if (!(await candidates.count())) candidates = page.locator("div");
        const count = Math.min(await candidates.count(), 500);

        for (let index = 0; index < count; index += 1) {
            const candidate = candidates.nth(index);
            if (!await candidate.isVisible().catch(() => false)) continue;
            const rawText = await candidate.innerText().catch(() => "");
            const lines = rawText.split(/\r?\n|\t/).map(normalize).filter(Boolean);
            const titleLinks = candidate.locator("a");
            const linkTexts = await titleLinks.allInnerTexts().catch(() => []);
            const hasExactTitle = lines.includes(wantedTitle)
                || linkTexts.some((value) => normalize(value) === wantedTitle);
            // Artist chips and responsive tables can add labels on the same
            // line (for example "Artist Erik Anis"). Title remains exact,
            // while artist may appear anywhere inside the same release row.
            if (!hasExactTitle || !lines.some((line) => isSameArtistLine(line, wantedArtist))) continue;

            return referenceForMatchedCandidate(page, candidate, wantedTitle, wantedArtist);
        }


        // Final DOM fallback: anchor on the exact title and walk upwards until
        // the enclosing release row/card also contains the requested artist.
        // This covers SoundOn layouts that do not use semantic table rows.
        const titleNodes = page.getByText(title, { exact: true }).filter({ visible: true });
        const titleCount = Math.min(await titleNodes.count(), 20);
        for (let index = 0; index < titleCount; index += 1) {
            let candidate = titleNodes.nth(index);
            for (let depth = 0; depth < 8; depth += 1) {
                const tag = await candidate.evaluate(element => element.tagName.toLowerCase()).catch(() => 'body');
                if (['body', 'html', 'table', 'tbody', 'thead'].includes(tag)) break;
                const rawText = await candidate.innerText().catch(() => "");
                const lines = rawText.split(/\r?\n|\t/).map(normalize).filter(Boolean);
                if (lines.some((line) => isSameArtistLine(line, wantedArtist))) {
                    return referenceForMatchedCandidate(page, candidate, wantedTitle, wantedArtist);
                }
                candidate = candidate.locator("..");
            }
        }

        let next = page.getByRole("button", { name: /next|berikutnya/i }).or(
            page.getByRole("link", { name: /next|berikutnya/i }),
        ).first();
        if (searched && !await next.isVisible().catch(() => false)) {
            next = page.locator('.semi-page-next,.semi-pagination-next').filter({ visible: true }).first();
        }
        if (!await next.isVisible().catch(() => false) || await next.isDisabled().catch(() => true)
            || await next.getAttribute('aria-disabled') === 'true'
            || /(?:^|\s)\S*disabled\S*(?:\s|$)/i.test(await next.getAttribute('class') ?? '')) {
            if (!searched) throw new Error('DRAFT_LOOKUP_UNCONFIRMED:Kontrol pencarian draft tidak tersedia');
            return null;
        }
        if (pageNumber === 19) throw new Error('DRAFT_LOOKUP_UNCONFIRMED:Batas halaman pencarian draft tercapai');
        await Promise.all([
            page.waitForLoadState("domcontentloaded").catch(() => undefined),
            next.click(),
        ]);
        if (searched) await waitForDraftResults(page);
    }

    return null;
}

export async function findDrafts(
    page: Page,
    lookups: DraftLookup[],
): Promise<Record<string, DraftMatch>> {
    const matches: Record<string, DraftMatch> = {};

    // Query SoundOn's own draft search for every title. This is intentionally
    // sequential to keep browser/RAM usage bounded and avoids inaccessible
    // icon-only pagination controls.
    for (const lookup of lookups) {
        if (!normalize(lookup.title) || !normalize(lookup.artist)) continue;
        const match = await findDraft(page, lookup.title, lookup.artist);
        if (match) matches[lookup.key] = match;
    }
    return matches;
}
