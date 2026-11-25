/** Retry navigation only; never repeat an upload or form submission. */
export async function navigateToReadPage(page, url) {
    for (let attempt = 0; attempt < 2; attempt++) {
        try {
            await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 60_000 });
            return;
        }
        catch (error) {
            const transient = error instanceof Error && (error.name === 'TimeoutError'
                || /net::ERR_(?:CONNECTION_RESET|CONNECTION_CLOSED|TIMED_OUT)\b/.test(error.message));
            if (attempt === 1 || !transient)
                throw error;
        }
    }
}
