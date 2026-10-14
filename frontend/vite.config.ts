import { defineConfig } from 'vitest/config';
import { loadEnv } from 'vite';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, '.', '');

  return {
    plugins: [svelte()],
    base: './',
    server: {
      proxy: {
        '/api': {
          target: env.CRM_API_TARGET ?? 'http://127.0.0.1:8080',
          changeOrigin: false
        }
      }
    },
    build: {
      outDir: 'dist',
      sourcemap: true
    },
    test: {
      setupFiles: ['./src/test-setup.ts']
    }
  };
});
