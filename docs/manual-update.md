# Ручное обновление: Git и Docker

Справочник для случая, когда нет агента, оборвалась связь или нужно сделать всё
самому через консоль. Все команды — из корня репозитория, если не указано иное.
Сервер по умолчанию: `root@www.crm7.ru`, SSH-порт `21023`, ключ
`~/.ssh/id_ed25519`.

## 0. Что где происходит

| Где | Что делает |
|---|---|
| Локальный репозиторий | правки, `git add`, `git commit` |
| GitHub (`yygomail-code/crm7`) | хранит коммиты; сюда идёт `git push` |
| Dev-сервер (`/srv/build/crm7`) | сборка образов `crm7-app` и `crm7-web` |
| GHCR | реестр образов `ghcr.io/yygomail-code/crm7-app`, `.../crm7-web` |
| `/srv/projects/crm7-{test,demo,k}` | окружения: `.env`, `docker-compose.yml`, тома |
| Тома окружения | БД, `config/`, `storage/`, `var/` — релиз их не трогает |

Порядок при изменении кода:

1. закоммитить и запушить код;
2. собрать образы и опубликовать их в GHCR;
3. выпустить тег на `test`, проверить;
4. выпустить на `k`/`all`, если тест прошёл.

Локальный Docker не нужен: `build_images.py` собирает образы по SSH на
dev-сервере. Локально нужны Git, Python, Node.js (для сборки фронтенда) и SSH.

## 1. Подготовка

### 1.1. Инструменты

```powershell
git --version
python --version
node --version
npm --version
python -c "import paramiko; print(paramiko.__version__)"
ssh -V
```

Ожидаемо:

- `git version 2.x…`;
- `Python 3.12.x` (подойдёт 3.9+);
- `v22.x` или новее для Node.js;
- версия npm;
- число версии `paramiko`, например `5.0.0`;
- `OpenSSH_for_Windows_…` или другая версия OpenSSH.

Если `paramiko` не найден:

```powershell
python -m pip install paramiko
```

Если не установлены зависимости фронтенда:

```powershell
cd frontend
npm ci
cd ..
```

### 1.2. Состояние репозитория

```powershell
git status
git branch --show-current
git log --oneline -5
git remote -v
```

Ожидаемо:

- ветка `master`;
- в `git status` — `nothing to commit, working tree clean` или список ваших
  изменений;
- `git remote -v` — две строки GitHub: `fetch` и `push`;
- последние коммиты в стиле `CRM7: <область> — <суть>`.

### 1.3. Связь с сервером

```powershell
ssh -p 21023 root@www.crm7.ru "echo SSH_OK"
```

Ожидаемо: `SSH_OK`.

Если `Permission denied (publickey)` — не тот ключ. Укажите его явно:

```powershell
ssh -i $HOME\.ssh\id_ed25519 -p 21023 root@www.crm7.ru "echo SSH_OK"
```

Если `Connection timed out` или `Connection refused` — проверьте VPN, интернет и
порт `21023`.

### 1.4. Доступ к GHCR

Образы приватные. На сервере один раз выполняют вход с GitHub PAT (scope
`write:packages` — для пуша, `read:packages` — для пула):

```bash
echo "$GHCR_TOKEN" | docker login ghcr.io -u <github-user> --password-stdin
```

Ожидаемо: `Login Succeeded`. Токен в репозиторий и `.env` не записывать.

## 2. Git: коммит и пуш вручную

### 2.1. Посмотреть изменения

```powershell
git status --short
```

Обозначения:

| Код | Значение |
|---|---|
| `M ` | изменён и уже в индексе |
| ` M` | изменён, но ещё не в индексе |
| `A ` | новый файл в индексе |
| `??` | новый файл, Git его не отслеживает |
| `D ` | удалён и уже в индексе |
| ` D` | удалён, но ещё не в индексе |

Подробности:

```powershell
git diff            # что изменилось вне индекса
git diff --cached   # что попадёт в коммит
```

### 2.2. Индекс и коммит

```powershell
git add -A
git diff --cached --stat
git commit -m "CRM7: <область> — <суть>"
```

- `git add -A` — добавляет все изменения, включая удаления и новые файлы.
- `git diff --cached --stat` — показывает, что именно уйдёт в коммит.
- `git commit` — создаёт локальный коммит. Сообщение — в стиле репозитория,
  по-русски, одна строка.

