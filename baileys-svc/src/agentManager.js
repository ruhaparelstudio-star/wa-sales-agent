'use strict';

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason,
    fetchLatestBaileysVersion,
} = require('@whiskeysockets/baileys');
const { Boom } = require('@hapi/boom');
const path = require('path');
const fs = require('fs');
const { sendWebhook } = require('./webhookSender');
const { pushQr, pushEvent, clearCache } = require('./qrStreamer');

// Map: agentId → socket instance
const sockets = new Map();
const outboundSends = new Map();

const SESSIONS_DIR = process.env.SESSIONS_DIR || path.join(__dirname, '..', 'sessions');
const OUTBOUND_IDEMPOTENCY_TTL_MS = parseInt(process.env.OUTBOUND_IDEMPOTENCY_TTL_MS || '21600000', 10);

function sessionPath(agentId) {
    return path.join(SESSIONS_DIR, `auth_info_${agentId}`);
}

function normalizeRemoteJid(remoteJid) {
    if (!remoteJid || typeof remoteJid !== 'string') {
        return null;
    }

    const localPart = remoteJid.split('@')[0];
    const digits = localPart.replace(/\D/g, '');

    if (!digits) {
        return null;
    }

    return `+${digits}`;
}

function normalizeOutboundJid(to) {
    if (!to || typeof to !== 'string') {
        throw new Error('Recipient is required');
    }

    if (to.includes('@')) {
        const [localPart, domain] = to.split('@');
        const digits = localPart.replace(/\D/g, '');

        if (!digits || !domain) {
            throw new Error(`Invalid WhatsApp JID: ${to}`);
        }

        return `${digits}@${domain}`;
    }

    const digits = to.replace(/\D/g, '');
    if (!digits) {
        throw new Error(`Invalid WhatsApp phone number: ${to}`);
    }

    return `${digits}@s.whatsapp.net`;
}

