import { execFileSync } from 'node:child_process';

import { E2E_DB_DATABASE, e2eEnv } from './env';

// Rebuilds the E2E database from scratch before every run, so results never depend on leftovers.
// The database must be dedicated to E2E: refusing the developer's own database name is a guard
// against wiping it by accident.
export default function globalSetup(): void {
  if (process.env.E2E_SKIP_DB_RESET === '1') {
    return;
  }

  if (!E2E_DB_DATABASE.includes('e2e')) {
    throw new Error(`Refusing to reset "${E2E_DB_DATABASE}": the E2E database name must contain "e2e".`);
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