Ожидаемо:

```text
[master 1a2b3c4] CRM7: заявки — ...
 3 files changed, 42 insertions(+), 5 deletions(-)
 create mode 100644 ...
```

Если вышло `nothing to commit, working tree clean` — изменений нет, коммит не
создан.

Если сработал pre-commit hook и коммит отклонён — исправьте замечания, снова
`git add -A` и новый `git commit`. Не используйте `--no-verify` без причины.

### 2.3. Пуш

```powershell
git push origin master
```

Ожидаемо:

```text
Enumerating objects: 12, done.
...
To https://github.com/yygomail-code/crm7.git
   f72c6c7..1a2b3c4  master -> master
```

Строка `master -> master` означает, что ветка на GitHub обновилась. Если вышло
`Everything up-to-date` — этот коммит уже был отправлен.

### 2.4. Получить изменения с GitHub

Если работаете не один или подтягиваете свежий `master`:

```powershell
git fetch origin
git status
git pull --ff-only origin master
```

- `git fetch origin` — скачивает новые коммиты, не меняя рабочие файлы; обычно
  выводит только имена обновлённых веток или ничего.
- `git status` — покажет, на сколько коммитов вы впереди/позади `origin/master`.
- `git pull --ff-only` — перематывает ветку вперёд без merge-коммита.

Ожидаемо: `Already up to date.` или `Updating abc1234..def5678`, `Fast-forward`
и список изменённых файлов. Если вышло `fatal: Not possible to fast-forward` —
локальные и удалённые изменения разошлись: закоммитьте свои правки, затем
`git pull --rebase origin master`, разрешите конфликты и только потом пушьте.
Force-push без явной необходимости не использовать.

### 2.5. Проверка

```powershell
git status
git log --oneline -1
git ls-remote origin master
git rev-parse HEAD
```

Ожидаемо:

- `git status` — `Your branch is up to date with 'origin/master'`;
- хеш из `git ls-remote origin master` совпадает с `git rev-parse HEAD`.

### 2.6. Если что-то пошло не так

| Сообщение | Что значит | Что делать |
|---|---|---|
| `not a git repository` | команда не в корне репозитория | `cd` в корень проекта |
| `nothing to commit` | изменений нет | коммит не нужен |
| `Authentication failed` / `Invalid username or token` | GitHub не принял пароль/токен | обновить PAT в Git Credential Manager (`git credential-manager github login`) или через учётные данные Windows; пароль GitHub больше не подходит |
| `could not resolve host` / `Connection timed out` | нет сети/VPN | коммит остался локально; повторить `git push`, когда связь появится |
| `Updates were rejected because the remote contains work…` | на GitHub есть чужие коммиты | `git pull --rebase origin master`, разрешить конфликты, `git push` |
| `Permission denied (publickey)` при SSH | не тот ключ | указать `-i` или добавить ключ на сервер |
| pre-commit hook failed | проверки не прошли | исправить, `git add -A`, новый коммит |

Проверить, есть ли неотправленные коммиты:

```powershell
git log --oneline origin/master..HEAD
git status
```

Отменить последний локальный коммит, оставив изменения в индексе:

```powershell
git reset --soft HEAD~1
```

`git reset --hard` удаляет изменения — применять только осознанно.

### 2.7. Скрипт

Вместо шагов 2.2–2.3 можно запустить `commit-push.cmd` (двойной клик или
`commit-push.cmd "CRM7: ..."`). Он делает `git add -A`, коммит и `git push`,
повторяет пуш при обрыве связи.

## 3. Docker: сборка, публикация, релиз

### 3.1. Тег и версия

- Версия по умолчанию — `version` из `frontend/package.json`.
- Коммит — `git rev-parse --short HEAD`.
- Образы получают два тега: `<version>` и `sha-<commit>`.

Если выпускаете новую версию, поднимите `version` в `frontend/package.json` и
закоммитьте это до сборки (или передайте `--tag` явно).

```powershell
Select-String -Path frontend\package.json -Pattern '"version"'
git rev-parse --short HEAD
```

Ожидаемо: строка версии и короткий хеш, например `1.3.0` и `a1b2c3d`. Тег должен
содержать только латиницу, цифры, точку, дефис или подчёркивание.

Перед сборкой рабочее дерево лучше закоммитить: тег `sha-<commit>` всегда указывает
на `HEAD`, даже если в образ попали незакоммиченные правки.

