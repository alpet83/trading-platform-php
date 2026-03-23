import type { CookieOptions } from '#app';

export function tCookie<T = any>(name: string, opts?: CookieOptions): Ref<T> {
  return useCookie(name, opts);
}