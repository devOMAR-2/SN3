import type { Messages } from '../en';

export default {
  tasks: 'لا توجد مهام حتى الآن',
  tasks_writer: 'أضف أول مهمة من الأعلى.',
  members: 'لا يوجد أعضاء.',
  teams: {
    title: 'لا توجد فرق بعد',
    body: 'أنشئ فريقًا، أو اطلب من أحدهم دعوتك إلى فريقه.',
  },
  projects: {
    title: 'لا توجد مشاريع بعد',
    body: 'ستظهر هنا المشاريع التي يمكنك رؤيتها في هذا الفريق.',
    body_creator: 'أنشئ أول مشروع لهذا الفريق.',
  },
} satisfies Messages['empty'];
