import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript';
import prettier from 'eslint-config-prettier/flat';
import vue from 'eslint-plugin-vue';

export default defineConfigWithVueTs(
  {
    ignores: [
      'vendor/**',
      'node_modules/**',
      'public/**',
      'storage/**',
      'bootstrap/**',
      // Playwright output (git-ignored)
      'playwright-report/**',
      'test-results/**',
      'blob-report/**',
    ],
  },
  vue.configs['flat/recommended'],
  vueTsConfigs.recommended,
  {
    // Inertia page components are resolved by file name, so single-word names (e.g. "Welcome") are intentional.
    files: ['resources/js/Pages/**/*.vue'],
    rules: {
      'vue/multi-word-component-names': 'off',
    },
  },
  prettier,
);
