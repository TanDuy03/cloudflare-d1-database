import { env, createExecutionContext, waitOnExecutionContext } from 'cloudflare:test';
import { describe, it, expect, beforeAll } from 'vitest';
import worker from '../src/worker';

const VALID_SECRET = 'test-secret-for-vitest';
const WORKER_URL = 'http://localhost';

function makeRequest(
    path: string,
    body?: object,
    options: { method?: string; secret?: string | null } = {}
): Request {
    const { method = 'POST', secret = VALID_SECRET } = options;

    const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'application/json',
    };
    if (secret !== null) {
        headers['Authorization'] = `Bearer ${secret}`;
    }

    return new Request(`${WORKER_URL}${path}`, {
        method,
        headers,
        body: body ? JSON.stringify(body) : undefined,
    });
}

async function fetchWorker(
    path: string,
    body?: object,
    options: { method?: string; secret?: string | null } = {}
): Promise<{ status: number; data: any }> {
    const request = makeRequest(path, body, options);
    const ctx = createExecutionContext();
    const response = await worker.fetch(request, env, ctx);
    await waitOnExecutionContext(ctx);

    const data = await response.json().catch(() => null);
    return { status: response.status, data };
}

// ─── Setup ────────────────────────────────────────────────────────────

describe('Worker D1 API', () => {
    beforeAll(async () => {
        // Create a test table using /exec
        const request = makeRequest('/exec', {
            sql: 'CREATE TABLE IF NOT EXISTS test_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT)',
        });
        const ctx = createExecutionContext();
        const response = await worker.fetch(request, env, ctx);
        await waitOnExecutionContext(ctx);
        expect(response.status).toBe(200);
    });

    // ─── /health ──────────────────────────────────────────────────────

    describe('GET /health', () => {
        it('returns success without auth', async () => {
            const { status, data } = await fetchWorker('/health', undefined, {
                method: 'GET',
                secret: null,
            });
            expect(status).toBe(200);
            expect(data.success).toBe(true);
            expect(data.message).toBe('OK');
        });
    });

    // ─── Auth ─────────────────────────────────────────────────────────

    describe('Authentication', () => {
        it('rejects request with no Authorization header', async () => {
            const { status, data } = await fetchWorker(
                '/query',
                { sql: 'SELECT 1', bindings: [] },
                { secret: null }
            );
            expect(status).toBe(401);
            expect(data.success).toBe(false);
            expect(data.errors[0].code).toBe(401);
            expect(data.errors[0].message).toBe('Unauthorized');
        });

        it('rejects request with wrong secret', async () => {
            const { status, data } = await fetchWorker(
                '/query',
                { sql: 'SELECT 1', bindings: [] },
                { secret: 'wrong-secret' }
            );
            expect(status).toBe(401);
            expect(data.success).toBe(false);
        });

        it('accepts request with correct secret', async () => {
            const { status, data } = await fetchWorker('/query', {
                sql: 'SELECT 1 as val',
                bindings: [],
            });
            expect(status).toBe(200);
            expect(data.success).toBe(true);
        });
    });

    // ─── POST /query ──────────────────────────────────────────────────

    describe('POST /query', () => {
        it('executes SELECT and returns results', async () => {
            const { status, data } = await fetchWorker('/query', {
                sql: 'SELECT 1 as num, "hello" as msg',
                bindings: [],
            });
            expect(status).toBe(200);
            expect(data.success).toBe(true);
            expect(data.errors).toEqual([]);
            expect(data.result).toBeInstanceOf(Array);
            expect(data.result[0].results[0]).toEqual({ num: 1, msg: 'hello' });
        });

        it('supports parameter bindings', async () => {
            // Insert first
            await fetchWorker('/query', {
                sql: 'INSERT INTO test_users (name, email) VALUES (?, ?)',
                bindings: ['Alice', 'alice@example.com'],
            });

            // Query with binding
            const { data } = await fetchWorker('/query', {
                sql: 'SELECT name FROM test_users WHERE email = ?',
                bindings: ['alice@example.com'],
            });
            expect(data.success).toBe(true);
            expect(data.result[0].results[0].name).toBe('Alice');
        });

        it('returns error for invalid SQL', async () => {
            const { status, data } = await fetchWorker('/query', {
                sql: 'INVALID SQL STATEMENT',
                bindings: [],
            });
            expect(status).toBe(200);
            expect(data.success).toBe(false);
            expect(data.errors).toHaveLength(1);
            expect(data.errors[0].code).toBe(7500);
        });

        it('response shape matches D1 REST API format', async () => {
            const { data } = await fetchWorker('/query', {
                sql: 'SELECT 1 as x',
                bindings: [],
            });

            // Must have these top-level keys
            expect(data).toHaveProperty('success');
            expect(data).toHaveProperty('errors');
            expect(data).toHaveProperty('result');

            // result[0] should have 'results' and 'meta'
            const firstResult = data.result[0];
            expect(firstResult).toHaveProperty('results');
            expect(firstResult).toHaveProperty('meta');
        });
    });

    // ─── POST /batch ──────────────────────────────────────────────────

    describe('POST /batch', () => {
        it('executes multiple statements atomically', async () => {
            const { status, data } = await fetchWorker('/batch', {
                statements: [
                    {
                        sql: 'INSERT INTO test_users (name, email) VALUES (?, ?)',
                        bindings: ['Bob', 'bob@example.com'],
                    },
                    {
                        sql: 'INSERT INTO test_users (name, email) VALUES (?, ?)',
                        bindings: ['Charlie', 'charlie@example.com'],
                    },
                ],
            });

            expect(status).toBe(200);
            expect(data.success).toBe(true);
            expect(data.result).toBeInstanceOf(Array);
            expect(data.result).toHaveLength(2);
        });

        it('returns error for invalid batch statement', async () => {
            const { data } = await fetchWorker('/batch', {
                statements: [
                    { sql: 'INVALID SQL', bindings: [] },
                ],
            });
            expect(data.success).toBe(false);
            expect(data.errors).toHaveLength(1);
        });
    });

    // ─── POST /exec ───────────────────────────────────────────────────

    describe('POST /exec', () => {
        it('executes DDL statements', async () => {
            const { status, data } = await fetchWorker('/exec', {
                sql: 'CREATE TABLE IF NOT EXISTS exec_test (id INTEGER PRIMARY KEY, val TEXT)',
            });
            expect(status).toBe(200);
            expect(data.success).toBe(true);
        });

        it('executes additional DDL statements', async () => {
            const { status, data } = await fetchWorker('/exec', {
                sql: `CREATE TABLE IF NOT EXISTS exec_test_two (id INTEGER PRIMARY KEY, value TEXT NOT NULL)`,
            });
            expect(status).toBe(200);
            expect(data.success).toBe(true);
        });
    });

    // ─── 404 for unknown routes ───────────────────────────────────────

    describe('Unknown routes', () => {
        it('returns 404 for unknown paths', async () => {
            const request = makeRequest('/nonexistent', undefined, { method: 'GET', secret: null });
            const ctx = createExecutionContext();
            const response = await worker.fetch(request, env, ctx);
            await waitOnExecutionContext(ctx);

            expect(response.status).toBe(404);
        });
    });
});
