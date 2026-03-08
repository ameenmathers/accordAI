/**
 * Laravel Echo + Reverb WebSocket client.
 *
 * Setup (one-time, already done):
 *   composer require laravel/reverb
 *   npm install laravel-echo pusher-js
 *
 * Start Reverb alongside the dev server:
 *   php artisan reverb:start --debug
 *
 * .env vars (add if not present):
 *   BROADCAST_CONNECTION=reverb
 *   REVERB_APP_ID=accord-local
 *   REVERB_APP_KEY=accord-key
 *   REVERB_APP_SECRET=accord-secret
 *   REVERB_HOST=localhost
 *   REVERB_PORT=8080
 *   REVERB_SCHEME=http
 *   VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
 *   VITE_REVERB_HOST="${REVERB_HOST}"
 *   VITE_REVERB_PORT="${REVERB_PORT}"
 *   VITE_REVERB_SCHEME="${REVERB_SCHEME}"
 *
 * Production (Forge / cloud):
 *   - Run: php artisan reverb:start as a Supervisor daemon
 *   - Or use a managed Pusher/Ably account and point the Pusher driver at it
 *   - Set REVERB_HOST to your domain (e.g. accord.example.com)
 *   - Set REVERB_SCHEME=https and REVERB_PORT=443
 *   - Nginx: proxy wss://accord.example.com:8080 → 127.0.0.1:8080
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

(window as any).Pusher = Pusher;

export const echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST ?? 'localhost',
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
});

export interface EchoMessage {
    id: number;
    sender_type: 'user' | 'ai';
    sender: { id: number; name: string } | null;
    content: string;
    created_at: string;
}

/**
 * Join the presence channel for a chat room.
 * Returns the channel so the caller can leave it on unmount.
 */
export function joinChatChannel(
    chatId: number,
    userId: number,
    onMessage: (msg: EchoMessage) => void,
    onTyping: (data: { userId: number; userName: string }) => void,
    onParticipantJoined: (data: { userId: number; userName: string }) => void,
) {
    return echo
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
