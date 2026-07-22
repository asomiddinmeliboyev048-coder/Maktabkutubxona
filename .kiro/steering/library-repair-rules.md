---
inclusion: always
---

# Maktab elektron kutubxonasi — ta'mirlash qoidalari

- Frontendda mavjud maxsus dizayn klasslarini (`book-card`, `book-cover-wrap`, `availability-ribbon`, `header-search-glass` va boshqalar) saqlang. Ularni o'zboshimchalik bilan standart Bootstrap klasslariga almashtirmang.
- `assets/css/style.css` va `assets/css/features.css` loyihaning asosiy vizual tizimi hisoblanadi; UI o'ziga xos, chiroyli va responsiv holatda qolishi kerak.
- Baza o'zgarishlarida foreign key ustunlarining turi, `UNSIGNED` holati va storage engine bir xil ekanini tekshiring. Error 150/1832 uchun foydalanuvchiga phpMyAdmin'da bajariladigan xavfsiz, ketma-ket SQL ko'rsatmalarini va sababni bering.
- Muammoni bosqichma-bosqich tushuntiring; katta kod blokini izohsiz bermang. Har bir tuzatishda avval sababni, so'ng aniq o'zgarishni ko'rsating.
- PHP fayllarda `declare(strict_types=1);`, PDO prepared statements, mavjud `e()` HTML escaping funksiyasi, CSRF va tayanch helperlardan foydalaning.
- SQL injection, XSS, IDOR va rollar aralashib ketishiga yo'l qo'ymang. Admin, librarian va student ruxsat chegaralarini qat'iy saqlang.
- Ma'lumot yo'qotishi mumkin bo'lgan SQL amallaridan oldin backup yarating va orphan yozuvlarni alohida tekshiring.
