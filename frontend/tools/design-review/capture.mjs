#!/usr/bin/env node
/**
 * Снимает скриншоты всех экранов CRM для дизайн-ревью (vision-LLM или человеком).
 *
 * Требуется запущенный фронтенд (npm run dev) и API.
 * Авторизация выполняется через API, токен подкладывается в localStorage.
 *
 * Переменные окружения:
 *   CRM_PASSWORD_FILE     JSON-файл {"login": "password"} (рекомендуется, пароли не в командной строке)
 *   CRM_PASSWORD          общий пароль для всех тестовых учёток
 *   CRM_PASSWORD_<ROLE>   пароль конкретной роли (переопределяет CRM_PASSWORD)
 *   CRM_BASE_URL          фронтенд (по умолчанию http://localhost:5173)
 *   CRM_API_URL           API v2 (по умолчанию http://127.0.0.1:8080/api/v2)
 *   CRM_SHOTS_DIR         каталог вывода (по умолчанию artifacts/design-review)
 *   CRM_BROWSER_CHANNEL   канал Playwright (по умолчанию msedge; альтернатива — chrome)
 *   CRM_SETTLE_MS         пауза после загрузки страницы, мс (по умолчанию 1600)
 *   CRM_CAPTURE           viewport | full | both (по умолчанию both)
 *   CRM_VIEWPORTS         desktop,mobile (по умолчанию оба)
 *   CRM_ROLES             manager,client,admin (по умолчанию все)
 *
 * Примеры:
 *   npm run screenshots
 *   CRM_PASSWORD_FILE=%TEMP%\opencode\seed_passwords.json npm run screenshots
 *   CRM_ROLES=client CRM_VIEWPORTS=mobile npm run screenshots
 */
import { chromium } from 'playwright-core';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const BASE = (process.env.CRM_BASE_URL ?? 'http://localhost:5173').replace(/\/$/, '');
const API = (process.env.CRM_API_URL ?? 'http://127.0.0.1:8080/api/v2').replace(/\/$/, '');
const CHANNEL = process.env.CRM_BROWSER_CHANNEL ?? 'msedge';
const SETTLE_MS = Number(process.env.CRM_SETTLE_MS ?? 1600);
const CAPTURE = (process.env.CRM_CAPTURE ?? 'both').toLowerCase();
const OUT_ROOT = path.resolve(process.env.CRM_SHOTS_DIR ?? 'artifacts/design-review');

const allViewports = [
  { name: 'desktop', width: 1440, height: 900 },
  { name: 'mobile', width: 390, height: 844 }
];
const selectedViewports = (process.env.CRM_VIEWPORTS ?? 'desktop,mobile')
  .split(',')
  .map((item) => item.trim())
  .filter(Boolean);
const viewports = allViewports.filter((viewport) => selectedViewports.includes(viewport.name));

const roleDefs = {
  manager: {
    login: 'manager',
    routes: (ctx) => [
      { name: '01-requests', path: '/requests' },
      { name: '02-request-new', path: '/requests/new' },
      { name: '03-request-card', path: ctx.requestId ? `/requests/${ctx.requestId}` : null },
      { name: '04-clients', path: '/clients' },
      { name: '05-client-card', path: ctx.clientId ? `/clients/${ctx.clientId}` : null },
      { name: '06-chat', path: '/chat' },
      { name: '07-stocks', path: '/stocks' },
      { name: '08-reports', path: '/reports' },
      { name: '09-substitutions', path: '/substitutions' },
      { name: '10-notifications', path: '/notifications' },
      { name: '11-profile', path: '/profile' },
      { name: '12-about', path: '/about' },
      { name: '13-legal-privacy', path: '/legal/privacy' }
    ]
  },
  client: {
    login: 'client',
    routes: (ctx) => [
      { name: '01-requests', path: '/requests' },
      { name: '02-request-new', path: '/requests/new' },
      { name: '03-request-card', path: ctx.requestId ? `/requests/${ctx.requestId}` : null },
      { name: '04-stocks', path: '/stocks' },
      { name: '05-my-reports', path: '/my-reports' },
      { name: '06-chat', path: '/chat' },
      { name: '07-notifications', path: '/notifications' },
      { name: '08-profile', path: '/profile' },
      { name: '09-about', path: '/about' },
      { name: '10-legal-consent', path: '/legal/consent' }
    ]
  },
  admin: {
    login: 'admin',
    routes: (ctx) => [
      { name: '01-admin-users', path: '/admin' },
      { name: '02-admin-audit', path: '/admin', clicks: ['Журнал аудита'] },
      { name: '03-admin-roles', path: '/admin', clicks: ['Роли'] },
      { name: '04-admin-refs', path: '/admin', clicks: ['Справочники'] },
      { name: '05-admin-docs', path: '/admin', clicks: ['Документы'] },
      { name: '06-settings', path: '/settings' },
      { name: '07-requests', path: '/requests' },
      { name: '08-request-card', path: ctx.requestId ? `/requests/${ctx.requestId}` : null },
      { name: '09-clients', path: '/clients' },
      { name: '10-client-card', path: ctx.clientId ? `/clients/${ctx.clientId}` : null },
      { name: '11-stocks', path: '/stocks' },
      { name: '12-reports', path: '/reports' },
      { name: '13-substitutions', path: '/substitutions' },
      { name: '14-profile', path: '/profile' }
    ]
  }
};

