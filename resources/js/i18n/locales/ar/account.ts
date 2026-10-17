import type { Messages } from '../en';

export default {
  home: {
    title: 'الرئيسية',
    greeting: 'أهلًا، {name}',
    settings_link: 'إعدادات الملف الشخصي',
    unverified: 'أكّد بريدك الإلكتروني لتتمكن من إنشاء فريق أو الانضمام إليه.',
    verify_link: 'التأكيد الآن',
  },
  settings: {
    title: 'إعدادات الملف الشخصي',
    saved: 'تم حفظ إعداداتك.',
    submit: 'حفظ التغييرات',
  },
  fields: {
    name: 'الاسم المعروض',
    locale: 'اللغة',
    timezone: 'المنطقة الزمنية',
    theme: 'المظهر',
  },
  errors: {
    name: {
      max: 'استخدم 100 حرف على الأكثر.',
    },
  },
} satisfies Messages['account'];
