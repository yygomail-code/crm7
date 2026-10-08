# Как работать над CRM7 (для разработчиков)

Быстрый вход и команды — в [`AGENTS.md`](AGENTS.md); устройство системы — в
[`docs/architecture.md`](docs/architecture.md); тесты — в [`docs/testing.md`](docs/testing.md);
дизайн-система — в [`docs/ui.md`](docs/ui.md).

## Роли и источник истины
- **Исходный код** — GitHub: `https://github.com/yygomail-code/crm7` (ветка `master`).
  Это единственный источник истины. Локальные «копии-в-стороне» не ведутся.
- **Стенд для проверки** — `test.crm7.local` (доступен **только из локальной сети** сервера).
- Окружения: `test.crm7.local` (общий тест), `k.crm7.local` (dev клиента «Камень»),
  `demo.crm7.local` (демо). Публичные домены — после DNS/проброса.

## Первый раз
```bash
git clone https://github.com/yygomail-code/crm7.git
cd crm7
```

## Локальный запуск
Фронтенд (Svelte + Vite):
```bash
cd frontend
npm ci
npm run dev        # http://localhost:5173
```
API (PHP 8.2, БД MySQL/MariaDB):
```bash
cd api
cp config/.env.example config/.env   # укажите DB_* локально
php -S 127.0.0.1:8080 -t public public/router.php
php bin/migrate.php
php bin/seed-dev.php --password=<локальный пароль>
```

## Ветки и PR
1. Ветка от `master`: `feature/<кратко>` или `fix/<кратко>`.
2. Коммиты — осмысленные, на русском или английском, в стиле репозитория.
3. Push ветки → **Pull Request** в `master`.
4. CI (GitHub Actions) должен быть зелёным: `svelte-check`, `vitest`, `php -l`.
5. Ревью и merge — после прохождения CI. Прямой push в `master` не делаем.

## Что проверять локально перед PR
```bash
cd frontend && npm run check && npm test
find api -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Изменения БД
Только через миграции: новый файл `api/db/migrations/NNN_*.sql`. Уже применённые
файлы не редактируются. Правила и примеры — `docs/migrations.md`. На `k`/прод
сиды не запускаются; `seed-demo.php --force` — только для `demo`.

## Сборка и выкладка
Образы и версии — `docs/images.md`. Кратко:
```bash
python deploy/build_images.py --push                 # собрать и запушить (GHCR)
python deploy/release_crm7.py --env test --tag <ver> # раскатать на стенд
```
На `test` деплой также можно запустить из GitHub Actions (workflow «Deploy test»)
через self-hosted runner на dev-сервере.

## Правила
- Секреты и `.env` в репозиторий не коммитим.
- Данные клиентов/демо живут в томах сервера, в образ и репозиторий не попадают.
- Перед выкладкой на `k`/прод — бэкап БД (см. `docs/migrations.md`).
