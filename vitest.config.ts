import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vitest/config';

// Frontend unit/component tests (ARCHITECTURE.md §12: Vitest + Vue Testing Library).
// Kept separate from vite.config.ts so the Laravel and Tailwind plugins are not loaded for tests.
// Test files live next to the code as `*.test.ts`. The production build only bundles what app.ts
// imports (its page glob matches `.vue` files), so test files never reach public/build.
export default defineConfig({
  plugins: [vue()],
  test: {
    environment: 'jsdom',
    include: ['resources/js/**/*.test.ts'],
    restoreMocks: true,
  },
});
