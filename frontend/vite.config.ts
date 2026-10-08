import { defineConfig } from 'vitest/config';
import { svelte } from '@sveltejs/vite-plugin-svelte';

export default defineConfig({
  plugins: [svelte()],
  base: './',
  server: {
    proxy: {
      '/api': {
        target: process.env.CRM_API_TARGET ?? 'http://127.0.0.1:8080',
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
});
