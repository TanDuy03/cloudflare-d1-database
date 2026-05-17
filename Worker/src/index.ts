/**
 * D1 Worker — Proxy for ntanduy/cloudflare-d1-database Laravel package.
 *
 * This Worker exposes D1 database operations to the Laravel package's Worker driver.
 * All mutating endpoints require Bearer token authentication via WORKER_SECRET.
 * Optional HMAC request signing adds body-tamper protection and per-isolate
 * replay detection (see CF_D1_HMAC config).
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

// Optional HMAC env vars (set via `wrangler secret put` or wrangler.jsonc vars)
declare global {
	interface Env {
		HMAC_REQUIRED?: string;
		HMAC_WINDOW_SECONDS?: string;
	}
}

interface QueryBody {
	sql: string;
	bindings?: unknown[];
	session?: string;
}

interface BatchBody {
	statements: QueryBody[];
	session?: string;
}

interface ExecBody {
	sql: string;
}

// ─── Replay Protection ───────────────────────────────────────────────

/**
 * Per-isolate nonce tracker for HMAC replay detection.
 *
 * Stores seen nonces with their timestamps. When a signed request arrives,
 * we check if the same nonce was already used — if so, the request is
 * rejected as a replay. Using a nonce (instead of the full signature) means
 * two identical requests within the same second are allowed as long as they
 * have different nonces.
 *
 * Limitations:
 *   - Resets on Worker isolate cold start (nonces are not persisted)
 *   - Each Cloudflare colo has separate isolates, so a replay to a
 *     different colo may succeed
 *   - For stricter guarantees, use D1 or Durable Objects for nonce storage
 *
 * Old entries are pruned on each authenticated request to prevent unbounded
 * memory growth.
 */
const usedNonces = new Map<string, number>();

// ─── Helpers ──────────────────────────────────────────────────────────

function json(data: unknown, status = 200): Response {
	return new Response(JSON.stringify(data), {
		status,
		headers: { "Content-Type": "application/json" },
	});
}

/**
 * Build a JSON error response matching the Cloudflare D1 REST API error shape.
 * @param code  - Application-level error code (e.g. 401, 7500)
 * @param message - Human-readable error description
 * @param status  - HTTP status code (default 200 for backwards compat with older clients)
 */
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
 * Constant-time string comparison to prevent timing attacks.
 */
function timingSafeEqual(a: string, b: string): boolean {
	const encoder = new TextEncoder();
	const aBuf = encoder.encode(a);
	const bBuf = encoder.encode(b);
	if (aBuf.byteLength !== bBuf.byteLength) return false;
	return crypto.subtle.timingSafeEqual(aBuf, bBuf);
}

/**
 * Compute HMAC-SHA256 hex digest using Web Crypto API.
 */
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

/**
 * Authenticate the request.
 *
 * 1. Verify Bearer token (always required).
 * 2. If X-D1-Signature header is present, verify HMAC-SHA256(timestamp.nonce.body, secret).
 *    If absent, fall back to Bearer-only (backward compatible).
 * 3. Set HMAC_REQUIRED=true in Worker env to reject requests without HMAC.
 */
