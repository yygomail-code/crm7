# Образы и версии CRM7

Код поставляется как **образы** в реестре, данные живут в томах и в образ не
попадают. Обновление на сервере = смена версии + перезапуск, без копирования
файлов.

## Что в образе, а что нет

| В образе (код) | Вне образа (данные/конфиг, тома) |
|---|---|
| `api/` (PHP) | `config/.env`, `config/database.json` |
| `frontend/dist` | `storage/` (вложения) |
| расширения PHP, `nginx.conf` | `var/` (логи, очереди) |
| | `data/db` (MariaDB) |

`.dockerignore` исключает `api/config/.env`, `database.json`, `api/var`,
`api/storage`, `api/db/backups` — в образ они не попадают. **Данные клиентов,
демо и тестов в реестр не уезжают.**

## Реестр
Приватный **GHCR**: `ghcr.io/yygomail-code/crm7-app` и `.../crm7-web`.
Доступ — по GitHub PAT (scope `write:packages` для пуша, `read:packages` для
пула). Образы приватные.

```bash
# на сервере (для сборки/пула) — один раз, токен не хранить в репозитории:
echo "$GHCR_TOKEN" | docker login ghcr.io -u <github-user> --password-stdin
```

## Сборка и публикация
```bash
python deploy/build_images.py            # собрать на dev-сервере (без пуша)
python deploy/build_images.py --push     # собрать и запушить в GHCR
```
Теги: `<version>` (из `frontend/package.json`) и `sha-<git-commit>`.

## Релиз на окружения
```bash
python deploy/release_crm7.py --env all  --tag 1.4.0
python deploy/release_crm7.py --env test --tag 1.4.0
```
Шаги для каждого окружения: `CRM7_VERSION=<tag>` в `.env` → `docker compose pull`
→ миграции (`migrate.php`) → `docker compose up -d` → проверка health. Тома с
данными не трогаются.

**Откат** — релиз предыдущего тега:
```bash
python deploy/release_crm7.py --env k --tag sha-<old-sha>
```

## Структура окружения
```
/srv/projects/crm7-<env>/
  docker-compose.yml      образы crm7-app/crm7-web:${CRM7_VERSION}
  .env                    DB_PASS, DB_ROOT_PASS, CRM7_VERSION
  config/                 → /var/www/html/api/config   (том)
  storage/                → /var/www/html/api/storage  (том)
  var/                    → /var/www/html/api/var      (том)
  data/db/                → /var/lib/mysql             (том)
```
PHP-сервис публикует сетевой алиас `crm7-php`, поэтому один и тот же
`nginx.conf` в образе работает во всех окружениях.

## Данные при обновлении
- БД, вложения, конфиг — в томах, обновление образа их не затрагивает.
- Миграции применяются инкрементально (см. `docs/migrations.md`).
- `seed-demo.php --force` — только demo; на k/prod не запускается.
- Перенос наработок клиента (k → прод) — отдельный `mysqldump`/restore.
