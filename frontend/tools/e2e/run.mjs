/**
 * Лёгкие e2e-проверки критичных сценариев CRM7 через playwright-core и системный Edge.
 *
 * Запуск:
 *   CRM_PASSWORD_FILE=%TEMP%\opencode\seed_passwords.json npm run e2e
 *
 * Переменные окружения:
 *   CRM_BASE_URL         фронтенд (по умолчанию http://localhost:5174)
 *   CRM_API_URL          API v2 (по умолчанию http://127.0.0.1:8080/api/v2)
 *   CRM_PASSWORD_FILE    JSON {"login": "password"}
 *   CRM_PASSWORD         общий пароль
 *   CRM_BROWSER_CHANNEL  канал Playwright (по умолчанию msedge; «bundled» — встроенный chromium)
 *   CRM_HEADED=1         показать окно браузера
 *   CRM_E2E_ONLY         список сценариев через запятую (фильтр по подстроке имени)
 */

import { chromium } from 'playwright-core';
import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';

const BASE = (process.env.CRM_BASE_URL ?? 'http://localhost:5174').replace(/\/$/, '');
const API = (process.env.CRM_API_URL ?? 'http://127.0.0.1:8080/api/v2').replace(/\/$/, '');
const CHANNEL = process.env.CRM_BROWSER_CHANNEL ?? 'msedge';
const HEADED = process.env.CRM_HEADED === '1';
const launchOptions =
  CHANNEL && CHANNEL !== 'bundled' ? { channel: CHANNEL, headless: !HEADED } : { headless: !HEADED };
const ONLY = (process.env.CRM_E2E_ONLY ?? '')
  .split(',')
  .map((item) => item.trim())
  .filter(Boolean);
const OUT = path.resolve('artifacts/e2e');

const log = (message) => process.stdout.write(`${message}\n`);

async function loadPasswords() {
  if (!process.env.CRM_PASSWORD_FILE) {
    return {};
  }

  const raw = await readFile(process.env.CRM_PASSWORD_FILE, 'utf8');

  return JSON.parse(raw.replace(/^\uFEFF/, ''));
}

function passwordFor(login, passwords) {
  return passwords[login] ?? process.env.CRM_PASSWORD ?? '';
}

