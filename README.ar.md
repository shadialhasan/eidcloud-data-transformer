[🇸🇦 العربية](README.ar.md) | [🇬🇧 English](README.md)

# eidcloud-data-transformer | المحول الشامل بين صيغ البيانات مع محرك استعلام سريع

[![الإصدار](https://img.shields.io/badge/version-v1.0.0-blue.svg)](https://github.com/shadialhasan/eidcloud-data-transformer/releases)
[![بيئة التشغيل](https://img.shields.io/badge/php-%3E%3D8.2-8892BF.svg)](https://www.php.net)
[![الترخيص: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![فتح في كولاب](https://colab.research.google.com/assets/colab-badge.svg)](https://colab.research.google.com/github/shadialhasan/eidcloud-data-transformer/blob/main/notebooks/quickstart.ipynb)
[![المنظومة](https://img.shields.io/badge/EIDCloud-Ecosystem-green.svg)](https://github.com/shadialhasan/eidcloud-cli)

أداة تحويل ومعالجة عالية الكفاءة بين صيغ CSV و JSON و SQL Inserts و XML مع محرك استعلام وتصفية داخلي بدون قواعد بيانات.


> **☁️ إشعار المنظومة:** هذه الأداة هي جزء مستقل من منظومة **[EIDCloud](https://github.com/shadialhasan/eidcloud-cli)**.  
> يمكنك استخدامها بمفردها كأداة متخصصة، أو إدارتها مع كافة أدوات المنظومة الـ 49 عبر الماستر CLI المركزي: `eidcloud`.


---

## 📑 التصنيف والقطاع
**البيانات، التخزين وقواعد البيانات (Data & Storage)**

---

## ✨ المميزات الرئيسية

- **تحويل تبادلي فوري بين ملفات CSV و JSON و XML و SQL Inserts**
- **لغة استعلام وتصفية سريعة شبيهة بـ SQL على الملفات مباشرة**
- **معالجة الملفات الضخمة بتقنية البث التدفقي (Streams) بدون استهلاك الرام**
- **إصلاح تلقائي للأخطاء الهيكلية الشائعة في البيانات المستوردة**

---

## 🚀 التثبيت والتشغيل السريع

### 1. الاستخدام المستقل (Standalone)
يمكنك استنساخ وتشغيل هذه الأداة بشكل منفصل تماماً:

```bash
git clone https://github.com/shadialhasan/eidcloud-data-transformer.git
cd eidcloud-data-transformer
php tests/run_tests.php
```

### 2. التثبيت كجزء من الماستر CLI الموحد
إذا كان لديك أداة `eidcloud-cli` مثبتة، يمكنك تسجيل هذه الأداة أو تثبيتها بأمر واحد:

```bash
eidcloud plugin add https://github.com/shadialhasan/eidcloud-data-transformer.git
```

---

## 💻 أمثلة الاستخدام عبر سطر الأوامر

```bash
# تشغيل الأداة مباشرة
php bin/eidcloud-data convert input.csv --to=json --out=output.json
php bin/eidcloud-data query data.json "SELECT name, email WHERE status = 'active'"
```

```bash
# عرض المساعدة والدليل الشامل
php bin/eidcloud-data --help
```

---

## 🧪 الاختبارات الآلية

تم تجهيز هذا المستودع بمجموعة اختبارات ذاتية متكاملة تعمل بدون أي مكتبات خارجية:

```bash
php tests/run_tests.php
```

---

## 🌐 تجربة فورية عبر المتصفح (Google Colab)

يمكنك تجربة الأداة مباشرة على سحابة Google بدون الحاجة لتثبيت أي متطلبات محلياً:  
[![Open In Colab](https://colab.research.google.com/assets/colab-badge.svg)](https://colab.research.google.com/github/shadialhasan/eidcloud-data-transformer/blob/main/notebooks/quickstart.ipynb)

---

## 👤 المؤلف والمشرف

**م. محمد شادي الحسن**  
- **الدور:** المدير التقني التنفيذي ومهندس الحلول المؤسسية  
- **البريد الإلكتروني:** [mhd.shadi.alhasan@gmail.com](mailto:mhd.shadi.alhasan@gmail.com)  
- **الهاتف / واتساب:** [+963934005922](tel:+963934005922)  
- **الموقع:** دمشق، سوريا  
- **GitHub:** [shadialhasan](https://github.com/shadialhasan)  

---

## 📄 الرخصة

هذا المشروع مرخص بموجب رخصة MIT - انظر ملف [LICENSE](LICENSE) للتفاصيل.  
حقوق النشر (c) 2026 **م. محمد شادي الحسن**. جميع الحقوق محفوظة.
