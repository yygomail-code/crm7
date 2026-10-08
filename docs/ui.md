# UI: дизайн-система (Ant Design)

Интерфейс приводится к рекомендациям Ant Design. Общие токены — в
`frontend/src/app.css` (`:root`); компоненты — в `frontend/src/lib/components/ui/`.

## Токены

Цвета:
- Основной: `--primary #1677ff`, `--primary-hover #4096ff`, `--primary-active #0958d9`, `--primary-bg #e6f4ff`, `--primary-border #91caff`.
- Текст: `--text rgba(0,0,0,.88)`, `--muted rgba(0,0,0,.65)`, `--text-description rgba(0,0,0,.45)`.
- Границы/заливки: `--border #d9d9d9`, `--border-secondary #f0f0f0`, `--fill-tertiary rgba(0,0,0,.02)`.
- Статусы: `--danger`, `--danger-bg`, `--error-border`, `--error-text #cf1322`; `--success`, `--success-bg`, `--success-border`, `--success-text #389e0d`; `--warning`, `--warning-bg`, `--warning-text #d48806`.

Прочее:
- Радиусы: `--radius-sm 6px`, `--radius-md 8px`. Тени: `--shadow-sm`, `--shadow-md`.
- Отступы: `--space-1..6` = 4/8/12/16/24/32. Контролы: `--control-height 32px`.
- Шрифт: `--font` (системный), база 14px / line-height 1.5714.

**Правило:** не хардкодить цвета вне токенов Ant — использовать переменные.

## Размеры

- Контролы (инпуты, селекты, кнопки, иконочные кнопки, пагинация) — **32px** (Ant default).
- Иконки внутри кнопок — **16px**.
- Радиус контролов — 6px; карточек, модалок и панелей — 8px.
- Фокус полей — `border-color: var(--primary)` + `box-shadow: 0 0 0 2px rgba(5,145,255,.1)` (Ant controlOutline).

## Иконки

- `frontend/src/lib/components/ui/Icon.svelte` — карта имён → иконки из `@ant-design/icons-svg`.
- Использование: `<Icon name="edit" size={16} />`.
- Иконка рендерится как `<svg fill="currentColor">` — цвет задаётся через `color` родителя.
- **Добавить иконку:** импортировать `XxxOutlined`/`XxxFilled` из `@ant-design/icons-svg/es/asn/...`
  и добавить запись в объект `icons` (ключ — короткое имя).
- Текущие имена: `admin`, `arrow-*`, `bell`, `camera`, `cart`, `chat`, `check-circle`, `clients`,
  `close`, `close-circle`, `edit`, `export`, `group`, `help`, `import`, `info`, `my-reports`,
  `picture`, `plus`, `reports`, `requests`, `settings`, `sort`, `stocks`, `substitutions`, `view`.

## Компоненты (`frontend/src/lib/components/ui/`)

- **`Button.svelte`** — `variant: 'primary' | 'ghost' | 'text' | 'danger'` (по умолч. `primary`),
  `size: 'md' | 'sm'` (32px), `loading`, `disabled`, `title`, `ariaLabel`, `onclick`.
- **`Modal.svelte`** — общий шелл: `open`, `title`, `label`, `wide`, `closeButton`, `bodyMinHeight`, `onclose`.
  Рендерится только при `open`; Escape и клик по маске закрывают.
- **`Input.svelte`** — `label`, `type`, `value` (bindable), `placeholder`, `error`, `name`. Высота 32.
- **`SearchInput.svelte`** — поиск с историей (`historyKey`), `onclear`, `onpick`.
- **`Spinner.svelte`** — `size` (по умолч. 18).
- **`Pagination.svelte`** — `page`, `perPage`, `total`, `onchange`, `onperpage`, `perPageOptions`, `always`, `loading`.
- **`FiltersModal.svelte`** — кнопка «Фильтры» + модалка (`onapply`, `onreset`, `onopen`, `count`).
- **`ExportModal.svelte`** — выбор формата (XLSX/XLS/CSV/TXT/PDF) и способа (скачать/почта), `onpick(format, byEmail)`.
- **`Logo.svelte`**, **`BarChart.svelte`** — логотип и гистограмма.
- **`stocks/PhotoGallery.svelte`** — галерея фото: `photos`, `variant: 'list' | 'tile'` (tile — свайп/стрелки), `onopen`, `flush`. Пропорции/вписывание и размер запрашиваемого варианта берутся из настроек (список → `preview`, tile → `card`).

## Фото номенклатуры

Формат отображения — системная настройка (`#/settings` → «Система»); применяется к плиткам, строкам, карточке позиции и ItemEdit:

- **формат** (`stocks.photo_ratio`): динамический (по фото) / квадрат 1:1 / горизонтальный 4:3 / вертикальный 3:4;
- **несовпадение пропорций** (`stocks.photo_fit`): вписывать (без обрезки, по умолчанию) / заполнять (с обрезкой);
- **размеры вариантов** по длинной стороне, px (`stocks.photo_size_preview/card/max`, дефолт 160/600/1600).

Список запрашивает `preview`, плитки/карточка/просмотр — `card`, полный размер — `max`. При загрузке фото уменьшается до `max` и генерируются варианты `card`/`preview` (см. [`architecture.md`](architecture.md)).

## Паттерны страниц

- **Заголовок:** `h1` 20px / 600 / `--text`; подзаголовок 14px `--text-description`.
- **Тулбар:** фильтры слева, действия справа; разделители — 1px × 24, `--border-secondary`.
- **Таблица:** шапка `--fill-tertiary`, текст 600 / `--text`, разделители `--border-secondary`,
  числа по правому краю, пагинация 32px.
- **Модалки:** заголовок 16/600, футер `[Отмена] [Действие]` справа.
- **Alert/notice:** иконка + рамка + тёмный текст на цветном фоне (`--danger-bg`/`--success-bg`).

## Примеры в коде

- `frontend/src/views/Stocks.svelte` — таблица/плитки, пагинация, модалки фильтров/импорта/экспорта, карточка позиции.
- `frontend/src/views/ItemEdit.svelte` — форма (поля, селекты, цены, управление фото).
- `frontend/src/lib/components/ui/*` — сами компоненты и их стили.

Связанное: [`architecture.md`](architecture.md) (структура фронтенда), рекомендации Ant
(Layout/Header, Menu, Table, Pagination, Form, Modal, Alert, Tag).