const selectedRoles = (process.env.CRM_ROLES ?? 'manager,client,admin')
  .split(',')
  .map((item) => item.trim())
  .filter(Boolean);

function log(message) {
  process.stdout.write(`${message}\n`);
}

async function loadPasswordFile() {
  if (!process.env.CRM_PASSWORD_FILE) {
    return {};
  }

  const raw = await readFile(process.env.CRM_PASSWORD_FILE, 'utf8');
  const decoded = JSON.parse(raw.replace(/^\uFEFF/, ''));

  return Object.fromEntries(Object.entries(decoded).map(([key, value]) => [key, String(value)]));
}

function passwordFor(roleName, passwords) {
  const specific = process.env[`CRM_PASSWORD_${roleName.toUpperCase()}`];

  return specific ?? passwords[roleName] ?? process.env.CRM_PASSWORD ?? '';
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

  const payload = await response.json();

  return payload.data;
}

async function apiGet(pathname, token) {
  try {
    const response = await fetch(`${API}${pathname}`, {
      headers: { Authorization: `Bearer ${token}` }
    });

    if (!response.ok) {
      return null;
    }

    const payload = await response.json();

    return payload.data ?? null;
  } catch {
    return null;
  }
}

async function collectRouteContext(role, session) {
  const context = { userId: session?.user?.id ?? null, requestId: null, clientId: null };
  const requests = await apiGet('/requests?per_page=1', session.token);
  context.requestId = requests?.items?.[0]?.id ?? null;

  if (role.login !== 'client') {
    const clients = await apiGet('/clients?per_page=1', session.token);
    context.clientId = clients?.items?.[0]?.id ?? null;
  }

  return context;
}

function galleryHtml(entries, title) {
  const groups = new Map();

  for (const entry of entries) {
    const key = `${entry.role} · ${entry.viewport}`;

    if (!groups.has(key)) {
      groups.set(key, []);
    }

    groups.get(key).push(entry);
  }

  const sections = [...groups.entries()]
    .map(([key, items]) => {
      const cards = items
        .filter((item) => item.file)
        .map(
          (item) => `
        <figure>
          <figcaption>${item.route}${item.error ? ` — ошибка: ${item.error}` : ''}</figcaption>
          <a href="${item.file}" target="_blank"><img loading="lazy" src="${item.file}" alt="${item.route}"></a>
        </figure>`
        )
        .join('');

      return `<h2>${key}</h2><div class="grid">${cards}</div>`;
    })
    .join('\n');

  return `<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>${title}</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 24px; background: #f5f6f8; color: #1a1c1f; }
  h1 { font-size: 20px; } h2 { font-size: 15px; margin: 28px 0 10px; }
  .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 16px; }
  figure { margin: 0; background: #fff; border: 1px solid #dfe3e8; border-radius: 10px; padding: 10px; }
  figcaption { font-size: 12px; color: #667085; margin-bottom: 6px; }
  img { width: 100%; height: auto; border-radius: 6px; display: block; }
</style>
</head>
<body>
<h1>${title}</h1>
${sections}
</body>
</html>`;
}

