/**
 * D1 Worker — Proxy for ntanduy/cloudflare-d1-database Laravel package.
 *
 * This Worker exposes D1 database operations to the Laravel package's Worker driver.
 * All mutating endpoints require Bearer token authentication via WORKER_SECRET.
 *
 * Endpoints:
 *   GET  /health  — Health check (no auth)
 *   POST /query   — Execute a single SQL query with bindings
 *   POST /batch   — Execute multiple statements atomically
 *   POST /exec    — Execute raw DDL/migration SQL
 *   POST /raw     — Execute a query and return raw array-of-arrays
 *
 * Setup:
 *   1. Add D1 binding in wrangler.jsonc
 *   2. Set WORKER_SECRET via `npx wrangler secret put WORKER_SECRET`
 *   3. Deploy with `npm run deploy`
 *
 * @see https://github.com/TanDuy03/cloudflare-d1-database
 */

// ─── Types ────────────────────────────────────────────────────────────

interface QueryBody {
	sql: string;
	bindings?: unknown[];
}

interface BatchBody {
	statements: QueryBody[];
}

interface ExecBody {
	sql: string;
}

// ─── Helpers ──────────────────────────────────────────────────────────

function json(data: unknown, status = 200): Response {
	return new Response(JSON.stringify(data), {
		status,
		headers: { "Content-Type": "application/json" },
	});
}

function errorResponse(
	code: number,
	message: string,
	status = 200,
): Response {
	return json(
		{
			success: false,
			errors: [{ code, message }],
			result: [],
		},
		status,
	);
}

/**
 * Verify Bearer token matches WORKER_SECRET.
 * Returns an error Response if invalid, or null if OK.
 */
function authenticate(request: Request, env: Env): Response | null {
	const header = request.headers.get("Authorization") ?? "";
	const token = header.startsWith("Bearer ") ? header.slice(7) : "";

	if (!env.WORKER_SECRET || token !== env.WORKER_SECRET) {
		return json(
			{
				success: false,
				errors: [{ code: 401, message: "Unauthorized" }],
			},
			401,
		);
	}

	return null;
}

// ─── Route Handlers ───────────────────────────────────────────────────

async function handleQuery(request: Request, env: Env): Promise<Response> {
	const body = (await request.json()) as QueryBody;

	try {
		const result = await env.DB
			.prepare(body.sql)
			.bind(...(body.bindings ?? []))
			.all();

		return json({
			success: result.success,
			errors: [],
			messages: [],
			result: [result],
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message);
	}
}

async function handleBatch(request: Request, env: Env): Promise<Response> {
	const body = (await request.json()) as BatchBody;

	try {
		const stmts = body.statements.map((s) =>
			env.DB.prepare(s.sql).bind(...(s.bindings ?? [])),
		);
		const results = await env.DB.batch(stmts);

		return json({
			success: true,
			errors: [],
			messages: [],
			result: results,
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message);
	}
}

async function handleExec(request: Request, env: Env): Promise<Response> {
	const body = (await request.json()) as ExecBody;

	try {
		const result = await env.DB.exec(body.sql);

		return json({
			success: true,
			errors: [],
			messages: [],
			result,
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message);
	}
}

async function handleRaw(request: Request, env: Env): Promise<Response> {
	const body = (await request.json()) as QueryBody;

	try {
		const result = await env.DB
			.prepare(body.sql)
			.bind(...(body.bindings ?? []))
			.raw();

		return json({
			success: true,
			errors: [],
			messages: [],
			result: [{ results: result }],
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message);
	}
}

// ─── Router ───────────────────────────────────────────────────────────

export default {
	async fetch(request, env, ctx): Promise<Response> {
		const url = new URL(request.url);
		const { pathname } = url;
		const method = request.method;

		// Health check — no auth required
		if (pathname === "/health" && method === "GET") {
			return json({ success: true, message: "OK" });
		}

		// All other endpoints require POST + auth
		if (method !== "POST") {
			return json({ error: "Method not allowed" }, 405);
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
			case "/raw":
				return handleRaw(request, env);
			default:
				return json({ error: "Not found" }, 404);
		}
	},
} satisfies ExportedHandler<Env>;