async function startAgent(agentId) {
    if (sockets.has(agentId)) {
        const existing = sockets.get(agentId);
        if (existing.user) {
            return { status: 'already_connected', phone: existing.user?.id?.split(':')[0] };
        }
        // Already starting, just return
        return { status: 'starting' };
    }

    // Always drop any previously cached QR before opening a fresh session.
    // This prevents reconnect attempts from rendering an expired code.
    clearCache(agentId);

    const { version } = await fetchLatestBaileysVersion();
    const { state, saveCreds } = await useMultiFileAuthState(sessionPath(agentId));

    const sock = makeWASocket({
        version,
        auth: state,
        printQRInTerminal: false,
        logger: require('pino')({ level: 'silent' }),
        browser: ['Sales Agent WA', 'Chrome', '1.0'],
    });

    sockets.set(agentId, sock);

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', async (update) => {
        const { connection, lastDisconnect, qr } = update;

        if (qr) {
            await pushQr(agentId, qr);
        }

        if (connection === 'open') {
            const phone = sock.user?.id?.split(':')[0] ?? '';
            const phoneFormatted = '+' + phone;
            pushEvent(agentId, 'agent_connected', { phone_number: phoneFormatted });
            await sendWebhook('agent_connected', agentId, {
                phone_number: phoneFormatted,
                connected_at: new Date().toISOString(),
            });
        }

        if (connection === 'close') {
            sockets.delete(agentId);
            clearCache(agentId);
            const statusCode = (lastDisconnect?.error instanceof Boom)
                ? lastDisconnect.error.output?.statusCode
                : null;

            let reason = 'connection_lost';
            if (statusCode === DisconnectReason.loggedOut) reason = 'logout';
            if (statusCode === DisconnectReason.forbidden) reason = 'kicked';

            pushEvent(agentId, 'disconnected', { reason });

            await sendWebhook('agent_disconnected', agentId, {
                reason,
                disconnected_at: new Date().toISOString(),
            });

            // Auto-reconnect unless logged out or kicked
            if (statusCode !== DisconnectReason.loggedOut && statusCode !== DisconnectReason.forbidden) {
                setTimeout(() => startAgent(agentId), 5000);
            } else {
                // Clean up session on logout/kick
                const sessPath = sessionPath(agentId);
                if (fs.existsSync(sessPath)) {
                    fs.rmSync(sessPath, { recursive: true, force: true });
                }
            }
        }
    });

    sock.ev.on('messages.upsert', async ({ messages, type }) => {
        if (type !== 'notify') return;

        for (const msg of messages) {
            if (!msg.message) continue;

            const from = normalizeRemoteJid(msg.key.remoteJid);
            if (!from) continue;

            const isFromMe = msg.key.fromMe ?? false;
            if (isFromMe) continue;

            const waMessageId = msg.key.id;
            const timestamp = new Date((msg.messageTimestamp ?? Date.now()) * 1000).toISOString();

            let type = 'text';
            let content = null;
            let caption = null;
            let mediaUrl = null;
            let mediaMime = null;

            const msgContent = msg.message;
            const contextInfo = extractContextInfo(msgContent);
            const quotedContext = extractQuotedContext(contextInfo);

            if (msgContent.conversation || msgContent.extendedTextMessage) {
                type = 'text';
                content = msgContent.conversation ?? msgContent.extendedTextMessage?.text ?? '';
            } else if (msgContent.imageMessage) {
                type = 'image';
                caption = msgContent.imageMessage.caption ?? null;
                mediaMime = msgContent.imageMessage.mimetype ?? 'image/jpeg';
            } else if (msgContent.documentMessage) {
                type = 'document';
                caption = msgContent.documentMessage.caption ?? null;
                mediaMime = msgContent.documentMessage.mimetype ?? null;
            } else if (msgContent.audioMessage) {
                type = 'audio';
                mediaMime = msgContent.audioMessage.mimetype ?? 'audio/ogg';
            } else if (msgContent.videoMessage) {
                type = 'video';
                caption = msgContent.videoMessage.caption ?? null;
                mediaMime = msgContent.videoMessage.mimetype ?? 'video/mp4';
            } else {
                type = 'unsupported';
            }

            await sendWebhook('message_received', agentId, {
                wa_message_id: waMessageId,
                from,
                from_jid: msg.key.remoteJid,
                type,
                content,
                caption,
                media_url: mediaUrl,
                media_mime: mediaMime,
                timestamp,
                quoted_wa_message_id: quotedContext.waMessageId,
                quoted_from_jid: quotedContext.fromJid,
                quoted_content: quotedContext.content,
                is_from_me: false,
            });
        }
    });

    sock.ev.on('message-receipt.update', async (receipts) => {
        for (const receipt of receipts) {
            const waMessageId = receipt.key?.id;
            if (!waMessageId) continue;

            let status = 'sent';
            if (receipt.receipt?.receiptTimestamp) status = 'delivered';
            if (receipt.receipt?.readTimestamp) status = 'read';

            console.info('[agentManager] message receipt update', {
                waMessageId,
                remoteJid: receipt.key?.remoteJid || null,
                status,
                receipt,
            });

            await sendWebhook('message_status_update', agentId, {
                wa_message_id: waMessageId,
                status,
            });
        }
    });

    sock.ev.on('messages.update', (updates) => {
        for (const update of updates) {
            console.info('[agentManager] messages.update', {
                key: update.key,
                update: update.update,
            });
        }
    });

    return { status: 'starting' };
}

async function cancelAgent(agentId) {
    const sock = sockets.get(agentId);
    if (sock) {
        sockets.delete(agentId);
        clearCache(agentId);
        pushEvent(agentId, 'session_cancelled');
        try {
            await sock.end();
        } catch { /* ignore */ }
    }
}

async function disconnectAgent(agentId) {
    const sock = sockets.get(agentId);
    sockets.delete(agentId);

    const sessPath = sessionPath(agentId);
    if (fs.existsSync(sessPath)) {
        fs.rmSync(sessPath, { recursive: true, force: true });
    }

    if (sock) {
        try {
            await sock.logout();
        } catch { /* ignore */ }
    }

    await sendWebhook('agent_disconnected', agentId, {
        reason: 'logout',
        disconnected_at: new Date().toISOString(),
    });
}

async function sendMessage(agentId, to, content, type = 'text', idempotencyKey = null, options = {}) {
    const sock = sockets.get(agentId);
    if (!sock) {
        throw new Error(`Agent ${agentId} is not connected`);
    }

    cleanupOutboundIdempotencyCache();

    const jid = await resolveOutboundTarget(sock, to);
    const cacheKey = buildOutboundIdempotencyCacheKey(agentId, jid, type, idempotencyKey);
    const cached = cacheKey ? outboundSends.get(cacheKey) : null;

    if (cached && cached.expiresAt > Date.now()) {
        console.info('[agentManager] outbound idempotency hit', {
            agentId,
            resolvedJid: jid,
            type,
            idempotencyKey,
        });

        return cached.promise;
    }

    const sendPromise = (async () => {
        let payload;

        if (type === 'document') {
            if (!options.filePath || !options.filename) {
                throw new Error('Document payload requires filePath and filename');
            }

            payload = {
                document: fs.readFileSync(options.filePath),
                mimetype: 'application/pdf',
                fileName: options.filename,
                caption: content || '',
            };
        } else {
            payload = { text: content };
        }

        const sent = await sock.sendMessage(jid, payload);

        console.info('[agentManager] outbound send accepted', {
            agentId,
            originalTo: to,
            resolvedJid: jid,
            type,
            messageId: sent.key?.id,
            idempotencyKey,
        });

        return {
            message_id: sent.key?.id,
            status: 'sent',
            timestamp: new Date().toISOString(),
        };
    })();

    if (cacheKey) {
        outboundSends.set(cacheKey, {
            promise: sendPromise,
            expiresAt: Date.now() + OUTBOUND_IDEMPOTENCY_TTL_MS,
        });
    }

    try {
        return await sendPromise;
    } catch (error) {
        if (cacheKey) {
            outboundSends.delete(cacheKey);
        }
        throw error;
    }
}

