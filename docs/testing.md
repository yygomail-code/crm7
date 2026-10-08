# Тестирование CRM7

## Что где

| Проверка | Где | Команда |
| --- | --- | --- |
| Типы и разметка Svelte | `frontend` | `npm run check` (`svelte-check`) |
| Юнит-тесты | `frontend/src/**/*.test.ts` | `npm test` (`vitest run`) |
| Сборка | `frontend` | `npm run build` |
| Синтаксис PHP | `api/` | `php -l <file>` |
| E2E (браузер) | `frontend/tools/e2e/run.mjs` | `npm run e2e` |
| Скриншоты дизайн-ревью | `frontend/tools/design-review/capture.mjs` | `npm run screenshots` |

Юнит-тестами покрыты утилиты и сторы: `format`, `filters`, `items-sort`, `period`,
`router`, `search-history`, `avatar`, `cart`.

## Локально

```bash
cd frontend
npm run check     # svelte-check (0 errors / 0 warnings — цель)
npm test          # vitest
npm run build     # production-сборка
```

PHP — по изменённым файлам (в CI прогоняется по всем):

```bash
php -l api/src/Stocks/StocksService.php
```

## CI (GitHub Actions)

`.github/workflows/ci.yml`:

- `api:lint` — `php -l` по `api/src`, `api/public`, `api/bin`.
- `frontend:check` — `npm ci && npm run check && npm test`.
- `frontend:build` — `npm run build` (артефакт `frontend/dist`).
- `frontend:e2e` — **вручную** (`when: manual`), требует запущенного стенда.

Отдельный workflow `deploy-test.yml` раскатывает `test` через self-hosted runner (см. [`images.md`](images.md)).

## E2E

Требуется поднятый стенд: API + фронтенд + БД с демо-данными, и системный браузер.

```powershell
# 1. Стенд: php -S ... + npm run dev (см. AGENTS.md / docs/e2e.md)
# 2. Запуск
$env:CRM_BASE_URL = 'http://localhost:5173'
$env:CRM_API_URL = 'http://127.0.0.1:8080/api/v2'
$env:CRM_PASSWORD_FILE = 'C:\path\to\seed_passwords.json'   # {"login":"password"}
npm run e2e
```

Переменные окружения:

| Переменная | По умолчанию | Назначение |
| --- | --- | --- |
| `CRM_BASE_URL` | `http://localhost:5174` | адрес фронтенда (для локального dev — `:5173`) |
| `CRM_API_URL` | `http://127.0.0.1:8080/api/v2` | адрес API v2 |
| `CRM_PASSWORD_FILE` / `CRM_PASSWORD` | — | пароли учётных записей (файл JSON или общий пароль) |
| `CRM_BROWSER_CHANNEL` | `msedge` | канал Playwright (`bundled` — встроенный chromium) |
| `CRM_HEADED` | — | `1` — показать окно браузера |
| `CRM_E2E_ONLY` | — | фильтр сценариев по подстроке имени (через запятую) |

Сценарий — элемент массива в `run.mjs`: `{ name, role, async run(page) { ... } }`.
Логин выполняется через API, проверки — локаторами (`expectVisible`, `assert`). Сценарий
«склад: поиск и сортировка» показывает типовой поток: поиск по Enter + применение сортировки через модалку.

## Дизайн-ревью (скриншоты)

```powershell
$env:CRM_PASSWORD_FILE = '...'
npm run screenshots
```

Переменные: `CRM_BASE_URL`, `CRM_API_URL`, `CRM_PASSWORD_FILE`/`CRM_PASSWORD`,
`CRM_ROLES`, `CRM_VIEWPORTS`, `CRM_SHOTS_DIR`, `CRM_SETTLE_MS`, `CRM_BROWSER_CHANNEL`, `CRM_CAPTURE`.
Результат — в `frontend/artifacts/design-review/` (в git не коммитится).

## Vitest

Настройка — в `frontend/vite.config.ts` (`test.setupFiles: ['./src/test-setup.ts']`).
`test-setup.ts` подставляет in-memory `localStorage`, чтобы сторы тестировались в Node.
Новый юнит-тест — рядом с модулем: `foo.ts` → `foo.test.ts`.
