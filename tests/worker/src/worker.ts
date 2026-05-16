/**
 * Test Worker — mirrors the production Worker's routing and auth patterns.
 *
 * Uses switch-based routing and timing-safe auth, matching Worker/src/index.ts.
 * Binding name is DB1 (per wrangler.jsonc) instead of production's DB.
 */

export type Env = {
	DB1: D1Database;
	WORKER_SECRET: string;
};

// ─── Helpers ──────────────────────────────────────────────────────────

function json(data: unknown, status = 200): Response {
	return new Response(JSON.stringify(data), {
		status,
		headers: { "Content-Type": "application/json" },
	});
}

function errorResponse(code: number, message: string, status = 500): Response {
	return json({ success: false, errors: [{ code, message }] }, status);
}

function timingSafeEqual(a: string, b: string): boolean {
	const enc = new TextEncoder();
	const aBuf = enc.encode(a);
	const bBuf = enc.encode(b);
	if (aBuf.byteLength !== bBuf.byteLength) return false;
	return crypto.subtle.timingSafeEqual(aBuf, bBuf);
}

function authenticate(request: Request, env: Env): Response | null {
	const header = request.headers.get("Authorization") ?? "";
	const token = header.startsWith("Bearer ") ? header.slice(7) : "";

	if (!env.WORKER_SECRET || !token || !timingSafeEqual(token, env.WORKER_SECRET)) {
		return json(
			{ success: false, errors: [{ code: 401, message: "Unauthorized" }] },
			401,
		);
	}

	return null;
}

// ─── Route Handlers ───────────────────────────────────────────────────

interface QueryBody { sql: string; bindings?: unknown[] }
interface BatchBody { statements: QueryBody[] }
interface ExecBody { sql: string }

async function handleQuery(request: Request, env: Env): Promise<Response> {
	const body = (await request.json()) as QueryBody;

	if (typeof body.sql !== "string" || body.sql.length === 0) {
		return errorResponse(400, 'Missing or invalid "sql" field', 400);
	}

	try {
		const result = await env.DB1
			.prepare(body.sql)
			.bind(...(body.bindings ?? []))
			.all();

		return json({
			success: result.success,
			errors: [],
			result: [result],
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message, 200);
	}
}

async function handleBatch(request: Request, env: Env): Promise<Response> {
	const body = (await request.json()) as BatchBody;

	if (!Array.isArray(body.statements) || body.statements.length === 0) {
		return errorResponse(400, 'Missing or invalid "statements" field', 400);
	}

	try {
		const stmts = body.statements.map((s) =>
			env.DB1.prepare(s.sql).bind(...(s.bindings ?? [])),
		);
		const results = await env.DB1.batch(stmts);

		return json({
			success: true,
			errors: [],
			result: results,
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message, 200);
	}
}

async function handleExec(request: Request, env: Env): Promise<Response> {
	const body = (await request.json()) as ExecBody;

	if (typeof body.sql !== "string" || body.sql.length === 0) {
		return errorResponse(400, 'Missing or invalid "sql" field', 400);
	}

	try {
		const result = await env.DB1.exec(body.sql);

		return json({
			success: true,
			errors: [],
			result,
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message, 200);
	}
}

// ─── Router ───────────────────────────────────────────────────────────

export default {
	async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
		const url = new URL(request.url);
		const { pathname } = url;
		const method = request.method;

		// Health check — no auth required
		if (pathname === "/health" && method === "GET") {
			return json({ success: true, message: "OK" });
		}

		// All other endpoints require POST + auth
		if (method !== "POST") {
			return json({ error: "Not found" }, 404);
		}

		const authError = authenticate(request, env);
		if (authError) return authError;

		switch (pathname) {
			case "/query":
				return handleQuery(request, env);
			case "/batch":
				return handleBatch(request, env);
			case "/exec":
				return handleExec(request, env);
			default:
				return json({ error: "Not found" }, 404);
		}
	},
};
