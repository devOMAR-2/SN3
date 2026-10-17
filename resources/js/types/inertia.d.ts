import type { PageProps } from '@inertiajs/core';
import type { ThemePreference } from '@/Composables/useTheme';
import type { Direction, Locale } from '@/i18n';
import type { WorkspaceProps } from '@/types/domain';

/** The signed-in user's own profile (App\Http\Resources\MeResource). */
export interface AuthUser {
  id: string;
  name: string;
  email: string;
  email_verified: boolean;
  email_verified_at: string | null;
  locale: Locale;
  timezone: string;
  theme: ThemePreference;
  created_at: string | null;
}

/**
 * Props shared with every Inertia page by App\Http\Middleware\HandleInertiaRequests::share().
 * Add new shared props here (or augment this interface from another module) when the server shares them.
 */
export interface SharedProps extends PageProps {
  locale: Locale;
  dir: Direction;
  auth: { user: AuthUser | null };
  flash: { status: string | null };
  features: { registration: boolean };
  /** CORE-006: the signed-in user's teams for the team switcher; null for guests. */
  workspace: WorkspaceProps | null;
}

declare module '@inertiajs/core' {
  interface InertiaConfig {
    sharedPageProps: SharedProps;
  }
}
