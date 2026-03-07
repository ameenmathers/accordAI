/**
 * Laravel Echo + Reverb WebSocket client.
 *
 * This file is imported by Show.vue to replace polling with true real-time.
 * It is ONLY active after you run the setup commands below.
 *
 * Setup (one-time):
 *   composer require laravel/reverb
 *   php artisan reverb:install
 *   npm install laravel-echo pusher-js
 *
 * Then start Reverb alongside the dev server:
 *   php artisan reverb:start --debug
 *
 * Add to .env (reverb:install does this automatically):
 *   BROADCAST_CONNECTION=reverb
 *   REVERB_APP_ID=my-app
 *   REVERB_APP_KEY=my-key
 *   REVERB_APP_SECRET=my-secret
 *   REVERB_HOST=localhost
 *   REVERB_PORT=8080
 *   REVERB_SCHEME=http
 *   VITE_REVERB_APP_KEY="${REVERB_APP_KEY}"
 *   VITE_REVERB_HOST="${REVERB_HOST}"
 *   VITE_REVERB_PORT="${REVERB_PORT}"
 *   VITE_REVERB_SCHEME="${REVERB_SCHEME}"
 */

// Uncomment after running: npm install laravel-echo pusher-js
// import Echo from 'laravel-echo';
// import Pusher from 'pusher-js';
//
// (window as any).Pusher = Pusher;
//
// export const echo = new Echo({
//     broadcaster: 'reverb',
//     key: import.meta.env.VITE_REVERB_APP_KEY,
//     wsHost: import.meta.env.VITE_REVERB_HOST,
//     wsPort: import.meta.env.VITE_REVERB_PORT ?? 8080,
//     wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
//     forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
//     enabledTransports: ['ws', 'wss'],
// });
//
// export function joinChatChannel(chatId: number, userId: number, onMessage: (msg: any) => void, onTyping: (data: any) => void) {
//     return echo
//         .join(`chat.${chatId}`)
//         .here((users: any[]) => {
//             console.log('Presence:', users);
//         })
//         .joining((user: any) => {
//             console.log('Joined:', user.name);
//         })
//         .leaving((user: any) => {
//             console.log('Left:', user.name);
//         })
//         .listen('.message.sent', (e: any) => {
//             onMessage(e.message);
//         })
//         .listen('.user.typing', (e: any) => {
//             if (e.userId !== userId) onTyping(e);
//         });
// }

export {}; // placeholder — remove when uncommenting above