### 3.2. Сборка и публикация

Только собрать на dev-сервере, без публикации:

```powershell
python deploy/build_images.py --tag 1.4.0
```

Собрать и запушить в GHCR:

```powershell
python deploy/build_images.py --tag 1.4.0 --push
```

Что делает команда:

1. `npm run build` во `frontend/` (локально);
2. пакует `frontend/dist`, `api/`, `Dockerfile.*`, `nginx.conf` в архив;
3. по SSH загружает архив в `/srv/build/crm7`;
4. на сервере выполняет `docker build` для `crm7-app` и `crm7-web`;
5. с `--push` отправляет оба тега каждого образа в GHCR.

Ожидаемый вывод:

```text
== building frontend ==
...
version=1.4.0 sha=1a2b3c4
images: ghcr.io/yygomail-code/crm7-app  ghcr.io/yygomail-code/crm7-web
context: 1234567 bytes
uploaded
...
BUILD_OK
build OK (pushed)
```

Признак успеха — `BUILD_OK` и `build OK`, код возврата `0`. С `--push` в выводе
будут строки `The push refers to repository […]` и `1.4.0: digest: sha256:…`.

Полезные флаги:

| Флаг | Значение |
|---|---|
| `--tag 1.4.0` | тег версии (по умолчанию из `package.json`) |
| `--push` | опубликовать образы в GHCR |
| `--skip-build` | не собирать фронтенд, взять готовый `frontend/dist` |
| `--server root@host:port` | другой сервер |
| `--key путь` | другой SSH-ключ |

`--skip-build` опасен: можно упаковать старую сборку фронтенда. Без `--push`
образы остаются только на dev-сервере, и релиз на окружения не сможет их
скачать.

### 3.3. Релиз на окружение

```powershell
python deploy/release_crm7.py --env test --tag 1.4.0
```

Окружения: `test`, `demo`, `k`, `all` (все три). Для каждого окружения скрипт:

1. меняет `CRM7_VERSION` в `/srv/projects/crm7-<env>/.env`;
2. выполняет `docker compose pull`;
3. применяет миграции: `docker compose run --rm php php /var/www/html/api/bin/migrate.php`;
4. выполняет `docker compose up -d`;
5. проверяет health и печатает статус контейнеров.

Ожидаемый вывод:

```text
=== test ===
...
CRM7 migrations
applied: 36, pending: 1

pending:
  new  037_example.sql

applied 037_example.sql
migrations done
 Container crm7-test-app  Started
 Container crm7-test-php  Started
 Container crm7-test-web  Started
crm7-test-app    Up 5 seconds
crm7-test-php    Up 5 seconds
crm7-test-web    Up 5 seconds
{"ok":true,"data":{"status":"ok","database":"ok","time":"…","php":"8.2…"}}
RELEASE_OK_test
release OK: test @ 1.4.0
```

Если миграций нет, вместо списка будет `nothing to apply`, затем
`migrations done`. Признак успеха — `RELEASE_OK_<env>` и `release OK`.

Флаги:

| Флаг | Значение |
|---|---|
| `--env test` | одно окружение (`demo`, `k`) |
| `--env all` | все три окружения |
| `--tag sha-1a2b3c4` | откат/релиз по SHA-тегу |
| `--no-pull` | не делать `docker compose pull` (образ уже на сервере) |

`release_crm7.py` не откатывает миграции. Если релиз содержит необратимую
миграцию, сначала сделайте резервную копию БД.

### 3.4. Проверка на сервере

```powershell
ssh -p 21023 root@www.crm7.ru
```

Дальше на сервере:

```bash
cd /srv/projects/crm7-test
grep '^CRM7_VERSION=' .env
docker compose ps
docker compose logs --tail=100 php web
docker exec crm7-test-web wget -qO- http://127.0.0.1/api/v2/health
```

Ожидаемо:

- `.env` содержит `CRM7_VERSION=1.4.0`;
- `docker compose ps` — контейнеры `app`, `php`, `web` в состоянии `Up`;
- в логах нет `Fatal error`, `PHP Parse error`, `502`, `connection refused`;
- health возвращает HTTP 200 и JSON:

```json
{"ok":true,"data":{"status":"ok","database":"ok","time":"2026-10-23T12:00:00+00:00","php":"8.2.…"}}
```

