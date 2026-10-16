import type { PageProps } from '@inertiajs/core';
import type { Direction, Locale } from '@/i18n';

/**
 * Props shared with every Inertia page by App\Http\Middleware\HandleInertiaRequests::share().
 * Add new shared props here (or augment this interface from another module) when the server shares them.
 */
export interface SharedProps extends PageProps {
  locale: Locale;
  dir: Direction;
}

declare module '@inertiajs/core' {
  interface InertiaConfig {
    sharedPageProps: SharedProps;
  }
}
