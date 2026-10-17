import { describe, expect, it } from 'vitest';
import { hasTranslation, locales } from './index';

// Every static `t('…')` key used by a component, page, composable or helper exists in Arabic and English
// (DS-ACC-009). Dynamic keys (template literals) are checked where their values are finite, below.
const sources = import.meta.glob(['../**/*.vue', '../**/*.ts', '!../**/*.test.ts', '!./locales/**'], {
  query: '?raw',
  import: 'default',
  eager: true,
}) as Record<string, string>;

const staticKeys = new Set<string>();

for (const source of Object.values(sources)) {
  for (const match of source.matchAll(/\bt\(\s*'([a-z_]+(?:\.[a-z0-9_]+)+)'/g)) {
    staticKeys.add(match[1]!);
  }
}

const dynamicKeys = [
  ...['owner', 'admin', 'member', 'viewer'].map((role) => `team.roles.${role}`),
  ...['manager', 'contributor'].map((role) => `project.roles.${role}`),
  ...['team', 'private'].flatMap((v) => [`project.visibility.${v}`, `project.visibility_help.${v}`]),
  ...['none', 'low', 'medium', 'high', 'urgent'].map((p) => `task.priorities.${p}`),
  ...['todo', 'in_progress', 'done'].map((c) => `workflow.categories.${c}`),
  ...['team_archived', 'project_archived', 'read_only'].flatMap((l) => [`lock.${l}.title`, `lock.${l}.body`]),
  ...['403', '404', '419', '429', '500', '503'].flatMap((s) => [`error.page.${s}.title`, `error.page.${s}.body`]),
  ...['failed', 'expired'].map((s) => `team.export.${s}`),
];

describe('translation catalogue', () => {
  it('finds the keys used in the source', () => {
    expect(staticKeys.size).toBeGreaterThan(200);
  });

  it.each(locales)('has every used key in %s', (locale) => {
    const missing = [...staticKeys, ...dynamicKeys].filter((key) => !hasTranslation(locale, key));

    expect(missing).toEqual([]);
  });
});
