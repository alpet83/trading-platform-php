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

const withBasePath = (basePath: string, path: string) => {
  if (!basePath) {
    return path;
  }

  if (path === '/') {
    return `${basePath}/`;
  }

  if (path.startsWith('/')) {
    return `${basePath}${path}`;
  }

  return `${basePath}/${path}`;
};

const appBasePath = normalizeBasePath(process.env.APP_BASE_PATH);
const apiPrefix = '/api';

export default defineNuxtConfig({
  ssr: false,
  vite: {
    server: {
      host: true,
      strictPort: false,
      allowedHosts: ['.loca.lt'],
    },
  },
  app: {
      baseURL: `${appBasePath || ''}/`,
  },
  modules: ["@nuxtjs/tailwindcss", "@nuxtjs/i18n"],
  runtimeConfig: {
    public: {
      baseURL: appBasePath || 'http://localhost:3001',
      appBasePath,
      // URL PHP signals API (get_signals.php). НЕ путь NestJS.
      // Для записи (edit/toggle/delete) запросы идут через NestJS /api/signals/*
      signalsApiUrl: process.env.SIGNALS_API_URL || 'http://localhost/signals/',
      telegramBot: process.env.TELEGRAM_BOT_USERNAME,
      apiPrefix,
    },
  },
  components: true,
  i18n: {
    locales: [
      { code: 'en', name: 'English', file: 'en.json' },
      { code: 'ru', name: 'Русский', file: 'ru.json' }
    ],
    defaultLocale: 'en',
    langDir: '../locales/',
    strategy: 'no_prefix',
    vueI18n: './i18n.config.ts'
  },
  tailwindcss: {
    config: {
      mode: "jit",
      content: [
        "./component/**/*.{vue,js,ts}",
        "./pages/**/*.{vue,js,ts}",
        "./layouts/**/*.{vue,js,ts}",
      ],
    },
    exposeConfig: true,
    viewer: true,
  },
  compatibilityDate: '2024-08-02',
})
