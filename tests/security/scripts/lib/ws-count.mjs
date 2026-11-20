// WebSocket probe for `make scale-problems` (requirement 5): subscribes to a
// private channel and counts how many times an event arrives.
//
//   node ws-count.mjs <channel> <event> <listen ms>
//   env: WS_URL, API_URL, ORIGIN, TOKEN (as ws-listen.mjs)
//
// Prints {"step":"ready"} once subscribed, then {"step":"done","count":N}.
const [channel, awaited, listenArg] = process.argv.slice(2);
const { WS_URL, API_URL, ORIGIN, TOKEN = '' } = process.env;
const say = (line) => console.log(JSON.stringify(line));
let count = 0;

const socket = new WebSocket(WS_URL, { headers: { Origin: ORIGIN } });
socket.addEventListener('error', () => {
  say({ step: 'refused', reason: 'websocket connection failed' });
  process.exit(3);
});

socket.addEventListener('message', async (frame) => {
  const message = JSON.parse(frame.data);
  const data = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;

  if (message.event === 'pusher:connection_established') {
    const response = await fetch(`${API_URL}/broadcasting/auth`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${TOKEN}` },
      body: JSON.stringify({ socket_id: data.socket_id, channel_name: channel }),
      signal: AbortSignal.timeout(10_000),
    });
    if (!response.ok) {
      say({ step: 'refused', reason: `the API answered ${response.status}` });
      process.exit(3);
    }
    socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth: (await response.json()).auth } }));
  } else if (message.event === 'pusher_internal:subscription_succeeded') {
    say({ step: 'ready' });
    setTimeout(() => {
      say({ step: 'done', count });
      process.exit(0);
    }, Number(listenArg));
  } else if (message.event === awaited && message.channel === channel) {
    count += 1;
  }
});
