/**
 * Browser page URLs (API_SPEC.md §1.1; CORE-006, ADR-026). Team pages use the team slug and the project
 * key; task links use the task's UUID, so renaming a team or project never breaks them (DESIGN_SYSTEM §5.2).
 */
const segment = encodeURIComponent;

export const pages = {
  home: (): string => '/',
  teams: (): string => '/teams',
  newTeam: (): string => '/teams/new',
  team: (slug: string): string => `/t/${segment(slug)}`,
  teamMembers: (slug: string): string => `/t/${segment(slug)}/members`,
  teamSettings: (slug: string): string => `/t/${segment(slug)}/settings`,
  projects: (slug: string): string => `/t/${segment(slug)}/projects`,
  newProject: (slug: string, onboarding = false): string =>
    `/t/${segment(slug)}/projects/new${onboarding ? '?onboarding=1' : ''}`,
  project: (slug: string, key: string): string => `/t/${segment(slug)}/p/${segment(key)}`,
  projectSettings: (slug: string, key: string): string => `/t/${segment(slug)}/p/${segment(key)}/settings`,
  task: (id: string): string => `/tasks/${segment(id)}`,
  verifyEmail: (): string => '/verify-email',
  profile: (): string => '/settings',
};
