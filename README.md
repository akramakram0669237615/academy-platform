# AcademyBuilder — Plain PHP Edition

نسخة مستقلة من المشروع بدون Laravel أو Composer. تعمل على PHP 8.3 + Apache + PostgreSQL.

## التشغيل
1. انسخ `.env.example` إلى `.env` وعدّل بيانات PostgreSQL.
2. أنشئ قاعدة البيانات ثم نفّذ `database.sql`.
3. شغّل Docker: `docker compose up -d --build`.
4. افتح `http://localhost:8080`.

## API
- `GET /api/v1/health`
- `POST /api/v1/auth/register`
- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `POST /api/v1/auth/logout`
- `GET /api/v1/academies/{id}/courses`
- `GET /api/v1/academies/{id}/courses/{course}`
- `POST /api/v1/academies/{id}/courses` (manager)
- `PUT /api/v1/academies/{id}/courses/{course}` (manager)
- `DELETE /api/v1/academies/{id}/courses/{course}` (manager)
- `POST /api/v1/academies/{id}/courses/{course}/enroll`
- `GET /api/v1/academies/{id}/courses/{course}/learn`
- `POST /api/v1/academies/{id}/courses/{course}/progress`
- `POST /api/v1/academies/{id}/checkout`
- `GET /api/v1/academies/{id}/orders`
- `GET /api/v1/academies/{id}/certificates`

## ملاحظات
هذه النسخة تستبدل Laravel بطبقة PDO وRouter بسيطة. قاعدة البيانات تشمل جداول الأكاديميات والكورسات والدروس والتسجيل والتقدم والفيديو والتجارة والكوبونات والاختبارات والشهادات والمراجعات والسجلات. الدفع الافتراضي Fake للتجربة؛ يمكن إضافة Stripe عبر API مباشر بدون SDK.
