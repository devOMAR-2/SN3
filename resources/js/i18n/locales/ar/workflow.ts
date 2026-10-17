import type { Messages } from '../en';

export default {
  title: 'سير العمل',
  help: 'الحالات هي الأعمدة التي تمر بها المهام، بهذا الترتيب.',
  default: 'تبدأ المهام الجديدة هنا',
  add: 'إضافة حالة',
  added: 'تمت إضافة الحالة «{name}».',
  new_name: 'حالة جديدة',
  edit: 'تعديل الحالة {name}',
  delete: 'حذف الحالة {name}',
  delete_body: 'تنتقل مهام هذه الحالة إلى الحالة البديلة، ولا يمكن حذف حالة فيها مهام دون بديل.',
  delete_submit: 'حذف الحالة',
  deleted: 'تم حذف الحالة «{name}».',
  replacement: 'نقل مهامها إلى',
  replacement_hint: 'مطلوب إن كانت في الحالة مهام.',
  no_replacement: 'بلا بديل (الحالة فارغة)',
  move_up: 'تحريك {name} للأعلى',
  move_down: 'تحريك {name} للأسفل',
  moved: 'نُقلت {name} إلى الموضع {position} من {total}.',
  conflict: 'تغيّر سير العمل في هذه الأثناء. تعرض القائمة الآن أحدث نسخة؛ راجعها ثم حاول مرة أخرى.',
  categories: {
    todo: 'للإنجاز',
    in_progress: 'قيد التنفيذ',
    done: 'مكتمل',
  },
  fields: {
    name: 'اسم الحالة',
    category: 'الفئة',
  },
} satisfies Messages['workflow'];
