# AGENTS.md — CRM7

Короткая карта для агентов и новых сессий. Подробности — в `docs/` (см. ссылки в конце).

## Что это

CRM для заявок клиентов: склад с ценами и корзиной, чат, отчёты, админка.

- Фронтенд: Svelte 5 (runes) + Vite 7 + TypeScript, hash-роутинг.
- Бэкенд: PHP 8.2+ (собственный REST API v2 без фреймворка) + MySQL/MariaDB.
- Источник истины — этот репозиторий (GitHub `yygomail-code/crm7`, ветка `master`).

## Структура

- `api/public/index.php` — точка входа API и все роуты; `api/public/router.php` — для `php -S`.
- `api/src/Controllers` → `api/src/<Домен>/*Service` → `api/src/Repositories` — слои API.
- `api/src/Core`, `api/src/Http`, `api/src/Support` — инфраструктура (Config, Database, Router, Storage, Crypto…).
- `api/db/migrations/` — нумерованные SQL-миграции (только добавлять новые).
- `api/bin/` — `migrate.php`, `seed-dev.php`, `seed-demo.php`, `cron.php`.
- `frontend/src/views/` — страницы; `frontend/src/lib/api/` — клиент API; `frontend/src/lib/stores/` — сторы;
  `frontend/src/lib/components/` — компоненты; `frontend/src/App.svelte` + `lib/router.svelte.ts` — роутинг.
- `deploy/` — `Dockerfile.app`, `Dockerfile.web`, `nginx.conf`, `build_images.py`, `release_crm7.py`.
- `docs/` — architecture, testing, ui, deploy, images, migrations, data-import, e2e, design-review, plan (исторический).

## Локальный стенд

Нужны PHP 8.2+ (`pdo_mysql`, `mbstring`, `openssl`, `gd`), MySQL/MariaDB, Node 22+.

```powershell
# API (из корня репозитория)
php -S 127.0.0.1:8080 -d upload_max_filesize=12M -d post_max_size=12M -t api/public api/public/router.php
#   -> http://127.0.0.1:8080/api/v2

# Фронтенд
cd frontend; npm install; npm run dev
#   -> http://localhost:5173  (Vite проксирует /api -> 127.0.0.1:8080)
```

БД: скопировать `api/config/.env.example` → `api/config/.env`, задать `DB_*` и `APP_KEY` (64 hex), затем:

```bash
php api/bin/migrate.php
php api/bin/seed-dev.php --password=<пароль>   # базовые учётные записи
php api/bin/seed-demo.php --force              # демо-данные (только для demo/dev)
```

## Проверки перед коммитом

```bash
cd frontend && npm run check && npm test && npm run build
php -l api/src/.../File.php     # по изменённым PHP-файлам
```

CI (GitHub Actions) гоняет `php -l`, `svelte-check`, `vitest`, `vite build`; e2e — вручную.

## Конвенции

- Миграции — новый файл `api/db/migrations/NNN_*.sql`; применённые не редактировать (см. `docs/migrations.md`).
- Секреты, `.env`, дампы — не коммитить. Данные клиента/демо живут в томах, в образ и репозиторий не попадают.
- Коммиты — в стиле репозитория: `CRM7: <область> — <суть>` (русский).
- UI — Ant Design: токены в `frontend/src/app.css`, иконки через `Icon.svelte` (см. `docs/ui.md`).
- `k` — реальные данные клиента: сиды не запускать, изменения только адресно (см. `docs/data-import.md`).

## Грабли

- Vite по умолчанию слушает `localhost` (на Windows это `::1`) — проверяй `http://localhost:5173`, не `127.0.0.1`.
- Адрес API фронта — относительный `./api/v2`; переопределяется `window.CRM_CONFIG` или `VITE_API_V2_BASE`.
- Файлы (`storage/`) и БД — в томах и не попадают в образ; пересборка/релиз их не затрагивает.
- Сборка образов и релиз идут через SSH к серверу: `python deploy/build_images.py [--push]`,
  `python deploy/release_crm7.py --env <env|all> --tag <ver>`.

## Документация

- [`docs/architecture.md`](docs/architecture.md) — устройство системы, слои, данные, окружения.
- [`docs/testing.md`](docs/testing.md) — тесты, e2e, дизайн-ревью.
- [`docs/ui.md`](docs/ui.md) — дизайн-система (Ant), токены, компоненты.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — процесс, ветки, PR, правила.
- [`docs/deploy.md`](docs/deploy.md), [`docs/images.md`](docs/images.md), [`docs/migrations.md`](docs/migrations.md).
