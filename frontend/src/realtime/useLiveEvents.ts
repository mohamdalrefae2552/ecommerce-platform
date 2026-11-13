import { useEffect, useRef } from 'react';
import { listen } from './socket';

/**
 * Listens to events of a private channel while the component is mounted.
 * `channelName === null` means "do not subscribe" (e.g. the user has no
 * right to this channel).
 */
export function useLiveEvents(
  channelName: string | null,
  events: readonly string[],
  onEvent: (event: string, data: unknown) => void,
): void {
  // The latest callback, without resubscribing on every render.
  const callback = useRef(onEvent);
  useEffect(() => {
    callback.current = onEvent;
  });

  const eventList = events.join(',');
  useEffect(() => {
    if (channelName === null) return undefined;
    return listen(channelName, eventList.split(','), (event, data) => {
      callback.current(event, data);
    });
  }, [channelName, eventList]);
}
