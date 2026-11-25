import Fastify from 'fastify';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, expect, it } from 'vitest';
import { registerErrorDiagnostics } from './diagnostics.js';

const directories: string[] = [];
afterEach(() => directories.splice(0).forEach(directory => rmSync(directory, { recursive: true, force: true })));

it('records thrown and returned HTTP failures without persisting session bodies or query secrets', async () => {
    const directory = mkdtempSync(join(tmpdir(), 'soundmatic-errors-'));
    directories.push(directory);
    const app = Fastify();
    registerErrorDiagnostics(app, directory);
    app.post('/upload', async () => { throw new Error('Upload failed password="private password" Bearer secret-header'); });
    app.post('/status', async (_request, reply) => reply.code(401).send({ success: false, error: 'AUTH_SESSION_EXPIRED' }));
    app.get('/ok', async () => ({ success: true }));
    try {
        await app.inject({ method: 'POST', url: '/upload?token=query-secret', payload: { password: 'body-secret', session: { cookies: ['session-secret'] } } });
        await app.inject({ method: 'POST', url: '/status' });
        await app.inject({ method: 'GET', url: '/ok' });
        const contents = readFileSync(join(directory, 'error browser.log'), 'utf8');
        const records = contents.trim().split('\n').map(line => JSON.parse(line));
        expect(records).toHaveLength(2);
        expect(records[0].context.route).toBe('/upload');
        expect(records[0].context.stack).toContain('Upload failed');
        expect(records[1].message).toBe('AUTH_SESSION_EXPIRED');
        for (const secret of ['private password', 'secret-header', 'query-secret', 'body-secret', 'session-secret']) expect(contents).not.toContain(secret);
    } finally { await app.close(); }
});
