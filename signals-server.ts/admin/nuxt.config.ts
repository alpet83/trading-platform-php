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

  runtimeConfig: {
    public: {
      baseURL: appBasePath || 'http://localhost:3001',
      apiPrefix,
    },
  },

  app: {
    baseURL: process.env.NODE_ENV === "production" ? withBasePath(appBasePath, "/admin") : "/",
  },

  modules: ["@invictus.codes/nuxt-vuetify"],

  vuetify: {
    /* vuetify options */
    vuetifyOptions: {
      // @TODO: list all vuetify options
    },
    moduleOptions: {
      treeshaking: true,
      useIconCDN: true,
      styles: true,
      autoImport: true,
      useVuetifyLabs: false,
    },
  },

  compatibilityDate: "2024-07-23",
});
