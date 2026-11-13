// WebSocket probe for `make demo-r4` (requirement 4). Runs with the Node that
// ships in the node image (built-in WebSocket and fetch, no packages).
//
//   node ws-listen.mjs <channel> <event> <timeout ms>
//   env: WS_URL (ws://localhost/app/<key>), API_URL (http://localhost/api/v1),
//        ORIGIN, TOKEN (JWT, may be empty), FORGED_AUTH (optional signature
//        sent instead of asking the API)
//
// Prints one JSON object per line:
//   {"step":"auth","status":200}             answer of POST /broadcasting/auth
//   {"step":"ready"}                         the subscription succeeded
//   {"step":"event","data":{...},"at":<ms>}  the awaited event (then exits 0)
//   {"step":"refused","reason":"..."}        subscription refused (exits 3)
//   {"step":"timeout"}                       nothing arrived in time (exits 4)
const [channel, awaited, timeoutArg] = process.argv.slice(2);
const { WS_URL, API_URL, ORIGIN, TOKEN = '', FORGED_AUTH = '' } = process.env;
const say = (line) => console.log(JSON.stringify(line));

const socket = new WebSocket(WS_URL, { headers: { Origin: ORIGIN } });
const timer = setTimeout(() => {
  say({ step: 'timeout' });
  process.exit(4);
}, Number(timeoutArg));

socket.addEventListener('error', () => {
  say({ step: 'refused', reason: 'websocket connection failed' });
  process.exit(3);
});

socket.addEventListener('message', async (frame) => {
  const message = JSON.parse(frame.data);
  const data = typeof message.data === 'string' ? JSON.parse(message.data) : message.data;

  if (message.event === 'pusher:connection_established') {
    let auth = FORGED_AUTH;
    if (auth === '') {
      const response = await fetch(`${API_URL}/broadcasting/auth`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          ...(TOKEN === '' ? {} : { Authorization: `Bearer ${TOKEN}` }),
        },
        body: JSON.stringify({ socket_id: data.socket_id, channel_name: channel }),
        signal: AbortSignal.timeout(10_000),
      });
      say({ step: 'auth', status: response.status });
      if (!response.ok) {
        say({ step: 'refused', reason: `the API answered ${response.status}` });
        process.exit(3);
      }
      auth = (await response.json()).auth;
    }
    socket.send(JSON.stringify({ event: 'pusher:subscribe', data: { channel, auth } }));
  } else if (message.event === 'pusher_internal:subscription_succeeded') {
    say({ step: 'ready' });
    if (awaited === '-') {
      clearTimeout(timer);
      process.exit(0);
    }
  } else if (message.event === 'pusher:error') {
    say({ step: 'refused', reason: data?.message ?? 'pusher:error' });
    process.exit(3);
  } else if (message.event === awaited && message.channel === channel) {
    say({ step: 'event', data, at: Date.now() });
    clearTimeout(timer);
    process.exit(0);
  }
});
