#!/usr/bin/env node
import { mkdir, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parseArgs } from 'node:util';
import {
  DEFAULT_ALPHABET,
  findFreeDomains,
  generateDomains,
  resolveSource
} from './index.mjs';

const HELP = `Автопоиск свободных доменов.

Использование:
  node tools/domain-finder/cli.mjs [опции]

Опции:
  --tld=ru                 зона (по умолчанию ru)
  --length=3               длина имени без зоны (по умолчанию 3)
  --alphabet=...           символы имени (по умолчанию a-z0-9)
  --letters-only           только латинские буквы a-z
  --count=10               сколько свободных найти (по умолчанию 10)
  --limit=1000             максимум проверок (по умолчанию 1000)
  --concurrency=2          параллельных проверок (по умолчанию 2)
  --delay=250              пауза между проверками, мс (по умолчанию 250)
  --source=auto            auto | http | whois | rdap (по умолчанию auto)
  --seed=1                 seed перемешивания
  --ordered                не перемешивать кандидатов
  --domains=abc.ru,qzx.ru  проверить конкретные домены
  --json                   вывести JSON
  --out=path               сохранить JSON в файл
  --dry-run                показать кандидатов без сетевых запросов
  --help                   эта справка

Примеры:
  node tools/domain-finder/cli.mjs --letters-only --count=20
  node tools/domain-finder/cli.mjs --count=10 --limit=300
  node tools/domain-finder/cli.mjs --domains=abc.ru,qzx.ru
  node tools/domain-finder/cli.mjs --source=whois --count=5

Важно: режим http использует сторонний сервис api.whois.vu. Статус «свободен»
означает отсутствие записи в WHOIS на момент проверки; перед покупкой
подтвердите доступность у регистратора.
`;

function fail(message) {
  console.error(`Ошибка: ${message}`);
  process.exit(1);
}

function integer(value, name, { min = 1, max = Number.MAX_SAFE_INTEGER } = {}) {
  const parsed = Number.parseInt(String(value), 10);

  if (!Number.isFinite(parsed) || parsed < min || parsed > max) {
    fail(`${name}: ожидается целое число от ${min} до ${max}`);
  }

  return parsed;
}

function normalizeDomain(value) {
  return value.trim().toLowerCase();
}

function isDomain(value) {
  return /^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.[a-z]{2,}$/i.test(value);
}

const { values } = parseArgs({
  allowPositionals: false,
  options: {
    tld: { type: 'string', default: 'ru' },
    length: { type: 'string', default: '3' },
    alphabet: { type: 'string' },
    'letters-only': { type: 'boolean', default: false },
    count: { type: 'string', default: '10' },
    limit: { type: 'string', default: '1000' },
    concurrency: { type: 'string', default: '2' },
    delay: { type: 'string', default: '250' },
    source: { type: 'string', default: 'auto' },
    seed: { type: 'string' },
    ordered: { type: 'boolean', default: false },
    domains: { type: 'string' },
    json: { type: 'boolean', default: false },
    out: { type: 'string' },
    'dry-run': { type: 'boolean', default: false },
    help: { type: 'boolean', default: false }
  }
});

if (values.help) {
  console.log(HELP);
  process.exit(0);
}

const tld = values.tld.replace(/^\./, '').toLowerCase();
const length = integer(values.length, '--length', { min: 1, max: 12 });
const count = integer(values.count, '--count', { min: 1, max: 100000 });
const limit = integer(values.limit, '--limit', { min: 1, max: 100000 });
const concurrency = integer(values.concurrency, '--concurrency', { min: 1, max: 10 });
const delay = integer(values.delay, '--delay', { min: 0, max: 60000 });
const source = String(values.source).toLowerCase();
const alphabet = values['letters-only']
  ? 'abcdefghijklmnopqrstuvwxyz'
  : (values.alphabet ?? DEFAULT_ALPHABET).toLowerCase().replace(/\s+/g, '');

if (!['auto', 'http', 'whois', 'rdap'].includes(source)) {
  fail('--source: допустимо auto, http, whois или rdap');
}

if (!/^[a-z]{2,}$/.test(tld)) {
  fail('--tld: ожидается зона из латинских букв, например ru');
}

if (alphabet.length < 2 || [...new Set(alphabet)].length < 2) {
  fail('--alphabet: нужно минимум два уникальных символа');
}

if (!/^[a-z0-9-]+$/.test(alphabet)) {
  fail('--alphabet: допустимы только a-z, 0-9 и дефис');
}

let candidates;

if (values.domains) {
  candidates = values.domains
    .split(/[,\s]+/)
    .map(normalizeDomain)
    .filter(Boolean);

  const invalid = candidates.filter((domain) => !isDomain(domain));

  if (invalid.length > 0) {
    fail(`некорректные домены: ${invalid.join(', ')}`);
  }

  candidates = [...new Set(candidates)];
} else {
  candidates = generateDomains({
    length,
    alphabet,
    tld,
    shuffle: !values.ordered,
    seed: values.seed === undefined ? Date.now() : integer(values.seed, '--seed', { min: 0 }),
    limit
  });
}

if (values['dry-run']) {
  console.log(`Кандидаты (${Math.min(candidates.length, count)}):`);

  for (const domain of candidates.slice(0, count)) {
    console.log(`  ${domain}`);
  }

  process.exit(0);
}

const checkedSource = await resolveSource(source, { tld });
const startedAt = Date.now();
const result = await findFreeDomains(candidates, {
  count,
  limit,
  concurrency,
  delayMs: delay,
  source: checkedSource,
  onResult: (item) => {
    if (!values.json) {
      console.log(`  + ${item.domain}`);
    }
  }
});

const payload = {
  tld,
  length,
  alphabet,
  source: checkedSource,
  requested: candidates.length,
  checked: result.checked,
  free: result.free.map((item) => item.domain),
  taken: result.taken,
  unknown: result.unknown,
  unknownDomains: result.unknownDomains,
  errors: result.errors,
  durationMs: Date.now() - startedAt,
  generatedAt: new Date().toISOString()
};

if (values.out) {
  const outputPath = path.resolve(process.cwd(), values.out);
  await mkdir(path.dirname(outputPath), { recursive: true });
  await writeFile(outputPath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');

  if (!values.json) {
    console.log(`\nJSON сохранён: ${outputPath}`);
  }
}

if (values.json) {
  console.log(JSON.stringify(payload, null, 2));
  process.exit(0);
}

console.log('');
console.log(`Свободные домены (${payload.free.length}):`);

if (payload.free.length === 0) {
  console.log('  не найдено — попробуйте увеличить --limit или другой алфавит');
} else {
  for (const domain of payload.free) {
    console.log(`  ${domain}`);
  }
}

console.log('');
console.log(
  `Проверено: ${payload.checked}; занято: ${payload.taken}; неизвестно: ${payload.unknown}; ошибок: ${payload.errors.length}`
);

if (payload.errors.length > 0) {
  console.log('Первые ошибки:');

  for (const error of payload.errors.slice(0, 5)) {
    console.log(`  ${error.domain}: ${error.message}`);
  }
}

if (payload.unknownDomains.length > 0) {
  console.log(`Не удалось определить (${payload.unknownDomains.length}):`);

  for (const domain of payload.unknownDomains.slice(0, 10)) {
    console.log(`  ${domain}`);
  }
}

if (checkedSource === 'http') {
  console.log('\nИсточник: api.whois.vu (сторонний). Подтвердите доступность у регистратора.');
}
