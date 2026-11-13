/**
 * In-memory stand-in for `src/realtime/socket.ts` (installed for every test
 * by `setup.ts`): no WebSocket is opened; tests push events with `emitLive`.
 */
type Listener = (event: string, data: unknown) => void;

interface Subscription {
  readonly events: readonly string[];
  readonly onEvent: Listener;
}

const subscriptions = new Map<string, Set<Subscription>>();

export const RESYNC = 'resync';

export function listen(
  channelName: string,
  events: readonly string[],
  onEvent: Listener,
): () => void {
  const subscription: Subscription = { events, onEvent };
  const set = subscriptions.get(channelName) ?? new Set<Subscription>();
  set.add(subscription);
  subscriptions.set(channelName, set);

  return () => {
    set.delete(subscription);
    if (set.size === 0) subscriptions.delete(channelName);
  };
}

export function disconnectRealtime(): void {
  subscriptions.clear();
}

/** Channels something is currently subscribed to. */
export function liveChannels(): string[] {
  return [...subscriptions.keys()];
}

/** Pushes an event to the listeners of a channel, like the server would. */
export function emitLive(channelName: string, event: string, data: unknown): void {
  for (const subscription of subscriptions.get(channelName) ?? []) {
    if (subscription.events.includes(event)) subscription.onEvent(event, data);
  }
}