async function apiLogin(login, password) {
  const response = await fetch(`${API}/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ login, password })
  });

  if (!response.ok) {
    throw new Error(`не удалось войти как ${login}: HTTP ${response.status}`);
  }

  return (await response.json()).data;
}

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

async function expectVisible(locator, message, timeout = 15000) {
  try {
    await locator.first().waitFor({ state: 'visible', timeout });
  } catch {
    throw new Error(message);
  }
}

function slug(name) {
  return name
    .toLowerCase()
    .replace(/[^a-zа-я0-9]+/gi, '-')
    .replace(/^-|-$/g, '')
    .slice(0, 60);
}

const scenarios = [
  {
    name: 'вход через форму и выход',
    role: 'manager',
    bare: true,
    async run(page, ctx) {
      await page.goto(`${BASE}/#/login`, { waitUntil: 'domcontentloaded' });
      await page.locator('input[name="login"]').fill(ctx.login);
      await page.locator('input[name="password"]').fill(ctx.password);
      await page.getByRole('button', { name: 'Войти' }).click();

      await expectVisible(page.locator('nav.desktop-nav'), 'после входа не появилась навигация');

      await page.goto(`${BASE}/#/profile`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Сессии' }).click();
      await page.getByRole('button', { name: 'Выйти из текущей сессии' }).click();

      await expectVisible(
        page.locator('input[name="login"]'),
        'после выхода не вернулись на экран входа'
      );
    }
  },
  {
    name: 'новая заявка: выбор клиента из поиска',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/requests/new`, { waitUntil: 'domcontentloaded' });

      const input = page.locator('.client-search input');
      await expectVisible(input, 'нет поля поиска клиента');
      await input.click();

      const items = page.locator('.cl-item');
      await expectVisible(items.first(), 'не появился список клиентов');
      const count = await items.count();
      assert(count > 0 && count <= 15, `в списке должно быть 10-15 клиентов, а не ${count}`);

      const submit = page.getByRole('button', { name: 'Создать заявку' });
      assert(await submit.isDisabled(), 'без выбранного клиента создание заявки должно быть заблокировано');

      await input.fill('7700000003');
      const innItem = page.locator('.cl-item', { hasText: '7700000003' });
      await expectVisible(innItem.first(), 'поиск по ИНН не вернул клиента');
      await innItem.first().click();

      await expectVisible(page.locator('.client-selected'), 'после выбора нет подтверждения клиента');
      await expectVisible(page.getByText(/ИНН 7700000003/), 'в выбранном клиенте нет ИНН');
      assert(!(await submit.isDisabled()), 'после выбора клиента создание заявки должно быть доступно');

      await page.locator('.cs-clear').click();
      await expectVisible(input, 'после сброса не вернулось поле поиска');
      await input.fill('Иванов');
      assert(await submit.isDisabled(), 'введённый вручную текст без выбора не должен проходить проверку');
    }
  },
  {
    name: 'заявки: карточка и вкладки',
    role: 'manager',    async run(page) {
      await page.goto(`${BASE}/#/requests`, { waitUntil: 'domcontentloaded' });
      const card = page
        .locator('a.card[href^="#/requests/"]')
        .filter({ hasText: /позиций: [1-9]/ })
        .first();
      await expectVisible(card, 'нет заявки с позициями в списке');
      await card.click();

      await expectVisible(page.getByRole('button', { name: /^Состав/ }), 'нет вкладки «Состав»');
      await expectVisible(page.getByRole('button', { name: /^Комментарии/ }), 'нет вкладки «Комментарии»');
      await expectVisible(page.getByRole('button', { name: /^История/ }), 'нет вкладки «История»');

      await page.getByRole('button', { name: /^Состав/ }).click();
      await expectVisible(page.locator('.it-row:not(.it-head)').first(), 'в составе нет позиций');

      await page.getByRole('button', { name: /^История/ }).click();
      await expectVisible(page.getByText('Хронология'), 'нет раздела «Хронология» в истории');
    }
  },
  {
    name: 'чат: отправка сообщения',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/chat`, { waitUntil: 'domcontentloaded' });
      const thread = page.locator('.threads .thread').first();
      await expectVisible(thread, 'нет ни одного треда в чате');
      await thread.click();

      const composer = page.locator('form.composer textarea');
      await expectVisible(composer, 'нет поля ввода сообщения');
      const marker = `E2E проверка ${Date.now()}`;
      await composer.fill(marker);
      await page.locator('form.composer').getByRole('button', { name: 'Отправить' }).click();

      await expectVisible(page.getByText(marker), 'отправленное сообщение не появилось');
    }
  },
  {
    name: 'склад: поиск и сортировка',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/stocks`, { waitUntil: 'domcontentloaded' });

      const search = page.locator('form.search input[type="search"]').first();
      await expectVisible(search, 'нет поля поиска на складе');
      await search.fill('iPhone');
      await page.locator('form.search').getByRole('button', { name: 'Найти' }).click();

      await expectVisible(page.getByText(/Смартфон Apple iPhone/).first(), 'поиск не вернул позиции');

      assert(
        (await page.getByRole('button', { name: /Фильтр/ }).count()) === 0,
        'фильтры на складе должны быть убраны'
      );

      const sort = page.locator('.sort-field select');
      await expectVisible(sort, 'нет сортировки на складе');
      await sort.selectOption('qty_desc');
      await expectVisible(page.locator('.levels .level').first(), 'после смены сортировки пропали строки');
    }
  },
  {
    name: 'отчёты: переключение вкладок',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/reports`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.getByText('Создано за период'), 'не загрузились плитки отчёта');

      await page.getByRole('button', { name: 'По складам' }).click();
      await expectVisible(page.getByText(/Топ/).first(), 'вкладка «По складам» не отрисовалась');

      await page.getByRole('button', { name: 'Динамика' }).click();
      await expectVisible(page.getByText(/Динамика/).first(), 'вкладка «Динамика» не отрисовалась');

      await page.getByRole('button', { name: 'По статусам' }).click();
      await expectVisible(page.getByText('Доля'), 'в «По статусам» нет столбца «Доля»');
    }
  },
  {
    name: 'заявки: пустое состояние при фильтрах',
    role: 'manager',
    async run(page) {
      await page.goto(`${BASE}/#/requests`, { waitUntil: 'domcontentloaded' });
      await expectVisible(page.locator('.list .row').first(), 'не загрузился список заявок');

      await page.getByRole('button', { name: /^Фильтры/ }).click();
      const dates = page.locator('.filter-form input[type="date"]');
      await dates.nth(0).fill('2000-01-01');
      await dates.nth(1).fill('2000-01-31');
      await page.getByRole('button', { name: 'Применить' }).click();

      const title = page.locator('.empty-title');
      await expectVisible(title, 'нет сообщения «Заявки не найдены»');
      assert(
        (await title.textContent()).trim() === 'Заявки не найдены',
        'неверный заголовок пустого состояния'
      );
      await expectVisible(
        page.getByRole('button', { name: 'Сбросить фильтры' }),
        'нет кнопки сброса фильтров'
      );

      await page.getByRole('button', { name: 'Сбросить фильтры' }).click();
      await expectVisible(page.locator('.list .row').first(), 'после сброса фильтров список не вернулся');
    }
  },
  {
    name: 'админ: роли — группы прав',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/admin`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Роли' }).click();

      const group = page.locator('.cap-group').filter({ hasText: 'Заявки' }).first();
      await expectVisible(group, 'нет группы прав «Заявки»');

      const head = group.locator('.cap-group-head');
      assert((await head.getAttribute('aria-expanded')) === 'false', 'группа должна быть свёрнута по умолчанию');
      await head.click();
      assert((await head.getAttribute('aria-expanded')) === 'true', 'группа не раскрылась');
      await expectVisible(group.locator('input[type="checkbox"]').first(), 'в раскрытой группе нет чекбоксов');
    }
  },
  {
    name: 'админ: аудит — русские подписи',
    role: 'admin',
    async run(page) {
      await page.goto(`${BASE}/#/admin`, { waitUntil: 'domcontentloaded' });
      await page.getByRole('button', { name: 'Журнал аудита' }).click();

      await expectVisible(page.getByText('Вход в систему').first(), 'аудит без русской подписи действия');
      await expectVisible(page.getByText('auth.login').first(), 'аудит без технического кода действия');
    }
  }
];