async function resolveOutboundTarget(sock, to) {
    const candidates = buildOutboundCandidates(to);

    for (const candidate of candidates) {
        try {
            const [result] = await sock.onWhatsApp(candidate);

            if (result?.exists) {
                const resolved = result.lid || result.jid || candidate;

                console.info('[agentManager] outbound recipient resolved', {
                    requested: to,
                    candidate,
                    resolved,
                    lid: result.lid || null,
                    jid: result.jid || null,
                });

                return resolved;
            }
        } catch (error) {
            console.warn('[agentManager] outbound recipient resolution failed for candidate', {
                requested: to,
                candidate,
                error: error.message,
            });
        }
    }

    console.warn('[agentManager] outbound recipient not found on WhatsApp, using fallback candidate', {
        requested: to,
        fallback: candidates[0],
        candidates,
    });

    return candidates[0];
}

function buildOutboundCandidates(to) {
    const primary = normalizeOutboundJid(to);
    const candidates = [primary];

    if (!primary.includes('@')) {
        return candidates;
    }

    const [localPart, domain] = primary.split('@');

    if (domain !== 's.whatsapp.net') {
        candidates.push(`${localPart}@s.whatsapp.net`);
    }

    return [...new Set(candidates)];
}

function getStatus(agentId) {
    const sock = sockets.get(agentId);
    if (!sock) {
        return { status: 'disconnected' };
    }
    const phone = sock.user?.id?.split(':')[0];
    return {
        status: phone ? 'connected' : 'connecting',
        phone_number: phone ? '+' + phone : null,
    };
}

function extractContextInfo(message) {
    return message?.extendedTextMessage?.contextInfo
        || message?.imageMessage?.contextInfo
        || message?.videoMessage?.contextInfo
        || message?.documentMessage?.contextInfo
        || message?.audioMessage?.contextInfo
        || null;
}

function extractQuotedContext(contextInfo) {
    if (!contextInfo) {
        return {
            waMessageId: null,
            fromJid: null,
            content: null,
        };
    }

    return {
        waMessageId: contextInfo.stanzaId || null,
        fromJid: contextInfo.participant || null,
        content: extractQuotedContent(contextInfo.quotedMessage),
    };
}

function extractQuotedContent(quotedMessage) {
    if (!quotedMessage) {
        return null;
    }

    return quotedMessage.conversation
        || quotedMessage.extendedTextMessage?.text
        || quotedMessage.imageMessage?.caption
        || quotedMessage.videoMessage?.caption
        || quotedMessage.documentMessage?.caption
        || null;
}

function buildOutboundIdempotencyCacheKey(agentId, jid, type, idempotencyKey) {
    if (!idempotencyKey) {
        return null;
    }

    return `${agentId}:${jid}:${type}:${idempotencyKey}`;
}

function cleanupOutboundIdempotencyCache() {
    const now = Date.now();

    for (const [key, entry] of outboundSends.entries()) {
        if (entry.expiresAt <= now) {
            outboundSends.delete(key);
        }
    }
}

async function reconnectSavedAgents() {
    if (!fs.existsSync(SESSIONS_DIR)) return;

    const entries = fs.readdirSync(SESSIONS_DIR);
    const agentIds = entries
        .filter((name) => name.startsWith('auth_info_'))
        .map((name) => name.replace('auth_info_', ''));

    for (const agentId of agentIds) {
        if (sockets.has(agentId)) continue;
        try {
            await startAgent(agentId);
        } catch (err) {
            console.error(`[agentManager] Startup reconnect failed for ${agentId}:`, err.message);
        }
    }
}

module.exports = { startAgent, cancelAgent, disconnectAgent, sendMessage, getStatus, reconnectSavedAgents };
