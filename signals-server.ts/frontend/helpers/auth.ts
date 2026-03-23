let afterRefreshQueue: any[] = [];
let isRefreshing = false;

async function loadQueueAfterRefresh(error: any, token = null) {
  afterRefreshQueue.forEach((prom) => {
    if (error) {
      prom.reject(error);
    } else {
      prom.resolve(token);
    }
  });

  afterRefreshQueue = [];
}

export async function makeRefreshToken(
  currentToken: string | null | undefined,
  refreshToken: string | null | undefined
) {
  if (!refreshToken) {
    return null;
  }
  if (!isRefreshing) {
    try {
      let baseURL = useRuntimeConfig().public.baseURL as string;
      const newTokens = await useFetch<any>("/api/auth/base/refreshToken", {
        baseURL,
        method: "POST",
        body: {
          token: currentToken,
          refreshToken: refreshToken,
          expiresIn: getExpiresForToken(),
        },
      });
      await loadQueueAfterRefresh(null, newTokens.value);
      return newTokens.data.value;
    } catch (error) {
      await loadQueueAfterRefresh(error, null);
    }
  } else {
    return new Promise((resolve, reject) => {
      afterRefreshQueue.push({
        resolve,
        reject,
      });
    });
  }
}

export function getExpiresForToken(): number {
  return 3 * 60 * 60;
}

export function getRefreshToken(): string {
  const refreshToken = tCookie("refreshTokenBase");
  return refreshToken.value;
}

export function saveRefreshToken(token: string): string {
  const refreshToken = tCookie("refreshTokenBase");
  return (refreshToken.value = token);
}

export function saveBaseAccessToken(token: string): string {
  const refreshToken = tCookie("tokenBase");
  return (refreshToken.value = token);
}

export function clearTokens() {
  saveBaseAccessToken("");
  saveRefreshToken("");
  tCookie("tokenTelegram").value = "";
}

export async function handle401Code(
  errorData: { message: string },
  callbacks: {
    afterRefresh: (newTokens: { token: string; refreshToken: string }) => void;
  }
) {
  if (errorData.message === "TOKEN_IS_EMPTY") {
    tCookie("tokenBase").value = "";

    tCookie("tokenTelegram").value = "";
    navigateTo("/auth");
  }
  if (errorData.message === "TOKEN_EXPIRED") {
    const refreshToken = getRefreshToken();
    if (!refreshToken) {
      navigateTo("/auth");
    }
    const newTokens = await makeRefreshToken(
      tCookie("tokenBase").value,
      refreshToken
    );
    saveRefreshToken(newTokens.refreshToken);
    saveBaseAccessToken(newTokens.token);

    return callbacks.afterRefresh(newTokens);
  }
}

export function createAuthHeaderForBaseToken(baseToken: string) {
  return "Bearer " + baseToken;
}

export function prepareAuthHeader(): string {
  const tokenBase = tCookie("tokenBase");
  const tokenTelegram = tCookie("tokenTelegram");
  let resultHeader = "";
  let baseToken = tokenBase.value;
  if (baseToken) {
    resultHeader = createAuthHeaderForBaseToken(baseToken);
  }
  if (tokenTelegram.value) {
    resultHeader = "Telegram " + tokenTelegram.value;
  }

  return resultHeader;
}