async function main() {
  const passwords = await loadPasswords();
  const selected = scenarios.filter(
    (scenario) => ONLY.length === 0 || ONLY.some((needle) => scenario.name.includes(needle))
  );

  await mkdir(OUT, { recursive: true });

  log(`Фронтенд: ${BASE}; API: ${API}; браузер: ${CHANNEL}`);
  log(`Сценариев: ${selected.length}`);

  const browser = await chromium.launch(launchOptions);
  const results = [];

  for (const scenario of selected) {
    const password = passwordFor(scenario.role, passwords);
    const started = Date.now();

    if (!password) {
      log(`✗ ${scenario.name} — не задан пароль для роли ${scenario.role}`);
      results.push({ name: scenario.name, ok: false, reason: 'нет пароля' });
      continue;
    }

    const context = await browser.newContext({
      viewport: { width: 1440, height: 900 },
      locale: 'ru-RU',
      timezoneId: 'Europe/Moscow'
    });
    const page = await context.newPage();
    page.setDefaultTimeout(15000);
    page.on('dialog', (dialog) => dialog.accept());

    try {
      let session = null;

      if (!scenario.bare) {
        session = await apiLogin(scenario.role, password);
        await context.addInitScript(
          ([token, user]) => {
            localStorage.setItem('crm_token', token);
            localStorage.setItem('crm_user', user);
          },
          [session.token, JSON.stringify(session.user)]
        );
      }

      await scenario.run(page, { login: scenario.role, password, session });

      log(`✓ ${scenario.name} (${Date.now() - started} мс)`);
      results.push({ name: scenario.name, ok: true });
    } catch (cause) {
      const file = path.join(OUT, `${slug(scenario.name)}.png`);

      try {
        await page.screenshot({ path: file, fullPage: false });
      } catch {
        // скриншот не критичен
      }

      log(`✗ ${scenario.name} — ${cause.message} (${path.basename(file)})`);
      results.push({ name: scenario.name, ok: false, reason: cause.message });
    } finally {
      await context.close();
    }
  }

  await browser.close();

  const failed = results.filter((result) => !result.ok);

  log('');
  log(`Итог: ${results.length - failed.length}/${results.length} сценариев пройдено`);

  if (failed.length > 0) {
    for (const result of failed) {
      log(`  ✗ ${result.name}: ${result.reason}`);
    }

    process.exitCode = 1;
  }
}

main().catch((cause) => {
  log(`Ошибка запуска: ${cause.message}`);
  process.exitCode = 1;
});
