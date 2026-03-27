import * as dotenv from 'dotenv';

dotenv.config();

const normalizeBasePath = (value?: string) => {
  if (!value) {
    return '';
  }

  const trimmed = value.trim();

  if (!trimmed || trimmed === '/') {
    return '';
  }

  const withLeadingSlash = trimmed.startsWith('/') ? trimmed : `/${trimmed}`;

  return withLeadingSlash.replace(/\/+$/, '');
};

const requiredEnvs = [
  'DATABASE_URL',
  'BOT_TOKEN',
  'SIGNALS_API_URL',
  'TRADING_DB_AUTH_URL',
];

for (const key of requiredEnvs) {
  if (!process.env[key]) {
    throw new Error(`вќЊ Missing required environment variable: ${key}`);
  }
}

const backendBasePath = normalizeBasePath(process.env.APP_BASE_PATH);

const resolveFetchTimeoutMs = () => {
  const raw = process.env.HTTP_FETCH_TIMEOUT_MS ?? '10000';
  const parsed = Number.parseInt(raw, 10);
  return Number.isFinite(parsed) && parsed > 0 ? parsed : 10000;
};

export class FetchTimeoutError extends Error {
  constructor(url: string, timeoutMs: number) {
    super(`request timed out after ${timeoutMs}ms: ${url}`);
    this.name = 'FetchTimeoutError';
  }
}

async function fetchWithTimeout(
  url: string,
  init?: import('node-fetch').RequestInit,
): Promise<import('node-fetch').Response> {
  const timeoutMs = resolveFetchTimeoutMs();
  const timeoutController = new AbortController();
  const inheritedSignal = init?.signal;

  const onAbort = () => timeoutController.abort();
  inheritedSignal?.addEventListener('abort', onAbort, { once: true });

  const timer = setTimeout(() => timeoutController.abort(), timeoutMs);
  try {
    const nodeFetch = (await import('node-fetch')).default;
    return await nodeFetch(url, {
      ...init,
      signal: timeoutController.signal,
    });
  } catch (err: unknown) {
    if (err instanceof Error && err.name === 'AbortError') {
      throw new FetchTimeoutError(url, timeoutMs);
    }
    throw err;
  } finally {
    clearTimeout(timer);
    inheritedSignal?.removeEventListener('abort', onAbort);
  }
}

/**
 * Encodes '#' in the userinfo (password) part of a mysql:// URL.
 * mysql2 uses the WHATWG URL parser which treats '#' as a fragment delimiter,
 * causing "Invalid URL" when the password contains it.
 * Example: mysql://user:p#ss@host/db  →  mysql://user:p%23ss@host/db
 */
