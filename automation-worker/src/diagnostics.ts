import { appendFileSync, existsSync, mkdirSync, renameSync, statSync, unlinkSync } from 'node:fs';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import type { FastifyInstance } from 'fastify';

const defaultDirectory = resolve(fileURLToPath(new URL('../..', import.meta.url)), 'data error');

export function redactError(value: string): string {
    return value
        .replace(/(https?:\/\/[^\s?]+)\?[^\s]+/gi, '$1?[REDACTED]')
        .replace(/\bBearer\s+[A-Za-z0-9._~+\/-]+/gi, 'Bearer [REDACTED]')
        .replace(/(["']?(?:password|passwd|secret|[\w-]*token|cookie|authorization|session|app_key|hmac[\w-]*)["']?\s*[:=]\s*)("[^"\r\n]*"|'[^'\r\n]*'|[^\s,;]+)/gi, '$1[REDACTED]')
        .slice(0, 24000);
}

export function writeBrowserError(message: string, context: Record<string, unknown> = {}, directory = process.env.ERROR_DATA_DIRECTORY || defaultDirectory): void {
    try {
        mkdirSync(directory, { recursive: true });
        const file = resolve(directory, 'error browser.log');
        if (existsSync(file) && statSync(file).size >= 5 * 1024 * 1024) {
            for (let index = 3; index >= 1; index--) {
                const source = index === 1 ? file : `${file}.${index - 1}`;
                const destination = `${file}.${index}`;
                if (existsSync(destination)) unlinkSync(destination);
                if (existsSync(source)) renameSync(source, destination);
            }
        }
        // Only allow diagnostic identifiers; never persist request bodies or headers.
        const safe: Record<string, unknown> = {};
        for (const key of ['route', 'request_id', 'status', 'job_id', 'stack']) {
            if (context[key] !== undefined) safe[key] = redactError(String(context[key]));
        }
        appendFileSync(file, JSON.stringify({ time: new Date().toISOString(), source: 'browser-worker', message: redactError(message), context: safe }) + '\n', 'utf8');
    } catch { /* Logging must not interfere with browser operations. */ }
}

export function registerErrorDiagnostics(app: FastifyInstance, directory?: string): void {
    const recorded = new WeakSet<object>();
    app.addHook('onError', async (request, _reply, error) => {
        recorded.add(request);
        writeBrowserError(error.message, { route: request.routeOptions.url, request_id: request.id, stack: error.stack }, directory);
    });
    app.addHook('onSend', async (request, reply, payload) => {
        if (!recorded.has(request) && reply.statusCode >= 400 && typeof payload === 'string') {
            let message = `HTTP ${reply.statusCode}`;
            try { const result = JSON.parse(payload); message = String(result.error ?? result.message ?? message); } catch { /* Do not log arbitrary response bodies. */ }
            writeBrowserError(message, { route: request.routeOptions.url, request_id: request.id, status: reply.statusCode }, directory);
        }
        return payload;
    });
}
