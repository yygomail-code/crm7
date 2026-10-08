# Архитектура CRM7

Карта устройства системы: слои, поток запроса, данные, окружения. Актуальна на момент
последних изменений; при смене entry point или инварианта — обновляйте вместе с кодом.

## Обзор

- **Фронтенд** — SPA на Svelte 5 (runes), hash-роутинг, общается с REST API v2.
- **Бэкенд** — собственный REST API без фреймворка: Router → Controller → Service → Repository → PDO.
- **БД** — MySQL 8 / MariaDB 10.5+ (utf8mb4).
- Код поставляется как образы (GHCR), данные живут в томах (см. «Окружения»).

## Поток запроса (бэкенд)

Точка входа — `api/public/index.php` (префикс API — `API_BASE_PATH`, по умолчанию `/api/v2`):

1. Autoload `App\` (`api/src/autoload.php`), загрузка конфига (`Config::load(config/.env)`).
2. `Router` сопоставляет HTTP-метод и путь с парой `[Controller, action]`.
3. `ApiController::context()` аутентифицирует по Bearer-токену и собирает capabilities пользователя.
4. Controller валидирует вход и вызывает Service.
5. Service — бизнес-логика, обращается к Repository.
6. Repository — SQL через `Database::pdo()`.
7. Ответ — `Response::ok($data)` (JSON `{ "ok": true, "data": ... }`); ошибки — `HttpException` → JSON с кодом.

### Слои

| Слой | Назначение |
| --- | --- |
| `Core/` | `Config`, `Database`, `Router`, `RateLimiter`, `Logger`, `DatabaseSettings` |
| `Http/` | `Request`, `Response`, `Router`, `HttpException`, `FileResponse`, `DownloadResponse` |
| `Controllers/` | Тонкие контроллеры по домену (Auth, Request, Stocks, Chat, Client, Report, Admin…) |
| `<Домен>/*Service.php` | Бизнес-логика (Requests, Stocks, Prices, Chat, Clients, Users, Reports…) |
| `Repositories/` | Доступ к БД (по одной таблице/агрегату) |
| `Support/` | `Storage` (файлы), `Crypto` (AES-256-GCM), `SmtpMailer`, `Validator` |

Роуты и контроллеры сгруппированы по префиксам: `auth`, `profile`, `requests`,
`request-drafts`, `clients`, `chat`, `stocks`, `reports`, `substitutions`,
`notifications`, `admin`, `settings`, `legal`, `users`, `preferences`.

## Аутентификация и права

- Логин (`POST /auth/login`) → токен в БД (`TokenRepository`); далее заголовок `Authorization: Bearer <token>`.
- Уровни: **90** сисадмин, **50** администратор, **10** менеджер, **5** клиент, **1** гость.
- Матрица прав — таблица `level_capabilities` (код → уровень). На фронте проверка `auth.can('code')`.
- Защита входа от перебора — `RateLimiter` + таблица попыток по IP (`login_attempts`).
- Пароли — `password_hash(..., PASSWORD_DEFAULT)`; сброс админом генерирует пароль `bin2hex(random_bytes(5)).'Aa1'`.

## Фронтенд

- `main.ts` → `App.svelte`: выбирает представление по `router.current.path`.
- Роуты — `frontend/src/App.svelte` (в т.ч. параметрические: `/requests/:id`, `/stocks/items/:id/edit`).
- `lib/router.svelte.ts` — hash-роутер (`$state`); `lib/match-route.ts` — разбор параметров пути.
- `lib/api/*.ts` — типизированные обёртки над API (`apiRequest`, Bearer, ошибки `ApiError`).
- `lib/stores/*.svelte.ts` — реактивные сторы: `auth`, `cart`, `app-settings`, `avatar`, `filter-prefs`.
- `lib/components/` — UI: `ui/` (Button, Modal, Input, Icon, SearchInput…), `layout/` (AppShell),
  `requests/`, `stocks/` (PhotoGallery); `lib/actions/tooltip.ts` — тултипы.
- `lib/config.ts` — адрес API (`window.CRM_CONFIG` / `VITE_API_V2_BASE`, по умолчанию `./api/v2`) и интервалы polling.

## Данные (ключевые таблицы)

- **Пользователи/права:** `users` (`LOGIN`, `PASSWORD`, `LEVEL`, `ACTIVE`, `SID`), `level_capabilities`, `tokens`, `user_history`, `login_attempts`.
- **Заявки:** `requests`, `request_items`, `request_statuses`, `request_assignments`, `request_history`, `request_activities`, `request_comments`, `request_drafts`.
- **Склад:** `stocks` (склады), `stock_levels` (остатки), `nomenclature` (`SID`, `NAME`, `NAME_1C`, `UNIT`, `DESCRIPTION`, `group_id`), `nomenclature_photos` (`storage_path`/`card_path`/`preview_path`, `mime`, `size`, `width`, `height`), `nomenclature_prices` (`name_sid`, `stock_sid`, `price_type_id`, `price`), `price_types`, `price_import_mappings`, `item_groups`.
- **Клиенты/чат/прочее:** `clients`, `client_managers`, `chat_threads`/`chat_messages`, `notifications`, `settings`, `audit`, `schema_migrations`.

### Инварианты

- Остаток и цена привязаны к паре `(STOCK_SID, NAME_SID)`; цены — по типам и **по каждому складу отдельно**.
- Импорт остатков **не удаляет и не перезаписывает** номенклатуру: отсутствующие в файле позиции обнуляются (не удаляются); новые склады добавляются; новая позиция создаётся на всех складах (0 там, где количества нет в файле); настройки позиции (описание, группа, единица, фото) сохраняются.
- Фото хранятся в `storage/` (том) в трёх вариантах: `storage_path` (максимум), `card_path` (карточка), `preview_path` (превью); в БД — метаданные (`mime`, `size`, `width`, `height`). Размеры вариантов — в настройках (`stocks.photo_size_preview/card/max`); при загрузке изображение уменьшается без увеличения (GD).
- Миграции инкрементальные (`schema_migrations`); применённые файлы не редактируются.

## Окружения и выкладка

- Код — образы в GHCR (`ghcr.io/yygomail-code/crm7-app`, `.../crm7-web`); данные — в томах.
- Окружения: `demo` (демо), `test` (общий тест), `k` (dev клиента «Камень»).
- Структура окружения: `/srv/projects/crm7-<env>/` — `docker-compose.yml`, `.env` (`CRM7_VERSION`, `DB_*`),
  тома `config/`, `storage/`, `var/`, `data/db/`.
- Сборка: `python deploy/build_images.py [--push]` — frontend build → SFTP контекста → `docker build` на сервере.
- Релиз: `python deploy/release_crm7.py --env <env|all> --tag <ver>` — версия в `.env` → pull → `migrate.php` → `up -d` → health.

Подробности: [`images.md`](images.md), [`deploy.md`](deploy.md), [`data-import.md`](data-import.md).

## Как добавить…

- **Эндпоинт:** роут в `api/public/index.php` → метод в `Controllers/<Domain>Controller.php` →
  метод в `<Домен>/*Service.php` → (при необходимости) метод в `Repositories/`; при изменении схемы — миграция.
- **Страницу:** `frontend/src/views/<Page>.svelte`, роут в `App.svelte`, обёртка API в `lib/api/`.
- **Миграцию:** `api/db/migrations/NNN_*.sql` (только `CREATE`/`ADD`, без `DROP`/`DELETE`; см. [`migrations.md`](migrations.md)).
