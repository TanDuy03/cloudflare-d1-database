import { SELF, env } from "cloudflare:test";
import { describe, it, expect, beforeAll } from "vitest";

// ─── Helpers ──────────────────────────────────────────────────────────

const SECRET = env.WORKER_SECRET;

async function fetchWorker(
	path: string,
	body?: Record<string, unknown>,
	options?: { auth?: boolean },
): Promise<{ status: number; data: Record<string, unknown> }> {
	const headers: Record<string, string> = {
		"Content-Type": "application/json",
	};

	if (options?.auth !== false) {
		headers["Authorization"] = `Bearer ${SECRET}`;
	}

	const response = await SELF.fetch(`https://worker${path}`, {
		method: body ? "POST" : "GET",
		headers,
		body: body ? JSON.stringify(body) : undefined,
	});

	const data = (await response.json()) as Record<string, unknown>;
	return { status: response.status, data };
}

// ─── Tests ────────────────────────────────────────────────────────────

describe("D1 Worker", () => {
	// Seed a test table before all tests
	beforeAll(async () => {
		await env.DB.exec(
			`CREATE TABLE IF NOT EXISTS test_users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)`,
		);
		await env.DB.exec(
			`INSERT OR IGNORE INTO test_users (id, name, email) VALUES (1, 'Alice', 'alice@example.com'), (2, 'Bob', 'bob@example.com')`,
		);
	});

	// ─── Health ───────────────────────────────────────────────────────

	describe("GET /health", () => {
		it("returns success without auth", async () => {
			const { status, data } = await fetchWorker("/health", undefined, {
				auth: false,
			});
			expect(status).toBe(200);
			expect(data.success).toBe(true);
		});
	});

	// ─── Authentication ───────────────────────────────────────────────

	describe("Authentication", () => {
		it("rejects request with no Authorization header", async () => {
			const { status, data } = await fetchWorker(
				"/query",
				{ sql: "SELECT 1" },
				{ auth: false },
			);
			expect(status).toBe(401);
			expect(data.success).toBe(false);
		});

		it("rejects request with wrong secret", async () => {
			const response = await SELF.fetch("https://worker/query", {
				method: "POST",
				headers: {
					"Content-Type": "application/json",
					Authorization: "Bearer wrong-secret",
				},
				body: JSON.stringify({ sql: "SELECT 1" }),
			});
			expect(response.status).toBe(401);
		});

		it("accepts request with correct secret", async () => {
			const { status, data } = await fetchWorker("/query", {
				sql: "SELECT 1",
			});
			expect(status).toBe(200);
			expect(data.success).not.toBe(false);
		});
	});

	// ─── POST /query ──────────────────────────────────────────────────

	describe("POST /query", () => {
		it("executes SELECT and returns results", async () => {
			const { status, data } = await fetchWorker("/query", {
				sql: "SELECT * FROM test_users ORDER BY id",
			});
			expect(status).toBe(200);

			const result = data.result as Array<{
				results: Array<Record<string, unknown>>;
			}>;
			expect(result[0].results.length).toBe(2);
			expect(result[0].results[0]).toMatchObject({
				name: "Alice",
				email: "alice@example.com",
			});
		});

		it("supports parameter bindings", async () => {
			const { status, data } = await fetchWorker("/query", {
				sql: "SELECT * FROM test_users WHERE id = ?",
				bindings: [2],
			});
			expect(status).toBe(200);

			const result = data.result as Array<{
				results: Array<Record<string, unknown>>;
			}>;
			expect(result[0].results.length).toBe(1);
			expect(result[0].results[0]).toMatchObject({ name: "Bob" });
		});

		it("returns error for invalid SQL", async () => {
			const { status, data } = await fetchWorker("/query", {
				sql: "SELECT * FROM nonexistent_table_xyz",
			});
			expect(status).toBe(500);
			expect(data.success).toBe(false);
		});

		it("response shape matches D1 REST API format", async () => {
			const { data } = await fetchWorker("/query", {
				sql: "SELECT 1 AS val",
			});

			// Must have the same top-level shape as Cloudflare D1 REST API
			expect(data).toHaveProperty("success");
			expect(data).toHaveProperty("errors");
			expect(data).toHaveProperty("result");
			expect(Array.isArray(data.result)).toBe(true);
		});
	});

	// ─── POST /batch ──────────────────────────────────────────────────

	describe("POST /batch", () => {
		it("executes multiple statements atomically", async () => {
			const { status, data } = await fetchWorker("/batch", {
				statements: [
					{
						sql: "INSERT INTO test_users (name, email) VALUES (?, ?)",
						bindings: ["Charlie", "charlie@example.com"],
					},
					{
						sql: "SELECT * FROM test_users WHERE name = ?",
						bindings: ["Charlie"],
					},
				],
			});
			expect(status).toBe(200);
			expect(data.success).toBe(true);

			const result = data.result as Array<{
				results: Array<Record<string, unknown>>;
			}>;
			expect(result.length).toBe(2);
		});

		it("returns error for invalid batch statement", async () => {
			const { status, data } = await fetchWorker("/batch", {
				statements: [{ sql: "INVALID SQL STATEMENT" }],
			});
			expect(status).toBe(500);
			expect(data.success).toBe(false);
		});
	});

	// ─── POST /exec ───────────────────────────────────────────────────

	describe("POST /exec", () => {
		it("executes DDL statements", async () => {
			const { status, data } = await fetchWorker("/exec", {
				sql: "CREATE TABLE IF NOT EXISTS exec_test (id INTEGER PRIMARY KEY, val TEXT)",
			});
			expect(status).toBe(200);
			expect(data.success).toBe(true);
		});
	});

	// ─── POST /raw ────────────────────────────────────────────────────

	describe("POST /raw", () => {
		it("returns array-of-arrays format", async () => {
			const { status, data } = await fetchWorker("/raw", {
				sql: "SELECT id, name FROM test_users WHERE id = ?",
				bindings: [1],
			});
			expect(status).toBe(200);
			expect(data.success).toBe(true);

			const result = data.result as Array<{
				results: unknown[][];
			}>;
			// raw() returns arrays, not objects
			expect(Array.isArray(result[0].results[0])).toBe(true);
		});
	});

	// ─── Input Validation ─────────────────────────────────────────────

	describe("Input validation", () => {
		it("returns 400 when sql is missing from /query", async () => {
			const { status, data } = await fetchWorker("/query", {});
			expect(status).toBe(400);
			expect(data.success).toBe(false);
		});

		it("returns 400 when bindings is not an array", async () => {
			const { status, data } = await fetchWorker("/query", {
				sql: "SELECT 1",
				bindings: "invalid",
			});
			expect(status).toBe(400);
			expect(data.success).toBe(false);
		});

		it("returns 400 when statements is missing from /batch", async () => {
			const { status, data } = await fetchWorker("/batch", {});
			expect(status).toBe(400);
			expect(data.success).toBe(false);
		});

		it("returns 400 when sql is missing from /exec", async () => {
			const { status, data } = await fetchWorker("/exec", {});
			expect(status).toBe(400);
			expect(data.success).toBe(false);
		});
	});

	// ─── 404 & 405 ────────────────────────────────────────────────────

	describe("Error routes", () => {
		it("returns 404 for unknown paths", async () => {
			const { status } = await fetchWorker("/unknown", { sql: "SELECT 1" });
			expect(status).toBe(404);
		});

		it("returns 405 for non-POST on protected routes", async () => {
			const response = await SELF.fetch("https://worker/query", {
				method: "GET",
				headers: { Authorization: `Bearer ${SECRET}` },
			});
			expect(response.status).toBe(405);
		});
	});

	// ─── HMAC Authentication ──────────────────────────────────────────

	describe("HMAC Authentication", () => {
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

		async function fetchWithHmac(
			path: string,
			body: Record<string, unknown>,
			options?: { timestamp?: string; signature?: string; skipTimestamp?: boolean; skipSignature?: boolean },
		): Promise<{ status: number; data: Record<string, unknown> }> {
			const bodyStr = JSON.stringify(body);
			const timestamp = options?.timestamp ?? String(Math.floor(Date.now() / 1000));
			const signature = options?.signature ?? await computeHmac(`${timestamp}.${bodyStr}`, SECRET);

			const headers: Record<string, string> = {
				"Content-Type": "application/json",
				"Authorization": `Bearer ${SECRET}`,
			};
			if (!options?.skipTimestamp) headers["X-D1-Timestamp"] = timestamp;
			if (!options?.skipSignature) headers["X-D1-Signature"] = signature;

			const response = await SELF.fetch(`https://worker${path}`, {
				method: "POST",
				headers,
				body: bodyStr,
			});
			const data = (await response.json()) as Record<string, unknown>;
			return { status: response.status, data };
		}

		it("accepts request with valid HMAC signature", async () => {
			const { status, data } = await fetchWithHmac("/query", {
				sql: "SELECT 1 as val",
				bindings: [],
			});
			expect(status).toBe(200);
			expect(data.success).toBe(true);
		});

		it("rejects request with invalid HMAC signature", async () => {
			const { status, data } = await fetchWithHmac("/query", {
				sql: "SELECT 1",
				bindings: [],
			}, { signature: "deadbeef" });
			expect(status).toBe(401);
			expect(data.success).toBe(false);
		});

		it("rejects request with expired timestamp", async () => {
			const expired = String(Math.floor(Date.now() / 1000) - 600);
			const { status, data } = await fetchWithHmac("/query", {
				sql: "SELECT 1",
				bindings: [],
			}, { timestamp: expired });
			expect(status).toBe(401);
			expect(data.success).toBe(false);
		});

		it("rejects request with only X-D1-Signature (missing timestamp)", async () => {
			const { status, data } = await fetchWithHmac("/query", {
				sql: "SELECT 1",
				bindings: [],
			}, { skipTimestamp: true });
			expect(status).toBe(401);
			expect(data.success).toBe(false);
		});

		it("rejects request with only X-D1-Timestamp (missing signature)", async () => {
			const { status, data } = await fetchWithHmac("/query", {
				sql: "SELECT 1",
				bindings: [],
			}, { skipSignature: true });
			expect(status).toBe(401);
			expect(data.success).toBe(false);
		});
	});
});
