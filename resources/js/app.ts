import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createApp, h, type DefineComponent } from 'vue';
import { initTheme } from '@/Composables/useTheme';
import { syncDocumentLocale } from '@/i18n';

const appName = import.meta.env.VITE_APP_NAME || 'SN3';

initTheme();

void createInertiaApp({
  title: (title) => (title ? `${title} · ${appName}` : appName),
  resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob<DefineComponent>('./Pages/**/*.vue')),
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) })
      .use(plugin)
      .mount(el);

    syncDocumentLocale();
  },
  progress: {
    color: 'var(--primary)',
  },
});
