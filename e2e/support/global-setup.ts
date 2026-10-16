import { execFileSync } from 'node:child_process';

import { E2E_DB_DATABASE, e2eEnv } from './env';

// Rebuilds the E2E database from scratch before every run, so results never depend on leftovers.
//
// `migrate:fresh` drops every table, so before running it we ask the application which database
// and environment it really resolves to with the E2E settings. A `DB_URL` in .env or a cached
// config (`php artisan config:cache` / `optimize`) would otherwise point the reset at the
// developer's own database.
export default function globalSetup(): void {
  if (process.env.E2E_SKIP_DB_RESET === '1') {
    return;
  }

  if (!E2E_DB_DATABASE.includes('e2e')) {
    throw new Error(`Refusing to reset "${E2E_DB_DATABASE}": the E2E database name must contain "e2e".`);
  }

  const expected = `${E2E_DB_DATABASE}|e2e`;
  const resolved = execFileSync(
    'php',
    ['artisan', 'tinker', '--execute', "echo DB::connection()->getDatabaseName().'|'.app()->environment();"],
    { env: e2eEnv(), encoding: 'utf8' },
  ).trim();

  if (resolved !== expected) {
    throw new Error(
      `Refusing to reset: the app resolves to "${resolved}", expected "${expected}". ` +
        'Clear a cached config with `php artisan config:clear`, and check DB_URL / DB_* in .env.',
    );
  }

  execFileSync(
    'php',
    [
      'artisan',
      'migrate:fresh',
      '--seed',
      '--seeder=Database\\Seeders\\E2ETenantSeeder',
      '--force',
      '--no-interaction',
    ],
    { env: e2eEnv(), stdio: 'inherit' },
  );
}
