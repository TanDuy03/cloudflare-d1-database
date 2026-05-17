/**
 * Test Worker — mirrors the production Worker's routing and auth patterns.
 *
 * Uses switch-based routing and timing-safe auth, matching Worker/src/index.ts.
 * Binding name is DB1 (per wrangler.jsonc) instead of production's DB.
 */

export type Env = {
	DB1: D1Database;
	WORKER_SECRET: string;
	HMAC_REQUIRED?: string;
	HMAC_WINDOW_SECONDS?: string;
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

async function computeHmac(message: string, secret: string): Promise<string> {
	const enc = new TextEncoder();
	const key = await crypto.subtle.importKey(
		"raw",
		enc.encode(secret),
		{ name: "HMAC", hash: "SHA-256" },
		false,
		["sign"],
	);
	const sig = await crypto.subtle.sign("HMAC", key, enc.encode(message));
	return Array.from(new Uint8Array(sig))
		.map((b) => b.toString(16).padStart(2, "0"))
		.join("");
}

async function authenticate(request: Request, env: Env): Promise<Response | null> {
	const header = request.headers.get("Authorization") ?? "";
	const token = header.startsWith("Bearer ") ? header.slice(7) : "";

	if (!env.WORKER_SECRET || !token || !timingSafeEqual(token, env.WORKER_SECRET)) {
		return errorResponse(401, "Unauthorized", 401);
	}

	const signature = request.headers.get("X-D1-Signature");
	const timestamp = request.headers.get("X-D1-Timestamp");
	const nonce = request.headers.get("X-D1-Nonce");

	if (!signature && !timestamp) {
		if (env.HMAC_REQUIRED === "true") {
			return errorResponse(401, "HMAC signature required", 401);
		}
		return null;
	}

	// All three headers required together
	if (!signature || !timestamp || !nonce) {
		return errorResponse(401, "Incomplete HMAC headers (require X-D1-Signature, X-D1-Timestamp, X-D1-Nonce)", 401);
	}

	const now = Math.floor(Date.now() / 1000);
	const ts = parseInt(timestamp, 10);
	const window = parseInt(env.HMAC_WINDOW_SECONDS ?? "300", 10);
	if (isNaN(ts) || Math.abs(now - ts) > window) {
		return errorResponse(401, "HMAC timestamp expired", 401);
	}

	const body = await request.clone().text();
	const expected = await computeHmac(`${timestamp}.${nonce}.${body}`, env.WORKER_SECRET);
	if (!timingSafeEqual(signature, expected)) {
		return errorResponse(401, "Invalid HMAC signature", 401);
	}

	return null;
}

// ─── Route Handlers ───────────────────────────────────────────────────

interface QueryBody { sql: string; bindings?: unknown[] }
interface BatchBody { statements: QueryBody[] }
interface ExecBody { sql: string }

const MAX_BODY_BYTES = 10 * 1024 * 1024;

async function handleQuery(request: Request, env: Env): Promise<Response> {
	let body: QueryBody;
	try {
		body = (await request.json()) as QueryBody;
	} catch {
		return errorResponse(400, "Invalid JSON in request body", 400);
	}

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
	let body: BatchBody;
	try {
		body = (await request.json()) as BatchBody;
	} catch {
		return errorResponse(400, "Invalid JSON in request body", 400);
	}

	if (!Array.isArray(body.statements) || body.statements.length === 0) {
		return errorResponse(400, 'Missing or invalid "statements" field', 400);
	}

	// D1 batch limit — match production Worker behavior
	const D1_BATCH_LIMIT = 100;
	if (body.statements.length > D1_BATCH_LIMIT) {
		return errorResponse(
			400,
			`Batch exceeds D1 limit of ${D1_BATCH_LIMIT} statements (received ${body.statements.length})`,
			400,
		);
	}

	// Validate each statement has proper shape before preparing
	for (let i = 0; i < body.statements.length; i++) {
		const s = body.statements[i];
		if (s === null || typeof s !== "object") {
			return errorResponse(
				400,
				`Statement [${i}]: must be an object with "sql" field`,
				400,
			);
		}
		if (typeof s.sql !== "string" || s.sql.length === 0) {
			return errorResponse(
				400,
				`Statement [${i}]: missing or invalid "sql" field`,
				400,
			);
		}
		if (s.bindings !== undefined && !Array.isArray(s.bindings)) {
			return errorResponse(
				400,
				`Statement [${i}]: "bindings" must be an array`,
				400,
			);
		}
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
	let body: ExecBody;
	try {
		body = (await request.json()) as ExecBody;
	} catch {
		return errorResponse(400, "Invalid JSON in request body", 400);
	}

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

		// Guard against oversized request bodies BEFORE auth reads the body
		const contentLength = parseInt(request.headers.get("Content-Length") ?? "0", 10);
		if (contentLength > MAX_BODY_BYTES) {
			return errorResponse(413, `Request body too large (max ${MAX_BODY_BYTES} bytes)`, 413);
		}

		const authError = await authenticate(request, env);
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
