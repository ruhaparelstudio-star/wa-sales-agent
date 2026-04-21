'use strict';

const crypto = require('crypto');
const { v4: uuidv4 } = require('uuid');

const DEFAULT_RETRYABLE_STATUSES = new Set([408, 425, 429, 500, 502, 503, 504]);

async function sendWebhook(event, agentId, data) {
    const webhookUrl = process.env.LARAVEL_WEBHOOK_URL;
    const timeoutMs = parseInt(process.env.WEBHOOK_TIMEOUT_MS || '30000', 10);
    const maxAttempts = parseInt(process.env.WEBHOOK_MAX_ATTEMPTS || '4', 10);
    const initialDelayMs = parseInt(process.env.WEBHOOK_RETRY_DELAY_MS || '1000', 10);

    if (!webhookUrl) {
        console.error('[webhookSender] LARAVEL_WEBHOOK_URL not set, skipping webhook');
        return;
    }

    const idempotencyKey = buildIdempotencyKey(event, agentId, data);
    const payload = JSON.stringify({ event, agent_id: agentId, data });

    for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
        try {
            const response = await fetch(webhookUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Baileys-Secret': process.env.BAILEYS_SECRET || '',
                    'X-Idempotency-Key': idempotencyKey,
                },
                body: payload,
                signal: AbortSignal.timeout(timeoutMs),
            });

            if (response.ok) {
                return;
            }

            const shouldRetry = DEFAULT_RETRYABLE_STATUSES.has(response.status) && attempt < maxAttempts;
            console.error(`[webhookSender] Webhook ${event} failed: HTTP ${response.status} (attempt ${attempt}/${maxAttempts})`);

            if (!shouldRetry) {
                return;
            }
        } catch (err) {
            const shouldRetry = attempt < maxAttempts;
            console.error(`[webhookSender] Webhook ${event} error on attempt ${attempt}/${maxAttempts}:`, err.message);

            if (!shouldRetry) {
                return;
            }
        }

        await sleep(initialDelayMs * attempt);
    }
}

function buildIdempotencyKey(event, agentId, data = {}) {
    switch (event) {
        case 'message_received':
            return stableKey(event, agentId, data.wa_message_id || '', data.from_jid || data.from || '');
        case 'message_status_update':
            return stableKey(event, agentId, data.wa_message_id || '', data.status || '');
        default:
            return uuidv4();
    }
}

function stableKey(...parts) {
    return crypto.createHash('sha256').update(parts.join('|')).digest('hex');
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

module.exports = { sendWebhook };
