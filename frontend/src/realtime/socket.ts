import Pusher, { type Channel } from 'pusher-js';
import { api, requireData } from '../api/client';

/**
 * Live updates (requirement 4, ADR-0016): one WebSocket to the same origin
 * (`/app/<key>` on edge, proxied to Reverb), opened on first use.
 *
 * Every channel is private: before subscribing, the library calls
 * `authorize`, which asks the API (`POST /broadcasting/auth`, with the JWT)
 * to sign the subscription. The API refuses channels the user may not
 * follow, and the WebSocket server refuses unsigned subscriptions.
 */

/** Public identifier of the WebSocket application (not a secret). */
const APP_KEY = import.meta.env.VITE_REVERB_APP_KEY ?? 'ecommerce-web';

let client: Pusher | null = null;

/**
 * Event name given to listeners when the socket comes back after a drop:
 * events pushed meanwhile were missed, so the page must re-read the truth.
 */
export const RESYNC = 'resync';
const resyncListeners = new Set<() => void>();

function socket(): Pusher {
  if (client !== null) return client;

  const secure = window.location.protocol === 'https:';
  const port = window.location.port === '' ? (secure ? 443 : 80) : Number(window.location.port);

  client = new Pusher(APP_KEY, {
    // Self-hosted server: the Pusher cluster is unused but required.
    cluster: '',
    wsHost: window.location.hostname,
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
    channelAuthorization: {
      transport: 'ajax',
      endpoint: '',
      customHandler: ({ socketId, channelName }, callback) => {
        // Through the API client: it adds the JWT and renews it when expired.
        api
          .POST('/broadcasting/auth', {
            body: { socket_id: socketId, channel_name: channelName },
          })
          .then(
            (result) => {
              callback(null, requireData(result));
            },
            (error: unknown) => {
              callback(error instanceof Error ? error : new Error('channel refused'), null);
            },
          );
      },
    },
  });

  let connectedBefore = false;
  client.connection.bind('connected', () => {
    if (connectedBefore) for (const resync of resyncListeners) resync();
    connectedBefore = true;
  });

  return client;
}

/** How many listeners each channel has, so the last one unsubscribes it. */
const listeners = new Map<string, number>();

/**
 * Subscribes to a private channel and calls `onEvent` for each of the given
 * events, and with `RESYNC` after a reconnection. Returns the function that
 * undoes it.
 */
export function listen(
  channelName: string,
  events: readonly string[],
  onEvent: (event: string, data: unknown) => void,
): () => void {
  const fullName = `private-${channelName}`;
  const channel: Channel = socket().subscribe(fullName);
  listeners.set(fullName, (listeners.get(fullName) ?? 0) + 1);

  const handlers = events.map((event) => {
    const handler = (data: unknown) => {
      onEvent(event, data);
    };
    channel.bind(event, handler);
    return { event, handler };
  });
  const resync = () => {
    onEvent(RESYNC, undefined);
  };
  resyncListeners.add(resync);

  return () => {
    for (const { event, handler } of handlers) channel.unbind(event, handler);
    resyncListeners.delete(resync);
    const remaining = (listeners.get(fullName) ?? 1) - 1;
    if (remaining > 0) {
      listeners.set(fullName, remaining);
    } else {
      listeners.delete(fullName);
      client?.unsubscribe(fullName);
    }
  };
}

/** Closes the socket (logout): the next user must not inherit subscriptions. */
export function disconnectRealtime(): void {
  client?.disconnect();
  client = null;
  listeners.clear();
  resyncListeners.clear();
}
