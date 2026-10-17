import type { Messages } from '../en';

export default {
  required: 'حقل {attribute} مطلوب.',
  string: 'يجب أن يكون {attribute} نصًا.',
  email: 'أدخل عنوان بريد إلكتروني صالحًا.',
  min: '{attribute} قصير جدًا.',
  max: '{attribute} طويل جدًا.',
  in: 'اختر قيمة صالحة لـ {attribute}.',
  timezone: 'اختر منطقة زمنية صالحة.',
  boolean: 'يجب أن تكون قيمة {attribute} نعم أو لا.',
  unknown_field: 'لا يمكن تغيير {attribute} هنا.',
  regex: 'صيغة {attribute} غير صحيحة.',
  exists: 'اختر قيمة صالحة لـ {attribute}.',
  uuid: 'اختر قيمة صالحة لـ {attribute}.',
  date_format: 'أدخل تاريخًا صحيحًا.',
  after_or_equal: 'تاريخ {attribute} مبكر جدًا.',
  invalid: 'قيمة {attribute} غير صالحة.',
  network_error: 'تعذّر الوصول إلى الخادم. تحقّق من اتصالك وحاول مجددًا.',
} satisfies Messages['validation'];