export function sanitizeDbUrl(url: string): string {
  const atIndex = url.lastIndexOf('@');
  if (atIndex === -1) return url;
  const userInfo = url.substring(0, atIndex);
  const rest = url.substring(atIndex);
  return userInfo.replace(/#/g, '%23') + rest;
}

// РњРѕР¶РЅРѕ СЌРєСЃРїРѕСЂС‚РёСЂРѕРІР°С‚СЊ РѕР±СЉРµРєС‚ РґР»СЏ СѓРґРѕР±РЅРѕРіРѕ РґРѕСЃС‚СѓРїР°
export const Env = {
  PORT: parseInt(process.env.PORT ?? '3001', 10),
  DATABASE_URL: process.env.DATABASE_URL as string,
  TRADING_DB_AUTH_URL: process.env.TRADING_DB_AUTH_URL as string,
  USERS_API_SOURCE: process.env.USERS_API_SOURCE ?? 'php',
  TRADING_EVENTS_ENABLED: process.env.TRADING_EVENTS_ENABLED ?? '1',
  TRADING_EVENTS_HOST: process.env.TRADING_EVENTS_HOST ?? '',
  SIGNALS_API_URL: process.env.SIGNALS_API_URL as string,
  AUTH_TOKEN: process.env.AUTH_TOKEN as string,
  INSTANCE_API_URL: process.env.INSTANCE_API_URL ?? process.env.BOTS_STATS_URL ?? '',
  /**
   * Relative path to the API root, e.g. /botctl/api
   * Used to build absolute backend URLs together with DOMAIN.
   * Defaults to APP_BASE_PATH + /api.
   */
  BACKEND_API_PATH: process.env.BACKEND_API_PATH ?? `${backendBasePath}/api`,
  APP_BASE_PATH: backendBasePath,
  API_PREFIX: '',
  SWAGGER_PATH: `${backendBasePath}/api/swagger`,
  // Р”Р»СЏ Р°РІС‚РѕСЂРёР·Р°С†РёРё
  BOT_TOKEN: process.env.TELEGRAM_BOT_TOKEN as string,
};

/**
 * Builds an absolute URL for internal HTTP calls (bots, chart, stats services).
 * Uses DOMAIN env var + BACKEND_API_PATH so the call goes through Apache2 reverse
 * proxy the same way the browser does - no localhost:port assumptions needed.
 *
 * Example: buildBackendUrl('/signals') => "https://myserver.com/test-app/api/signals"
 *
 * DOMAIN must be set in .env.node.docker, e.g.: DOMAIN=myserver.com
 */
/**
 * Normalizes a domain value to an absolute origin.
 * Handles three cases:
 *   "myserver.com"          → "https://myserver.com"   (no scheme → assume https)
 *   "http://myserver.com"   → "http://myserver.com"    (explicit http kept)
 *   "https://myserver.com/" → "https://myserver.com"   (trailing slash stripped)
 */
function normalizeDomain(raw: string): string {
  const trimmed = raw.trim().replace(/\/+$/, '');
  if (!trimmed) return '';
  if (/^https?:\/\//i.test(trimmed)) return trimmed;
  return `https://${trimmed}`;
}

export function buildBackendUrl(path: string): string {
  const domain = normalizeDomain(process.env.DOMAIN ?? '');
  const apiPath = Env.BACKEND_API_PATH.replace(/\/+$/, '');
  const suffix = path.startsWith('/') ? path : `/${path}`;
  return `${domain}${apiPath}${suffix}`;
}


/**
 * Drop-in replacement for node-fetch with:
 *  - automatic relative-path resolution via buildBackendUrl()
 *  - detailed console logging (URL, status, timing)
 *  - descriptive errors instead of raw TypeError
 *
 * Usage: const data = await backendFetch('/bots', { method: 'GET', headers })
 */
export async function backendFetch(
  path: string,
  init?: import('node-fetch').RequestInit,
): Promise<import('node-fetch').Response> {
  const url = path.startsWith('http') ? path : buildBackendUrl(path);

  // Validate before calling node-fetch to get a descriptive error instead of bare "Invalid URL"
  try {
    new URL(url);
  } catch {
    const domain = normalizeDomain(process.env.DOMAIN ?? '');
    console.error(`[backendFetch] Invalid URL "${url}"`);
    console.error(`  path="${path}" DOMAIN raw="${process.env.DOMAIN}" normalized="${domain}" BACKEND_API_PATH="${Env.BACKEND_API_PATH}"`);
    throw new TypeError(`backendFetch: invalid URL "${url}" — check DOMAIN env var (got: "${process.env.DOMAIN ?? ''}")`);
  }

  const t0 = Date.now();
  console.log(`[backendFetch] --> ${init?.method ?? 'GET'} ${url}`);
  try {
    const res = await fetchWithTimeout(url, init);
    console.log(`[backendFetch] <-- ${res.status} ${url} (${Date.now() - t0}ms)`);
    return res;
  } catch (err: unknown) {
    const msg = err instanceof Error ? err.message : String(err);
    console.error(`[backendFetch] FAIL ${url}: ${msg}`);
    console.error(`  DOMAIN raw="${process.env.DOMAIN}" normalized="${normalizeDomain(process.env.DOMAIN ?? '')}" BACKEND_API_PATH="${Env.BACKEND_API_PATH}"`);
    throw err;
  }
}

/**
 * Validated fetch for external services (stats, signals).
 * Logs URL + timing, throws descriptive error if URL is invalid or empty.
 * serviceName is used in log prefix, e.g. "stats", "signals".
 */
export async function safeFetch(
  serviceName: string,
  url: string,
  init?: import('node-fetch').RequestInit,
): Promise<import('node-fetch').Response> {
  if (!url) {
    throw new TypeError(`[${serviceName}] safeFetch: URL is empty — check env var for this service`);
  }
  try { new URL(url); } catch {
    throw new TypeError(`[${serviceName}] safeFetch: invalid URL "${url}"`);
  }
  const t0 = Date.now();
  console.log(`[${serviceName}] --> ${init?.method ?? 'GET'} ${url}`);
  const res = await fetchWithTimeout(url, init);
  console.log(`[${serviceName}] <-- ${res.status} ${url} (${Date.now() - t0}ms)`);
  return res;
}