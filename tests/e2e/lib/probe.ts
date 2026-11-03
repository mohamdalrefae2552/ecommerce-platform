/**
 * Measures one HTTP GET on a fresh TCP connection (no keep-alive pool), so
 * the elapsed time includes everything a proxy in the path adds.
 */
import http from 'node:http';

export type ProbeResult =
  | { ok: true; status: number; body: string; elapsedMs: number }
  | { ok: false; error: string; elapsedMs: number };

export function timedGet(url: string, timeoutMs = 10_000): Promise<ProbeResult> {
  const started = performance.now();
  const elapsed = (): number => Math.round(performance.now() - started);
  return new Promise((resolve) => {
    const request = http.get(url, { agent: false, timeout: timeoutMs }, (response) => {
      const chunks: Buffer[] = [];
      response.on('data', (chunk: Buffer) => chunks.push(chunk));
      response.on('end', () =>
        resolve({
          ok: true,
          status: response.statusCode ?? 0,
          body: Buffer.concat(chunks).toString('utf8'),
          elapsedMs: elapsed(),
        }),
      );
      response.on('error', (error) => resolve({ ok: false, error: error.message, elapsedMs: elapsed() }));
    });
    request.on('timeout', () => request.destroy(new Error(`timed out after ${timeoutMs}ms`)));
    request.on('error', (error) => resolve({ ok: false, error: error.message, elapsedMs: elapsed() }));
  });
}
