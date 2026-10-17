/**
 * The current team's projects in the sidebar (first page of GET /api/v1/teams/{team}/projects), kept per
 * team for the session of the page and invalidated after a project is created, renamed or removed.
 * It only ever holds what the server listed for the user (AccessibleProjects).
 */
import { ref } from 'vue';
import { api } from '@/lib/api';
import type { CursorPage, Project } from '@/types/domain';

const cache = ref<Record<string, Project[]>>({});
const pending = new Map<string, Promise<void>>();

export function invalidateNavProjects(teamId?: string): void {
  if (teamId === undefined) {
    cache.value = {};

    return;
  }

  const next = { ...cache.value };
  delete next[teamId];
  cache.value = next;
}

export function useNavProjects(): {
  load: (teamId: string) => Promise<void>;
  projectsOf: (teamId: string) => Project[];
} {
  async function load(teamId: string): Promise<void> {
    if (cache.value[teamId] !== undefined) {
      return;
    }

    const existing = pending.get(teamId);

    if (existing) {
      return existing;
    }

    const request = api
      .get<CursorPage<Project>>(`/teams/${teamId}/projects`, { params: { limit: 25 } })
      .then((response) => {
        cache.value = { ...cache.value, [teamId]: response.data.data };
      })
      .catch(() => {
        // The sidebar is a convenience; the projects page shows the error with a retry.
      })
      .finally(() => pending.delete(teamId));

    pending.set(teamId, request);

    return request;
  }

  return { load, projectsOf: (teamId) => cache.value[teamId] ?? [] };
}
