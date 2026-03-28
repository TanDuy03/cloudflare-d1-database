import { IRequest, Router } from 'itty-router';

const router = Router();

declare type Env = {
	DB1: D1Database;
	WORKER_SECRET: string;
	[key: string]: any;
};

/**
 * Auth middleware — verifies Bearer token matches WORKER_SECRET.
 */
function authMiddleware(request: IRequest, env: Env): Response | void {
	const authHeader = request.headers.get('Authorization') ?? '';
	const token = authHeader.replace('Bearer ', '');

	if (!env.WORKER_SECRET || token !== env.WORKER_SECRET) {
		return new Response(
			JSON.stringify({
				success: false,
				errors: [{ code: 401, message: 'Unauthorized' }],
			}),
			{ status: 401, headers: { 'Content-Type': 'application/json' } }
		);
	}
}

/**
 * GET /health — Basic health check.
 */
router.get('/health', (request: IRequest, env: Env) => {
	return new Response(
		JSON.stringify({ success: true, message: 'OK' }),
		{ headers: { 'Content-Type': 'application/json' } }
	);
});

/**
 * POST /query — Execute a single SQL query with bindings.
 * Body: { sql: string, bindings: any[] }
 * Response: D1-compatible JSON.
 */
router.post('/query', authMiddleware, async (request: IRequest, env: Env) => {
	const body = await request.json() as { sql: string; bindings?: any[] };
	let res: D1Result;

	try {
		res = await env.DB1
			.prepare(body.sql)
			.bind(...(body.bindings || []))
			.all();
	} catch (e: any) {
		return new Response(
			JSON.stringify({
				success: false,
				errors: [{ message: e.message, code: 7500 }],
			}),
			{ status: 200, headers: { 'Content-Type': 'application/json' } }
		);
	}

	return new Response(
		JSON.stringify({
			success: res.success,
			errors: [],
			result: [res],
		}),
		{ headers: { 'Content-Type': 'application/json' } }
	);
});

/**
 * POST /batch — Execute multiple SQL statements atomically.
 * Body: { statements: [{ sql: string, bindings?: any[] }] }
 */
router.post('/batch', authMiddleware, async (request: IRequest, env: Env) => {
	const body = await request.json() as { statements: { sql: string; bindings?: any[] }[] };

	try {
		const stmts = body.statements.map((s) =>
			env.DB1.prepare(s.sql).bind(...(s.bindings || []))
		);
		const results = await env.DB1.batch(stmts);

		return new Response(
			JSON.stringify({
				success: true,
				errors: [],
				result: results,
			}),
			{ headers: { 'Content-Type': 'application/json' } }
		);
	} catch (e: any) {
		return new Response(
			JSON.stringify({
				success: false,
				errors: [{ message: e.message, code: 7500 }],
			}),
			{ status: 200, headers: { 'Content-Type': 'application/json' } }
		);
	}
});

/**
 * POST /exec — Execute raw SQL (DDL, migrations).
 * Body: { sql: string }
 */
router.post('/exec', authMiddleware, async (request: IRequest, env: Env) => {
	const body = await request.json() as { sql: string };

	try {
		const result = await env.DB1.exec(body.sql);

		return new Response(
			JSON.stringify({
				success: true,
				errors: [],
				result: result,
			}),
			{ headers: { 'Content-Type': 'application/json' } }
		);
	} catch (e: any) {
		return new Response(
			JSON.stringify({
				success: false,
				errors: [{ message: e.message, code: 7500 }],
			}),
			{ status: 200, headers: { 'Content-Type': 'application/json' } }
		);
	}
});

/**
 * Legacy REST API proxy (kept for backward compatibility).
 */
router.post(
	'/api/client/v4/accounts/:account/d1/database/:database/query',
	async (request: IRequest, env: Env) => {
		const body = await request.json() as { sql: string; params?: any[] };
		let res: D1Result;

		try {
			res = await (env[request.params.database as string] as D1Database)
				.prepare(body.sql)
				.bind(...(body.params || []))
				.all();
		} catch (e: any) {
			return new Response(JSON.stringify({
				errors: [{ message: e.stack, code: e.message }],
			}), {
				headers: { 'Content-Type': 'application/json' },
			});
		}

		return new Response(JSON.stringify({
			success: res.success,
			result: [res],
		}), {
			headers: { 'Content-Type': 'application/json' },
		});
	},
);

// 404 for everything else
router.all('*', () => new Response('Not Found.', { status: 404 }));

export default router;