async function authenticate(request: Request, env: Env): Promise<Response | null> {
	const header = request.headers.get("Authorization") ?? "";
	const token = header.startsWith("Bearer ") ? header.slice(7) : "";

	if (!env.WORKER_SECRET || !token || !timingSafeEqual(token, env.WORKER_SECRET)) {
		return errorResponse(401, "Unauthorized", 401);
	}

	// HMAC verification
	const signature = request.headers.get("X-D1-Signature");
	const timestamp = request.headers.get("X-D1-Timestamp");
	const nonce = request.headers.get("X-D1-Nonce");

	if (!signature && !timestamp) {
		// No HMAC headers — reject if required, otherwise accept
		if (env.HMAC_REQUIRED === "true") {
			return errorResponse(401, "HMAC signature required", 401);
		}
		return null;
	}

	// Partial HMAC headers = invalid (all three are required together)
	if (!signature || !timestamp || !nonce) {
		return errorResponse(401, "Incomplete HMAC headers (require X-D1-Signature, X-D1-Timestamp, X-D1-Nonce)", 401);
	}

	// Validate timestamp window (default 300s = 5 minutes)
	const now = Math.floor(Date.now() / 1000);
	const ts = parseInt(timestamp, 10);
	const window = parseInt(env.HMAC_WINDOW_SECONDS ?? "300", 10);
	if (isNaN(ts) || Math.abs(now - ts) > window) {
		return errorResponse(401, "HMAC timestamp expired", 401);
	}

	// Verify signature against timestamp.nonce.body
	const body = await request.clone().text();
	const expected = await computeHmac(`${timestamp}.${nonce}.${body}`, env.WORKER_SECRET);
	if (!timingSafeEqual(signature, expected)) {
		return errorResponse(401, "Invalid HMAC signature", 401);
	}

	// ─── Replay detection ────────────────────────────────────────────
	// Reject if this nonce was already seen (within window).
	// Using nonce instead of signature means two identical requests with
	// different nonces are allowed (legitimate duplicate requests).
	if (usedNonces.has(nonce)) {
		return errorResponse(401, "HMAC nonce already used (replay detected)", 401);
	}

	// Store nonce for replay detection
	usedNonces.set(nonce, ts);

	// Prune expired nonces to prevent unbounded memory growth.
	for (const [n, nTs] of usedNonces) {
		if (Math.abs(now - nTs) > window) {
			usedNonces.delete(n);
		}
	}

	return null;
}

// ─── Route Handlers ───────────────────────────────────────────────────

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
	if (body.bindings !== undefined && !Array.isArray(body.bindings)) {
		return errorResponse(400, '"bindings" must be an array', 400);
	}

	try {
		// Use D1 Sessions API when session param is provided
		const db = body.session
			? env.DB.withSession(body.session)
			: env.DB;

		const result = await db
			.prepare(body.sql)
			.bind(...(body.bindings ?? []))
			.all();

		const bookmark =
			body.session && "getBookmark" in db
				? (db as D1DatabaseSession).getBookmark()
				: undefined;

		return json({
			success: result.success,
			errors: [],
			messages: [],
			result: [result],
			...(bookmark !== undefined && { bookmark }),
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message, 500);
	}
}

/** D1 batch limit — max statements per batch call */
const D1_BATCH_LIMIT = 100;

/** Maximum request body size (10 MB). Prevents oversized payloads from consuming Worker resources. */
const MAX_BODY_BYTES = 10 * 1024 * 1024;

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
		// Use D1 Sessions API when session param is provided
		const db = body.session
			? env.DB.withSession(body.session)
			: env.DB;

		const stmts = body.statements.map((s) =>
			db.prepare(s.sql).bind(...(s.bindings ?? [])),
		);
		const results = await db.batch(stmts);

		const bookmark =
			body.session && "getBookmark" in db
				? (db as D1DatabaseSession).getBookmark()
				: undefined;

		return json({
			success: true,
			errors: [],
			messages: [],
			result: results,
			...(bookmark !== undefined && { bookmark }),
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message, 500);
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
		const result = await env.DB.exec(body.sql);

		return json({
			success: true,
			errors: [],
			messages: [],
			result,
		});
	} catch (e: unknown) {
		const message = e instanceof Error ? e.message : String(e);
		return errorResponse(7500, message, 500);
	}
}

async function handleRaw(request: Request, env: Env): Promise<Response> {
	let body: QueryBody;
	try {
		body = (await request.json()) as QueryBody;
	} catch {
		return errorResponse(400, "Invalid JSON in request body", 400);
	}

	if (typeof body.sql !== "string" || body.sql.length === 0) {
		return errorResponse(400, 'Missing or invalid "sql" field', 400);
	}
	if (body.bindings !== undefined && !Array.isArray(body.bindings)) {
		return errorResponse(400, '"bindings" must be an array', 400);
	}

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
		return errorResponse(7500, message, 500);
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

		// Guard against oversized request bodies BEFORE auth reads the body.
		// This prevents large payloads from consuming HMAC hash work.
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
			case "/raw":
				return handleRaw(request, env);
			default:
				return json({ error: "Not found" }, 404);
		}
	},
} satisfies ExportedHandler<Env>;
