---
inclusion: always
---

# Maktab elektron kutubxonasi — ta'mirlash qoidalari

- Frontendda mavjud maxsus dizayn klasslarini (`book-card`, `book-cover-wrap`, `availability-ribbon`, `header-search-glass` va boshqalar) saqlang. Ularni o'zboshimchalik bilan standart Bootstrap klasslariga almashtirmang.
- `assets/css/style.css` va `assets/css/features.css` loyihaning asosiy vizual tizimi hisoblanadi; UI o'ziga xos, chiroyli va responsiv holatda qolishi kerak.
- Baza o'zgarishlarida foreign key ustunlarining turi, `UNSIGNED` holati va storage engine bir xil ekanini tekshiring. Error 150/1832 uchun foydalanuvchiga phpMyAdmin'da bajariladigan xavfsiz, ketma-ket SQL ko'rsatmalarini va sababni bering.
- Muammoni bosqichma-bosqich tushuntiring; katta kod blokini izohsiz bermang. Har bir tuzatishda avval sababni, so'ng aniq o'zgarishni ko'rsating.
- PHP fayllarda `declare(strict_types=1);`, PDO prepared statements, mavjud `e()` HTML escaping funksiyasi, CSRF va tayanch helperlardan foydalaning.
- SQL injection, XSS, IDOR va rollar aralashib ketishiga yo'l qo'ymang. `admin`, `librarian` (seller) va `student` ruxsat chegaralarini qat'iy saqlang.
- Ma'lumot yo'qotishi mumkin bo'lgan SQL amallaridan oldin backup yarating va orphan yozuvlarni alohida tekshiring.

## Marketplace va media arxitekturasi

- `librarian` rolini sotuvchi sifatida qo'llang; yangi parallel `seller` roli kiritilsa, avval barcha auth, ENUM, migratsiya va dashboard oqimlarini bir xil semantikaga xavfsiz ko'chiring. Bir vaqtning o'zida chalkash ikki seller rolini ishlatmang.
- Super Admin `admin/` ichida foydalanuvchilar, sotuvchilar, kitoblar, so'rovlar va moderatsiyani boshqaradi. Ochiq navigatsiyada admin panel havolasini ko'rsatish shart emas; server-side `require_role($pdo, 'admin')` tekshiruvi har doim majburiy.
- Asosiy admin akkaunti bazaga seed qilinsa, parol hech qachon ochiq matnda saqlanmasin: faqat `password_hash()` bilan yaratilgan hash `password_hash` ustuniga yozilsin. Login `password_verify()` orqali tekshirilsin.
- Sotuvchi dashboardida jami, faol sotuv/ijara e'lonlari, sotilgan/yakunlangan kitoblar va xaridor so'rovlari bazadagi aniq statuslar orqali hisoblanishi kerak.
- Xaridor so'rovlari kitob egasiga bog'lansin; har bir ko'rish yoki mutatsiyada ownership tekshiruvi, CSRF va ruxsat etilgan status transition ishlatilsin.
- eBook fayllari uchun PDF kabi aniq allowlist, server-side MIME tekshiruvi, hajm chegarasi, tasodifiy fayl nomi va bevosita bajarilmaydigan upload katalogi ishlatilsin. Faqat ruxsat berilgan yoki ochiq kitoblarni o'qish endpointi faylni uzatsin.
- Video uchun avval xavfsiz HTTPS havolasi va ruxsat etilgan provider identifikatori afzal. Upload qo'llansa, MIME/hajm allowlist, tasodifiy nom va executable fayllarni bloklash majburiy.
- Sharh va reytinglarda autentifikatsiya, kitob mavjudligi, 1–5 reyting validatsiyasi, uzunlik chegarasi, prepared statement va chiqishda `e()` ishlatilsin.

## PHP versiya mosligi

- Minimal target PHP 7.4 bo'lsin va kod PHP 8.x da ham ishlasin. `match`, `str_starts_with`, `str_ends_with` va faqat PHP 8 ga tegishli sintaksisdan foydalanmang; mos `switch` yoki xavfsiz helper/polyfill qo'llang.
- Har bir PHP entry fayli `<?php` dan keyin `declare(strict_types=1);` bilan boshlansin. PHP lint barcha o'zgargan fayllarda bajarilsin.
- Schema va kod o'zgarishlarini bosqichlarga ajrating: avval backup va repeatable migratsiya, keyin auth/rollar, so'ng seller requests/statuslar, undan keyin eBook/video reader va yakunda UI integratsiyasi.
