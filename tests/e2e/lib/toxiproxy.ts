/**
 * Thin client for Toxiproxy's admin REST API (https://github.com/Shopify/toxiproxy#http-api).
 *
 * Toxiproxy sits between api/scheduler and the inventory service
 * (infra/toxiproxy/toxiproxy.json); the proofs of R2-E2 / PROOF-R2-2 cut or
 * slow that link with `down()` / `latency()` and always restore it with
 * `resetProxy()` (the `chaos` fixture does this after every test).
 *
 * Every call has an explicit deadline so a dead Toxiproxy
 * fails the test instead of hanging it.
 */
import { env } from './env';

/** Name of the api → inventory proxy in infra/toxiproxy/toxiproxy.json. */
export const INVENTORY_PROXY = 'inventory';

export type Stream = 'upstream' | 'downstream';

export interface Toxic {
  name: string;
  type: string;
  stream: Stream;
  toxicity: number;
  attributes: Record<string, number>;
}

export interface Proxy {
  name: string;
  listen: string;
  upstream: string;
  enabled: boolean;
  toxics: Toxic[];
}

export interface ProxySpec {
  name: string;
  listen: string;
  upstream: string;
  enabled?: boolean;
}

export interface LatencyOptions {
  /** Random +/- variation in ms (default 0: the delay is exactly `ms`). */
  jitter?: number;
  /** `downstream` (default) delays the responses coming back to the client. */
  stream?: Stream;
}

export class ToxiproxyError extends Error {
  constructor(
    readonly method: string,
    readonly path: string,
    readonly status: number,
    readonly body: string,
  ) {
    super(`Toxiproxy ${method} ${path} -> HTTP ${status}: ${body.trim() || '(empty body)'}`);
    this.name = 'ToxiproxyError';
  }
}

/** Name used for the single latency toxic, so a new value replaces the old one. */
const LATENCY_TOXIC = 'latency';

export class Toxiproxy {
  constructor(
    readonly baseUrl: string = env.toxiproxyUrl,
    private readonly timeoutMs: number = env.toxiproxyTimeoutMs,
  ) {}

  /** Host name the proxies' listen ports are reachable on (same host as the admin API). */
  get host(): string {
    return new URL(this.baseUrl).hostname;
  }

  async version(): Promise<string> {
    const raw = await this.call('GET', '/version');
    // 2.x answers {"version":"x.y.z"}; older releases answered plain text.
    try {
      const parsed: unknown = JSON.parse(raw);
      if (parsed && typeof parsed === 'object' && 'version' in parsed && typeof parsed.version === 'string') {
        return parsed.version;
      }
    } catch {
      // plain-text answer
    }
    return raw.trim();
  }

  async getProxy(name: string): Promise<Proxy> {
    return JSON.parse(await this.call('GET', `/proxies/${encodeURIComponent(name)}`)) as Proxy;
  }

  async createProxy(spec: ProxySpec): Promise<Proxy> {
    const body = { enabled: true, ...spec };
    return JSON.parse(await this.call('POST', '/proxies', body)) as Proxy;
  }

  /** Deletes a proxy; a proxy that does not exist is not an error. */
  async deleteProxy(name: string): Promise<void> {
    await this.call('DELETE', `/proxies/${encodeURIComponent(name)}`, undefined, [404]);
  }

  async setEnabled(name: string, enabled: boolean): Promise<Proxy> {
    return JSON.parse(await this.call('POST', `/proxies/${encodeURIComponent(name)}`, { enabled })) as Proxy;
  }

  /** Cuts the link: new connections are refused and open ones are closed. */
  async down(name: string = INVENTORY_PROXY): Promise<Proxy> {
    return this.setEnabled(name, false);
  }

  /** Re-enables the link (toxics are kept; use `resetProxy` to clear them). */
  async up(name: string = INVENTORY_PROXY): Promise<Proxy> {
    return this.setEnabled(name, true);
  }

  /**
   * Delays every chunk on `stream` by exactly `ms` (default jitter 0).
   * Calling it again replaces the previous value instead of stacking toxics.
   */
  async latency(ms: number, name: string = INVENTORY_PROXY, options: LatencyOptions = {}): Promise<Toxic> {
    if (!Number.isInteger(ms) || ms < 0) {
      throw new RangeError(`latency must be a non-negative integer of ms, got ${ms}`);
    }
    const jitter = options.jitter ?? 0;
    if (!Number.isInteger(jitter) || jitter < 0) {
      throw new RangeError(`jitter must be a non-negative integer of ms, got ${jitter}`);
    }
    await this.removeToxic(LATENCY_TOXIC, name);
    const toxic = {
      name: LATENCY_TOXIC,
      type: 'latency',
      stream: options.stream ?? 'downstream',
      toxicity: 1,
      attributes: { latency: ms, jitter },
    };
    return JSON.parse(await this.call('POST', `/proxies/${encodeURIComponent(name)}/toxics`, toxic)) as Toxic;
  }

  /** Removes one toxic; a toxic that does not exist is not an error. */
  async removeToxic(toxic: string, name: string = INVENTORY_PROXY): Promise<void> {
    await this.call(
      'DELETE',
      `/proxies/${encodeURIComponent(name)}/toxics/${encodeURIComponent(toxic)}`,
      undefined,
      [404],
    );
  }

  /** Restores one proxy: every toxic removed and the link enabled. Other proxies are untouched. */
  async resetProxy(name: string = INVENTORY_PROXY): Promise<Proxy> {
    const proxy = await this.getProxy(name);
    for (const toxic of proxy.toxics) {
      await this.removeToxic(toxic.name, name);
    }
    return this.up(name);
  }

  /** Global `POST /reset`: re-enables every proxy and removes every toxic. */
  async resetAll(): Promise<void> {
    await this.call('POST', '/reset');
  }

  private async call(method: string, path: string, body?: unknown, tolerated: number[] = []): Promise<string> {
    const init: RequestInit = { method, signal: AbortSignal.timeout(this.timeoutMs) };
    if (body !== undefined) {
      init.headers = { 'Content-Type': 'application/json' };
      init.body = JSON.stringify(body);
    }
    let response: Response;
    try {
      response = await fetch(`${this.baseUrl}${path}`, init);
    } catch (cause) {
      throw new Error(`Toxiproxy ${method} ${path} failed: is ${this.baseUrl} reachable? (${String(cause)})`, {
        cause,
      });
    }
    const text = await response.text();
    if (!response.ok && !tolerated.includes(response.status)) {
      throw new ToxiproxyError(method, path, response.status, text);
    }
    return text;
  }
}
