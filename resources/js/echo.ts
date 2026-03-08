/**
 * Laravel Echo + Reverb WebSocket client.
 *
 * Echo is initialized lazily (on first joinChatChannel call) so a missing
 * VITE_REVERB_APP_KEY or a Reverb server that's not running won't crash the
 * entire Show.vue component at import time.
 *
 * Setup (one-time, already done):
 *   composer require laravel/reverb
 *   npm install laravel-echo pusher-js
 *
 * Start Reverb alongside the dev server:
 *   php artisan reverb:start --debug
 *
 * .env vars required:
 *   BROADCAST_CONNECTION=reverb
 *   REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET
 *   REVERB_HOST=localhost  REVERB_PORT=8080  REVERB_SCHEME=http
 *   VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
 *   VITE_REVERB_HOST="${REVERB_HOST}"
 *   VITE_REVERB_PORT="${REVERB_PORT}"
 *   VITE_REVERB_SCHEME="${REVERB_SCHEME}"
 *
 * Production:
 *   - Run reverb:start as a Supervisor daemon
 *   - Set REVERB_HOST to your domain, REVERB_SCHEME=https, REVERB_PORT=443
 *   - Nginx: proxy wss://yourdomain.com → 127.0.0.1:8080
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

export interface EchoMessage {
    id: number;
    sender_type: 'user' | 'ai';
    sender: { id: number; name: string } | null;
    content: string;
    created_at: string;
}

// Lazy singleton — created on first call so import errors don't block Show.vue
let _echo: Echo | null = null;

function getEcho(): Echo {
    if (!_echo) {
        (window as any).Pusher = Pusher;
        _echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST ?? 'localhost',
            wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
        });
    }
    return _echo;
}

/**
 * Join the presence channel for a chat room.
 * Returns the channel so the caller can leave it on unmount.
 * Throws if Echo/Pusher fails — caller should catch and fall back to polling.
 */
export function joinChatChannel(
    chatId: number,
    userId: number,
    onMessage: (msg: EchoMessage) => void,
    onTyping: (data: { userId: number; userName: string }) => void,
    onParticipantJoined: (data: { userId: number; userName: string }) => void,
) {
    return getEcho()
        .join(`chat.${chatId}`)
        .here((users: any[]) => {
            console.debug('[Echo] presence:', users);
        })
        .joining((user: any) => {
            console.debug('[Echo] joined:', user.name);
        })
        .leaving((user: any) => {
            console.debug('[Echo] left:', user.name);
        })
        .listen('.message.sent', (e: { message: EchoMessage }) => {
            onMessage(e.message);
        })
        .listen('.user.typing', (e: { userId: number; userName: string }) => {
            if (e.userId !== userId) onTyping(e);
        })
        .listen('.participant.joined', (e: { userId: number; userName: string }) => {
            onParticipantJoined(e);
        });
}
