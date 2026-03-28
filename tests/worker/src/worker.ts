import apiRouter from './router';

export type Env = {
	DB1: D1Database;
	WORKER_SECRET: string;
};

export default {
	async fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response> {
		return apiRouter.handle(request, env);
	},
};
