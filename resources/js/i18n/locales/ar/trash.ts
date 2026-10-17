import type { Messages } from '../en';

export default {
  deleted_teams: 'الفرق المحذوفة',
  teams_hint: 'فرق تملكها موجودة في سلة المحذوفات. استعد أيًا منها خلال 30 يومًا من حذفه.',
  deleted_projects: 'المشاريع المحذوفة',
  projects_hint: 'مشاريع في سلة المحذوفات يمكنك استعادتها خلال 30 يومًا من حذفها.',
  recoverable_until: 'يمكن استعادته حتى {date}',
  restore: 'استعادة',
} satisfies Messages['trash'];
