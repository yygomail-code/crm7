export const config = {
  apiBase: import.meta.env.VITE_API_BASE ?? './back/index.php',
  apiV2Base: import.meta.env.VITE_API_V2_BASE ?? '/api/v2',
  appName: 'CRM',
  chatPollMs: 7000,
  notificationsPollMs: 15000
} as const;
