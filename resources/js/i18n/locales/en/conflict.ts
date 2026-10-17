// Concurrent modification (412 version_mismatch; DESIGN_SYSTEM.md §9.7, API_SPEC.md §14.3).
export default {
  title: 'Changed by someone else',
  task: 'This task was changed after you opened it, so your last change was not saved. Reload to see the latest version, then apply your change again. Text you are still editing is kept.',
  project:
    'This project was changed after you opened the page, so your change was not saved. Reload to see the latest version.',
  reload: 'Reload',
};
