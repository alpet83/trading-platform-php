import {navigateTo, useCookie, UseFetchOptions} from '#app';
import { NitroFetchRequest } from 'nitropack';
import { KeyOfRes } from 'nuxt/dist/app/composables/asyncData';
export async function useApiRequest<T>(
    request: NitroFetchRequest,
    opts?:
        | UseFetchOptions<
        T extends void ? unknown : T,
        (res: T extends void ? unknown : T) => T extends void ? unknown : T,
        KeyOfRes<
            (res: T extends void ? unknown : T) => T extends void ? unknown : T
        >
    >
        | undefined
) {
  const token = useCookie('tokenTelegram');
  const config = useRuntimeConfig();

  const base = config.public.baseURL.replace(/\/+$/, '');

  const fetch = await useFetch(request, {
    baseURL: base,
    headers: { Authorization: 'Bearer ' + (token.value || '') },
    ...opts,
  });

  if (fetch.error.value) {
    const status = fetch.error.value.data?.statusCode;
    if (status === 401 || status === 403) {
      // await navigateTo('/auth/telegram');
    }
    throw new Error(fetch.error.value.data?.message || 'Ошибка запроса');
  }

  return fetch.data.value;
}