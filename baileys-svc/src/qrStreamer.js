'use strict';

const QRCode = require('qrcode');

// Map: agentId → Set<reply (SSE response object)>
const subscribers = new Map();

// Cache last QR per agent so late subscribers get it immediately
const lastQrCache = new Map();

function subscribe(agentId, reply) {
    if (!subscribers.has(agentId)) {
        subscribers.set(agentId, new Set());
    }
    subscribers.get(agentId).add(reply);

    // Send cached QR immediately so late subscribers don't miss it
    const cached = lastQrCache.get(agentId);
    if (cached) {
        try {
            reply.raw.write(`data: ${JSON.stringify({ type: 'qr', qr: cached })}\n\n`);
        } catch {
            subscribers.get(agentId)?.delete(reply);
        }
    }
}

function unsubscribe(agentId, reply) {
    subscribers.get(agentId)?.delete(reply);
    if (subscribers.get(agentId)?.size === 0) {
        subscribers.delete(agentId);
    }
}

function clearCache(agentId) {
    lastQrCache.delete(agentId);
}

async function pushQr(agentId, qrString) {
    let base64;
    try {
        base64 = await QRCode.toDataURL(qrString);
    } catch (err) {
        console.error('[qrStreamer] QR encode error:', err.message);
        return;
    }

    lastQrCache.set(agentId, base64);

    const subs = subscribers.get(agentId);
    if (!subs || subs.size === 0) return;

    const data = JSON.stringify({ type: 'qr', qr: base64 });
    for (const reply of subs) {
        try {
            reply.raw.write(`data: ${data}\n\n`);
        } catch {
            subs.delete(reply);
        }
    }
}

function pushEvent(agentId, type, payload = {}) {
    // Clear QR cache when agent connects or session is cancelled
    if (type === 'agent_connected' || type === 'session_cancelled') {
        clearCache(agentId);
    }

    const subs = subscribers.get(agentId);
    if (!subs || subs.size === 0) return;

    const data = JSON.stringify({ type, ...payload });
    for (const reply of subs) {
        try {
            reply.raw.write(`data: ${data}\n\n`);
        } catch {
            subs.delete(reply);
        }
    }
}

module.exports = { subscribe, unsubscribe, pushQr, pushEvent, clearCache };
