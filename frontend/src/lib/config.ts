interface RuntimeConfig {
  apiV2Base?: string;
  apiBase?: string;
  appName?: string;
}

declare global {
  interface Window {
    CRM_CONFIG?: RuntimeConfig;
  }
}

const runtime: RuntimeConfig = (typeof window !== 'undefined' ? window.CRM_CONFIG : undefined) ?? {};

export const config = {
  apiBase: runtime.apiBase ?? import.meta.env.VITE_API_BASE ?? './back/index.php',
  apiV2Base: runtime.apiV2Base ?? import.meta.env.VITE_API_V2_BASE ?? './api/v2',
  appName: runtime.appName ?? 'CRM',
  chatPollMs: 7000,
  notificationsPollMs: 15000,
  listPollMs: 30000
} as const;
