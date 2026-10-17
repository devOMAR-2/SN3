import type { Messages } from '../en';

export default {
  team_archived: {
    title: 'هذا الفريق مؤرشف',
    body: 'كل ما فيه للقراءة فقط حتى يلغي المالك أرشفته.',
  },
  project_archived: {
    title: 'هذا المشروع مؤرشف',
    body: 'المشروع للقراءة فقط حتى يلغي المالك أو مدير المشروع أرشفته.',
  },
  read_only: {
    title: 'أصبح هذا للقراءة فقط',
    body: 'تمت أرشفة المشروع أو فريقه، ولم يُحفظ تغييرك.',
  },
} satisfies Messages['lock'];
