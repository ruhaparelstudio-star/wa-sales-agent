'use strict';

require('dotenv').config();

const Fastify = require('fastify');
const { subscribe, unsubscribe } = require('./qrStreamer');
const agentManager = require('./agentManager');

const app = Fastify({ logger: { level: process.env.LOG_LEVEL || 'info' } });
const PORT = parseInt(process.env.PORT || '3001', 10);
const BAILEYS_SECRET = process.env.BAILEYS_SECRET || '';

// Auth — skip /health, validate all other routes
app.addHook('onRequest', async (request, reply) => {
    if (request.url === '/health') return;
    if (request.method === 'GET' && /^\/agents\/[^/]+\/qr-stream$/.test(request.url)) return;

    const secret = request.headers['x-baileys-secret'];
    if (!secret || secret !== BAILEYS_SECRET) {
        reply.code(401).send({ error: 'Unauthorized' });
    }
});

// GET /health
app.get('/health', async (_request, reply) => {
    return reply.code(200).send({ status: 'ok' });
});

// POST /agents/:id/start
app.post('/agents/:id/start', async (request, reply) => {
    const agentId = request.params.id;
    const result = await agentManager.startAgent(agentId);
    return reply.code(200).send({
        ...result,
        qr_stream_url: `/agents/${agentId}/qr-stream`,
    });
});

// GET /agents/:id/qr-stream — SSE
app.get('/agents/:id/qr-stream', async (request, reply) => {
    const agentId = request.params.id;

    reply.raw.setHeader('Content-Type', 'text/event-stream');
    reply.raw.setHeader('Cache-Control', 'no-cache');
    reply.raw.setHeader('Connection', 'keep-alive');
    reply.raw.setHeader('X-Accel-Buffering', 'no');
    reply.raw.flushHeaders();

    subscribe(agentId, reply);

    const heartbeat = setInterval(() => {
        try {
            reply.raw.write(': ping\n\n');
        } catch {
            clearInterval(heartbeat);
        }
    }, 5000);

    await new Promise((resolve) => {
        request.raw.on('close', () => {
            clearInterval(heartbeat);
            unsubscribe(agentId, reply);
            resolve();
        });
    });
});

// POST /agents/:id/cancel
app.post('/agents/:id/cancel', async (request, reply) => {
    await agentManager.cancelAgent(request.params.id);
    return reply.code(200).send({ status: 'cancelled' });
});

// POST /agents/:id/disconnect
app.post('/agents/:id/disconnect', async (request, reply) => {
    await agentManager.disconnectAgent(request.params.id);
    return reply.code(200).send({ status: 'disconnected' });
});

// POST /agents/:id/send
app.post('/agents/:id/send', async (request, reply) => {
    const { to, type = 'text', content, file_path, filename, idempotency_key } = request.body ?? {};
    if (!to) {
        return reply.code(422).send({ error: 'Missing required field: to' });
    }

    if (type === 'text' && !content) {
        return reply.code(422).send({ error: 'Missing required fields: to, content' });
    }

    if (type === 'document' && (!file_path || !filename)) {
        return reply.code(422).send({ error: 'Missing required fields: to, file_path, filename' });
    }

    try {
        const result = await agentManager.sendMessage(
            request.params.id,
            to,
            content,
            type,
            idempotency_key,
            { filePath: file_path, filename }
        );
        return reply.code(200).send(result);
    } catch (err) {
        return reply.code(422).send({ error: err.message });
    }
});

// GET /agents/:id/status
app.get('/agents/:id/status', async (request, reply) => {
    return reply.code(200).send(agentManager.getStatus(request.params.id));
});

app.listen({ port: PORT, host: '0.0.0.0' }, (err) => {
    if (err) {
        app.log.error(err);
        process.exit(1);
    }
    app.log.info(`Baileys sidecar running on port ${PORT}`);

    // Reconnect any agents that have saved sessions from before restart
    agentManager.reconnectSavedAgents().catch((e) =>
        app.log.error({ err: e }, 'Startup reconnect failed')
    );
});