Если `"database":"error"` и `"status":"degraded"` — образ тут не виноват:
проблема с БД или конфигом окружения. Смотрите `.env`, `config/` и логи `php`.

После проверки откройте сайт окружения, войдите и проверьте список заявок и
несколько ключевых страниц.

### 3.5. Откат

Откат — это релиз предыдущего тега:

```powershell
python deploy/release_crm7.py --env k --tag sha-1a2b3c4
```

Нужный SHA можно найти в `git log --oneline` или в GHCR. После отката снова
проверьте `RELEASE_OK_<env>`, health и версию в `.env`.

Помните: код откатится, а миграции БД — нет. Если миграция уже изменила схему,
может понадобиться восстановление БД из резервной копии.

Ручной откат без скрипта (на сервере):

```bash
cd /srv/projects/crm7-k
sed -i 's/^CRM7_VERSION=.*/CRM7_VERSION=sha-1a2b3c4/' .env
grep -q '^CRM7_VERSION=' .env || echo 'CRM7_VERSION=sha-1a2b3c4' >> .env
docker compose pull
docker compose run --rm php php /var/www/html/api/bin/migrate.php
docker compose up -d
docker exec crm7-k-web wget -qO- http://127.0.0.1/api/v2/health
```

### 3.6. Полный цикл одной командой

```powershell
python deploy/update_images.py --mode full --env test --tag 1.4.0
```

Или двойной клик по `docker-update.cmd` — интерактивное меню: собрать,
опубликовать, выпустить или полный цикл. Полезные варианты:

```powershell
python deploy/update_images.py --mode push --tag 1.4.0 --dry-run
python deploy/update_images.py --mode release --env k --tag sha-1a2b3c4
```

`--dry-run` показывает команды без запуска. Для `k` и `all` подтверждение по
умолчанию — «нет».

### 3.7. Частые ошибки

| Сообщение | Что значит | Что делать |
|---|---|---|
| `paramiko is required` | нет Python-модуля | `python -m pip install paramiko` |
| `frontend build not found` | нет `frontend/dist` | убрать `--skip-build` или собрать фронтенд |
| `Connection refused` / `timed out` | нет SSH/VPN | проверить VPN, порт `21023`, ключ |
| `Permission denied (publickey)` | не тот SSH-ключ | указать `--key` или добавить ключ на сервер |
| `unauthorized` / `denied` при push/pull | GHCR не авторизован | `docker login ghcr.io` с PAT и нужными scope |
| `manifest unknown` / `not found` | тега нет в GHCR | сначала `build_images.py --push` с этим тегом |
| `no space left on device` | закончилось место | `docker image prune`, `docker builder prune`; **не** удалять тома |
| `build FAILED` | сборка на сервере не прошла | смотреть вывод выше: Docker, место, ошибка в коде |
| `release FAILED (envs without OK: […])` | релиз не подтвердился | смотреть вывод и health; окружения могли обновиться частично |
| health `database: error` | нет связи с БД | проверить `.env`, `config/`, логи `php`; образ не пересобирать |
| `could not resolve host` при git push | нет сети/VPN | коммит локальный; повторить push позже |

## 4. Безопасность

- `k` — реальные данные клиента: `seed-demo.php` и `seed-dev.php` там не
  запускать.
- `docker compose down -v` и `docker system prune --volumes` удаляют тома с БД и
  файлами. На серверах так не делать.
- `.env`, `config/database.json`, `storage/`, дампы БД — не коммитить и не
  пересылать в образ.
- Перед релизом с миграцией — резервная копия БД.
- Секреты GHCR и SSH-ключи хранить вне репозитория.

## 5. Чек-лист

- [ ] VPN и SSH работают (`SSH_OK`).
- [ ] `git status` ожидаемый; рабочее дерево закоммичено.
- [ ] Коммит создан и отправлен: `git status` — `up to date`.
- [ ] Версия/тег выбраны; `sha` совпадает с нужным коммитом.
- [ ] Образы собраны и опубликованы: `BUILD_OK` + `build OK (pushed)`.
- [ ] Релиз на `test`: `RELEASE_OK_test` + `release OK`.
- [ ] Health: `"status":"ok"`, `"database":"ok"`.
- [ ] Сайт, вход и ключевые страницы проверены.
- [ ] Только после этого — релиз на `k` или `all`.
- [ ] При проблеме — откат на предыдущий `sha-<commit>` и проверка health.
