import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';
import path from 'node:path';

// Vitest runs without the Laravel/PWA/Tailwind plugins: pure TS/TSX under jsdom.
export default defineConfig({
  plugins: [react()],
  resolve: { alias: { '@panel': path.resolve('resources/js/panel'), '@site': path.resolve('resources/js/site'), '@shared': path.resolve('resources/js/shared'), '@lang': path.resolve('resources/lang') } },
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/__tests__/**/*.test.{ts,tsx}', 'tests/js/**/*.test.{ts,tsx}'],
    setupFiles: ['tests/js/setup.ts'],
    restoreMocks: true,
    clearMocks: true,
    css: false,
  },
});
