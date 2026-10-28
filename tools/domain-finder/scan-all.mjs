#!/usr/bin/env node
import { appendFile, mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parseArgs } from 'node:util';
import { DEFAULT_ALPHABET, checkDomain, generateDomains } from './index.mjs';

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function integer(value, name, { min = 1, max = Number.MAX_SAFE_INTEGER } = {}) {
  const parsed = Number.parseInt(String(value), 10);

  if (!Number.isFinite(parsed) || parsed < min || parsed > max) {
    console.error(`Ошибка: ${name}: ожидается целое число от ${min} до ${max}`);
    process.exit(1);
  }

  return parsed;
}

function formatDuration(ms) {
  const totalSeconds = Math.round(ms / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  return [hours ? `${hours}ч` : '', minutes ? `${minutes}м` : '', `${seconds}с`]
    .filter(Boolean)
    .join(' ');
}

const { values } = parseArgs({
  allowPositionals: false,
  options: {
    tld: { type: 'string', default: 'ru' },
    length: { type: 'string', default: '3' },
    alphabet: { type: 'string', default: DEFAULT_ALPHABET },
    concurrency: { type: 'string', default: '4' },
    delay: { type: 'string', default: '60' },
    source: { type: 'string', default: 'http' },
    limit: { type: 'string' },
    state: { type: 'string', default: 'domain-finder-state.jsonl' },
    out: { type: 'string', default: 'domain-finder-result.json' },
    'progress-every': { type: 'string', default: '100' }
  }
});

const tld = values.tld.replace(/^\./, '').toLowerCase();
const length = integer(values.length, '--length', { min: 1, max: 12 });
const alphabet = values.alphabet.toLowerCase().replace(/\s+/g, '');
const concurrency = integer(values.concurrency, '--concurrency', { min: 1, max: 32 });
const delay = integer(values.delay, '--delay', { min: 0, max: 60000 });
const source = values.source.toLowerCase();
const progressEvery = integer(values['progress-every'], '--progress-every', { min: 1 });
const limit = values.limit === undefined
  ? Infinity
  : integer(values.limit, '--limit', { min: 1, max: 10_000_000 });

if (!['http', 'whois'].includes(source)) {
  console.error('Ошибка: --source: для полного перебора допустимо http или whois');
  process.exit(1);
}

if (!/^[a-z]{2,}$/.test(tld)) {
  console.error('Ошибка: --tld: ожидается зона из латинских букв');
  process.exit(1);
}

if (!/^[a-z0-9-]+$/.test(alphabet) || [...new Set(alphabet)].length < 2) {
  console.error('Ошибка: --alphabet: нужны минимум два уникальных символа a-z0-9-');
  process.exit(1);
}

const statePath = path.resolve(process.cwd(), values.state);
const outPath = path.resolve(process.cwd(), values.out);

await mkdir(path.dirname(statePath), { recursive: true });
await mkdir(path.dirname(outPath), { recursive: true });

const results = new Map();

try {
  const raw = await readFile(statePath, 'utf8');

  for (const line of raw.split(/\r?\n/)) {
    if (!line.trim()) {
      continue;
    }

    try {
      const item = JSON.parse(line);
      results.set(item.domain, item);
    } catch {
      // пропускаем повреждённую строку — остальной кэш остаётся рабочим
    }
  }
} catch (error) {
  if (error.code !== 'ENOENT') {
    throw error;
  }
}

const domains = generateDomains({
  length,
  alphabet,
  tld,
  shuffle: false,
  limit
});

const pending = domains.filter((domain) => !results.has(domain));
let cursor = 0;
let completed = 0;
const startedAt = Date.now();
let writeChain = Promise.resolve();

function persist(item) {
  writeChain = writeChain.then(() =>
    appendFile(statePath, `${JSON.stringify(item)}\n`, 'utf8')
  );

  return writeChain;
}

function resultCounters() {
  const counters = { free: 0, taken: 0, unknown: 0, errors: 0 };

  for (const item of results.values()) {
    if (item.status === 'error') {
      counters.errors += 1;
    } else if (item.status === 'free') {
      counters.free += 1;
    } else if (item.status === 'taken') {
      counters.taken += 1;
    } else {
      counters.unknown += 1;
    }
  }

  return counters;
}

console.log(
  `[scan] tld=${tld} length=${length} total=${domains.length} cached=${results.size} pending=${pending.length}`
);

async function worker() {
  while (cursor < pending.length) {
    const index = cursor;
    cursor += 1;

    const domain = pending[index];
    let item;

    try {
      const result = await checkDomain(domain, { source });
      item = {
        domain,
        status: result.status,
        source: result.source,
        detail: result.detail ?? '',
        checkedAt: new Date().toISOString()
      };
    } catch (error) {
      item = {
        domain,
        status: 'error',
        source,
        error: error.message,
        checkedAt: new Date().toISOString()
      };
    }

    results.set(domain, item);
    completed += 1;
    await persist(item);

    if (completed % progressEvery === 0 || completed === pending.length) {
      const elapsed = Date.now() - startedAt;
      const rate = completed / Math.max(1, elapsed / 1000);
      const eta = (pending.length - completed) / Math.max(0.001, rate);
      const counters = resultCounters();

      console.log(
        `[scan] ${completed}/${pending.length} free=${counters.free} taken=${counters.taken} unknown=${counters.unknown} errors=${counters.errors} ` +
          `rate=${rate.toFixed(2)}/s eta=${formatDuration(eta)}`
      );
    }

    if (delay > 0) {
      await sleep(delay);
    }
  }
}

await Promise.all(Array.from({ length: concurrency }, () => worker()));
await writeChain;

const failed = [...results.values()].filter((item) => item.status === 'error');

if (failed.length > 0) {
  console.log(`[scan] повторная проверка ошибок: ${failed.length}`);

  for (const item of failed) {
    try {
      const retry = await checkDomain(item.domain, { source });
      const next = {
        domain: item.domain,
        status: retry.status,
        source: retry.source,
        detail: retry.detail ?? '',
        checkedAt: new Date().toISOString()
      };

      results.set(item.domain, next);
      await persist(next);
    } catch (error) {
      await persist({
        domain: item.domain,
        status: 'error',
        source,
        error: error.message,
        checkedAt: new Date().toISOString()
      });
    }

    await sleep(500);
  }
}

const all = [...results.values()];
const finishedAt = new Date().toISOString();
const payload = {
  tld,
  length,
  alphabet,
  source,
  total: domains.length,
  checked: all.filter((item) => item.status !== 'error').length,
  free: all
    .filter((item) => item.status === 'free')
    .map((item) => item.domain)
    .sort(),
  taken: all.filter((item) => item.status === 'taken').length,
  unknown: all.filter((item) => item.status === 'unknown').length,
  errors: all.filter((item) => item.status === 'error').length,
  startedAt: new Date(startedAt).toISOString(),
  finishedAt,
  durationMs: Date.now() - startedAt
};

await writeFile(outPath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');

console.log(
  `[scan] готово: free=${payload.free.length} taken=${payload.taken} unknown=${payload.unknown} errors=${payload.errors}`
);
console.log(`[scan] результат: ${outPath}`);
console.log(`[scan] кэш: ${statePath}`);
