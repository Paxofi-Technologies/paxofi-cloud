import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  build: {
    sourcemap: false,
    rollupOptions: {
      // Two pages: the customer portal and the staff console. Nginx serves
      // admin.html only on the admin host (identity threat model §6.19). Paths
      // are relative to this directory, where every npm script runs.
      input: {
        index: 'index.html',
        admin: 'admin.html',
      },
    },
  },
  server: {
    host: '127.0.0.1',
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    css: true,
  },
});
