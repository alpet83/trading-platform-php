export function useApiRequest<T>(
    url: string,
    options?: {
      method?: 'GET' | 'POST' | 'PUT' | 'DELETE';
      body?: any;
      params?: Record<string, any>;
      headers?: Record<string, string>;
    }
) {
  const config = useRuntimeConfig();
  const appBasePath = String(config.public.appBasePath || '').replace(/\/+$/, '');

  const normalizeApiUrl = (rawUrl: string): string => {
    if (/^https?:\/\//i.test(rawUrl)) {
      return rawUrl;
    }

    const withLeadingSlash = rawUrl.startsWith('/') ? rawUrl : `/${rawUrl}`;
    const apiPath = withLeadingSlash.startsWith('/api/')
      ? withLeadingSlash
      : `/api${withLeadingSlash}`;

    return `${appBasePath}${apiPath}`;
  };

  const requestUrl = normalizeApiUrl(url);

  return useFetch<T>(requestUrl, {
    method: options?.method || 'GET',
    body: options?.body,
    params: options?.params,
    headers: {
      Authorization: prepareAuthHeader(),
      ...options?.headers,
    },
    async onResponseError(e: any) {
      if (e.response?.status === 401) {
        await handle401Code();
      }
    },
  });
}

/**
 * Прямой запрос к PHP signals API (get_signals.php) — НЕ через NestJS.
 * Используется только для чтения (список сигналов).
 * Для записи (edit/toggle/delete/add) используй useApiRequest — там нужна JWT-авторизация NestJS.
 * URL берётся из runtimeConfig.public.signalsApiUrl (например https://myserver.com/signals=api/).
 */
export function usePhpSignalsRequest(params: Record<string, string>) {
  const config = useRuntimeConfig();
  const base = (config.public.signalsApiUrl as string).replace(/\/+$/, '');
  const query = new URLSearchParams(params).toString();
  const url = `${base}/get_signals.php?${query}`;

  return useFetch(url, {
    method: 'GET',
  });
}

// -------------------------------------------
// Заголовки авторизации
// -------------------------------------------
export function prepareAuthHeader(): string {
  const tokenTelegram = tCookie('tokenTelegram')?.value;
  return tokenTelegram ? `Bearer ${tokenTelegram}` : '';
}

// -------------------------------------------
// Обработка 401 — редирект на login
// -------------------------------------------
export async function handle401Code() {
  tCookie('tokenTelegram').value = '';
  navigateTo('/auth/telegram');
}

// -------------------------------------------
// Утилиты
// -------------------------------------------
export function clearTokens() {
  tCookie('tokenTelegram').value = '';
}