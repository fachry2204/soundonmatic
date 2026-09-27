import { createHmac, timingSafeEqual } from 'node:crypto';
const usedNonces = new Map();
export function verifyRequest(headers, body) { const timestamp = String(headers['x-automation-timestamp'] ?? ''); const nonce = String(headers['x-automation-nonce'] ?? ''); const signature = String(headers['x-automation-signature'] ?? ''); const key = process.env.AUTOMATION_HMAC_KEY ?? ''; if (!key || !timestamp || !nonce || !signature || Math.abs(Date.now() / 1000 - Number(timestamp)) > 60 || usedNonces.has(nonce))
    return false; const expected = createHmac('sha256', key).update(`${timestamp}.${nonce}.${body}`).digest('hex'); if (expected.length !== signature.length || !timingSafeEqual(Buffer.from(expected), Buffer.from(signature)))
    return false; usedNonces.set(nonce, Date.now()); for (const [n, t] of usedNonces)
    if (Date.now() - t > 120_000)
        usedNonces.delete(n); return true; }