async function main() {
  if (viewports.length === 0) {
    throw new Error('CRM_VIEWPORTS не содержит известных значений (desktop,mobile)');
  }

  const unknownRoles = selectedRoles.filter((role) => !(role in roleDefs));

  if (unknownRoles.length > 0) {
    throw new Error(`неизвестные роли: ${unknownRoles.join(', ')} (доступны: ${Object.keys(roleDefs).join(', ')})`);
  }

  const passwords = await loadPasswordFile();
  const stamp = new Date().toISOString().replace(/[:.]/g, '-').slice(0, 19);
  const outDir = path.join(OUT_ROOT, `run-${stamp}`);

  await mkdir(outDir, { recursive: true });

  log(`Каталог: ${outDir}`);
  log(`Фронтенд: ${BASE}; API: ${API}; браузер: ${CHANNEL}`);

  const browser = await chromium.launch({ channel: CHANNEL, headless: true });
  const entries = [];
  let captured = 0;
  let failed = 0;

  try {
    for (const roleName of selectedRoles) {
      const role = roleDefs[roleName];
      const password = passwordFor(roleName, passwords);

      if (!password) {
        log(`[${roleName}] пропуск: не задан пароль (CRM_PASSWORD_FILE / CRM_PASSWORD)`);
        failed++;
        continue;
      }

      let session;

      try {
        session = await apiLogin(role.login, password);
      } catch (cause) {
        log(`[${roleName}] пропуск: ${cause.message}`);
        failed++;
        continue;
      }

      const context = await collectRouteContext(role, session);
      const routes = role.routes(context).filter((route) => route.path);

      log(`[${roleName}] маршрутов: ${routes.length}`);

      for (const viewport of viewports) {
        const viewportDir = `${roleName}-${viewport.name}`;
        const dir = path.join(outDir, viewportDir);

        await mkdir(dir, { recursive: true });

        const browserContext = await browser.newContext({
          viewport: { width: viewport.width, height: viewport.height },
          deviceScaleFactor: 1,
          locale: 'ru-RU',
          timezoneId: 'Europe/Moscow'
        });

        await browserContext.addInitScript(
          ([token, user]) => {
            localStorage.setItem('crm_token', token);
            localStorage.setItem('crm_user', user);
          },
          [session.token, JSON.stringify(session.user)]
        );

        await browserContext.addInitScript(() => {
          document.addEventListener('DOMContentLoaded', () => {
            const style = document.createElement('style');
            style.textContent =
              '*,*::before,*::after{transition:none!important;animation:none!important}';
            document.head.appendChild(style);
          });
        });

        const page = await browserContext.newPage();

        for (const route of routes) {
          const url = `${BASE}/#${route.path}`;
          const fileName = `${route.name}.png`;
          const filePath = path.join(dir, fileName);
          let error = null;

          try {
            if (route.clicks?.length) {
              await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
              await page.waitForTimeout(SETTLE_MS);
              await page.reload({ waitUntil: 'domcontentloaded', timeout: 30000 });
              await page.waitForTimeout(SETTLE_MS);

              for (const label of route.clicks) {
                await page.getByRole('button', { name: label, exact: true }).first().click();
                await page.waitForTimeout(700);
              }
            } else {
              await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30000 });
              await page.waitForTimeout(SETTLE_MS);
            }

            await page.evaluate(() => window.scrollTo(0, 0));

            if (CAPTURE === 'viewport' || CAPTURE === 'both') {
              await page.screenshot({ path: filePath, fullPage: false });
            }

            if (CAPTURE === 'full' || CAPTURE === 'both') {
              await page.screenshot({
                path: filePath.replace(/\.png$/, '-full.png'),
                fullPage: true
              });
            }

            captured++;
            log(`  ✓ ${viewportDir}/${fileName}`);
          } catch (cause) {
            error = String(cause?.message ?? cause).split('\n')[0];
            failed++;
            log(`  ✗ ${viewportDir}/${fileName}: ${error}`);
          }

          entries.push({
            role: roleName,
            viewport: viewport.name,
            route: route.name,
            url,
            file: error ? null : path.join(viewportDir, fileName),
            width: viewport.width,
            height: viewport.height,
            error
          });
        }

        await browserContext.close();
      }
    }
  } finally {
    await browser.close();
  }

  const manifest = {
    capturedAt: new Date().toISOString(),
    baseUrl: BASE,
    apiUrl: API,
    roles: selectedRoles,
    viewports: viewports.map((viewport) => viewport.name),
    captured,
    failed,
    entries
  };

  await writeFile(path.join(outDir, 'manifest.json'), JSON.stringify(manifest, null, 2), 'utf8');
  await writeFile(
    path.join(outDir, 'index.html'),
    galleryHtml(entries, `Дизайн-ревью CRM7 — ${stamp}`),
    'utf8'
  );

  log(`Готово: снимков ${captured}, ошибок ${failed}`);
  log(`Галерея: ${path.join(outDir, 'index.html')}`);
  log('Дальше: отдать скриншоты vision-агенту с промптом из docs/design-review.md');

  if (captured === 0) {
    process.exitCode = 1;
  }
}

main().catch((cause) => {
  process.stderr.write(`${String(cause?.stack ?? cause)}\n`);
  process.exitCode = 1;
});
