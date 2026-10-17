import type { Messages } from '../en';

export default {
  title: 'قائمة التحقق',
  empty: 'لا توجد عناصر في قائمة التحقق.',
  progress: 'أُنجز {done} من {total}',
  done: '(منجز)',
  add: 'إضافة',
  add_label: 'عنصر جديد في قائمة التحقق',
  edit_label: 'عنصر قائمة التحقق',
  edit: 'تعديل {text}',
  delete: 'حذف {text}',
  move_up: 'تحريك {text} للأعلى',
  move_down: 'تحريك {text} للأسفل',
  moved: 'نُقل {text} إلى الموضع {position} من {total}.',
  fields: {
    text: 'عنصر قائمة التحقق',
  },
} satisfies Messages['checklist'];
