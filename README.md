# ForumHub (new-forum)

Лёгкий форум на чистом PHP + MySQL. Без фреймворков и сборщиков.

## Требования

- **PHP >= 8.0** с расширениями: `pdo_mysql`, `mbstring`, `fileinfo`, `gd`
- **MySQL >= 8.0.14** (LATERAL в index.php, FULLTEXT WITH PARSER ngram, оконные функции в поиске)
- **MariaDB не поддерживается** (нет LATERAL/ngram)
- Apache с `AllowOverride All` **или** nginx (см. ниже)
- Опционально: `apcu` (кэш настроек/статистики), `opcache` (настоятельно рекомендуется)

## Установка

1. Создайте БД `utf8mb4` и импортируйте `database.sql`.
2. Скопируйте `config/database.php`, впишите креды БД, `APP_TIMEZONE`,
   при необходимости `TRUSTED_PROXIES` и `FORCE_SECURE_COOKIES`.
   На проде `APP_DEBUG = false`.
3. Создайте первого администратора:
   ```bash
   php install/create_admin.php admin you@example.com 'StrongPass_1'
