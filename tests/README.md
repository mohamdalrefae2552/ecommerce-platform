# tests/ — system-level proofs

Black-box tests written from the contract (`contracts/`) and the requirements
(`docs/00`), run against the full compose stack through `edge`.

| Folder | Tool | Run |
|---|---|---|
| `e2e/` | Playwright 1.64.1 (TypeScript) | `make up && make verify-e2e` |
| `load/` | k6 2.3.0 | `make race-test` (P2-QA-01); skeleton: `docker compose --profile tools run --rm k6 run /work/tests/load/smoke.js` |
| `security/scripts/` | bash + curl + jq (+ a Node WebSocket probe) | `make demo-r1`, `make demo-r2`, `make demo-r4` |

## e2e layout
- `playwright.config.ts` — targets `EDGE_URL`; one worker (rate limits are per IP, chaos specs share the inventory link); no retries.
- `lib/env.ts` — `EDGE_URL` (default `http://localhost`) and `TOXIPROXY_URL` (default `http://toxiproxy:8474`), the values compose sets.
- `lib/api.ts` — contract types and helpers: `registerCustomer`, `login`, `createCustomer`.
- `lib/toxiproxy.ts` — Toxiproxy admin API: `down`, `up`, `latency(ms)`, `resetProxy`, `resetAll`.
- `fixtures/index.ts` — `customer`, `makeCustomer`, `chaos` (restores the inventory link after every test).
- `harness/` — project `harness`: self-tests of the Toxiproxy helper that measure the injected latency on a throw-away proxy.
- `specs/` — project `chromium`: the end-to-end specs (`smoke.spec.ts` first).

From the host (stack up, Node 24): `cd tests/e2e && npm ci && EDGE_URL=http://localhost:8080 npx playwright test --project=chromium`.
The `harness` project needs the Toxiproxy admin API reachable at `TOXIPROXY_URL`, which is only true inside the compose network.
