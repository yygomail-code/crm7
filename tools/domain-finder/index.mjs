import net from 'node:net';

export const DEFAULT_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';
export const HTTP_ENDPOINT = 'https://api.whois.vu/';
export const WHOIS_HOST = 'whois.tcinet.ru';
export const WHOIS_PORT = 43;
export const RDAP_BASES = {
  com: 'https://rdap.verisign.com/com/v1/domain/',
  net: 'https://rdap.verisign.com/net/v1/domain/'
};

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function mulberry32(seed) {
  let value = seed >>> 0;

  return () => {
    value |= 0;
    value = (value + 0x6d2b79f5) | 0;
    let t = Math.imul(value ^ (value >>> 15), 1 | value);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

function shuffleInPlace(items, seed) {
  const random = mulberry32(seed);

  for (let index = items.length - 1; index > 0; index -= 1) {
    const swapIndex = Math.floor(random() * (index + 1));
    [items[index], items[swapIndex]] = [items[swapIndex], items[index]];
  }
}

function backoff(attempt) {
  return 500 * 2 ** attempt + Math.floor(Math.random() * 250);
}

export function* labelCombinations(length, alphabet, prefix = '') {
  if (prefix.length === length) {
    yield prefix;
    return;
  }

  for (const character of alphabet) {
    yield* labelCombinations(length, alphabet, prefix + character);
  }
}

export function generateDomains({
  length = 3,
  alphabet = DEFAULT_ALPHABET,
  tld = 'ru',
  shuffle = true,
  seed = Date.now(),
  limit = Infinity
} = {}) {
  if (!Number.isInteger(length) || length < 1) {
    throw new Error('length должен быть положительным целым числом');
  }

  const characters = [...new Set(alphabet.replace(/\s+/g, ''))];

  if (characters.length < 2) {
    throw new Error('alphabet должен содержать минимум два символа');
  }

  if (!shuffle) {
    const domains = [];

    for (const label of labelCombinations(length, characters)) {
      if (label.startsWith('-') || label.endsWith('-')) {
        continue;
      }

      domains.push(`${label}.${tld}`);

      if (domains.length >= limit) {
        break;
      }
    }

    return domains;
  }

  const total = characters.length ** length;

  if (limit >= total || total <= 200_000) {
    const domains = [];

    for (const label of labelCombinations(length, characters)) {
      if (label.startsWith('-') || label.endsWith('-')) {
        continue;
      }

      domains.push(`${label}.${tld}`);
    }

    shuffleInPlace(domains, seed);

    return domains.slice(0, limit);
  }

  const random = mulberry32(seed);
  const domains = new Set();
  let attempts = 0;
  const maxAttempts = Math.max(limit * 100, 1000);

  while (domains.size < limit && attempts < maxAttempts) {
    attempts += 1;

    let label = '';

    for (let index = 0; index < length; index += 1) {
      label += characters[Math.floor(random() * characters.length)];
    }

    if (label.startsWith('-') || label.endsWith('-')) {
      continue;
    }

    domains.add(`${label}.${tld}`);
  }

  return [...domains];
}

export function checkViaWhois(
  domain,
  { host = WHOIS_HOST, port = WHOIS_PORT, timeoutMs = 10000 } = {}
) {
  return new Promise((resolve, reject) => {
    const socket = net.createConnection({ host, port });
    let data = '';
    let finished = false;

    const finish = (error, result) => {
      if (finished) {
        return;
      }

      finished = true;

      if (error) {
        socket.destroy();
        reject(error);
        return;
      }

      socket.destroy();
      resolve(result);
    };

    socket.setEncoding('utf8');
    socket.setTimeout(timeoutMs, () => finish(new Error('WHOIS timeout')));
    socket.on('connect', () => socket.write(`${domain}\r\n`));
    socket.on('data', (chunk) => {
      data += chunk;

      if (data.length > 2_000_000) {
        finish(new Error('WHOIS response too large'));
      }
    });
    socket.on('end', () => {
      const lower = data.toLowerCase();

      if (/(^|\n)domain:\s/i.test(data)) {
        finish(null, { domain, status: 'taken', source: 'whois' });
        return;
      }

      if (/no entries found|no match for|not found/.test(lower)) {
        finish(null, { domain, status: 'free', source: 'whois' });
        return;
      }

      finish(null, { domain, status: 'unknown', source: 'whois' });
    });
    socket.on('error', (error) => finish(error));
  });
}

export async function checkViaHttp(domain, { timeoutMs = 15000, retries = 2 } = {}) {
  let lastError = new Error('HTTP check failed');

  for (let attempt = 0; attempt <= retries; attempt += 1) {
    try {
      const url = new URL(HTTP_ENDPOINT);
      url.searchParams.set('q', domain);
      url.searchParams.set('clean', '1');

      const response = await fetch(url, {
        headers: {
          Accept: 'application/json',
          'User-Agent': 'crm7-domain-finder/1.0'
        },
        signal: AbortSignal.timeout(timeoutMs)
      });

      if (response.status === 429) {
        lastError = new Error('HTTP 429: слишком много запросов');
        const retryAfter = Number(response.headers.get('retry-after')) || 2;

        if (attempt < retries) {
          await sleep(retryAfter * 1000);
        }

        continue;
      }

      if (!response.ok) {
        lastError = new Error(`HTTP ${response.status}`);

        if (attempt < retries) {
          await sleep(backoff(attempt));
        }

        continue;
      }

      const data = await response.json();
      const reportedDomain = String(data.domain ?? '').toLowerCase();

      if (reportedDomain && reportedDomain !== domain.toLowerCase()) {
        throw new Error(`API вернул другой домен: ${data.domain}`);
      }

      if (data.available === 'yes') {
        return { domain, status: 'free', source: 'http' };
      }

      if (data.available === 'no') {
        return { domain, status: 'taken', source: 'http' };
      }

      return {
        domain,
        status: 'unknown',
        source: 'http',
        detail: String(data.available ?? '')
      };
    } catch (error) {
      lastError = error;

      if (attempt < retries) {
        await sleep(backoff(attempt));
      }
    }
  }

  throw lastError;
}

export async function checkViaRdap(domain, { timeoutMs = 15000, retries = 2, baseUrl } = {}) {
  const tld = domain.split('.').pop()?.toLowerCase() ?? '';
  const base = baseUrl ?? RDAP_BASES[tld];

  if (!base) {
    throw new Error(`RDAP не поддерживается для .${tld}`);
  }

  let lastError = new Error('RDAP check failed');

  for (let attempt = 0; attempt <= retries; attempt += 1) {
    try {
      const response = await fetch(`${base}${encodeURIComponent(domain)}`, {
        headers: {
          Accept: 'application/rdap+json',
          'User-Agent': 'crm7-domain-finder/1.0'
        },
        signal: AbortSignal.timeout(timeoutMs)
      });

      if (response.status === 404) {
        return { domain, status: 'free', source: 'rdap' };
      }

      if (response.status === 200) {
        return { domain, status: 'taken', source: 'rdap' };
      }

      if (response.status === 429 || response.status >= 500) {
        lastError = new Error(`RDAP HTTP ${response.status}`);

        if (attempt < retries) {
          await sleep(backoff(attempt));
        }

        continue;
      }

      return {
        domain,
        status: 'unknown',
        source: 'rdap',
        detail: `HTTP ${response.status}`
      };
    } catch (error) {
      lastError = error;

      if (attempt < retries) {
        await sleep(backoff(attempt));
      }
    }
  }

  throw lastError;
}

export async function checkDomain(domain, { source = 'http' } = {}) {
  if (source === 'http') {
    return checkViaHttp(domain);
  }

  if (source === 'whois') {
    return checkViaWhois(domain);
  }

  if (source === 'rdap') {
    return checkViaRdap(domain);
  }

  const tld = domain.split('.').pop()?.toLowerCase() ?? '';

  if (RDAP_BASES[tld]) {
    return checkViaRdap(domain);
  }

  try {
    return await checkViaWhois(domain);
  } catch {
    return checkViaHttp(domain);
  }
}

export async function resolveSource(source = 'auto', { tld = 'ru' } = {}) {
  if (source === 'http' || source === 'whois' || source === 'rdap') {
    return source;
  }

  if (RDAP_BASES[tld.toLowerCase()]) {
    return 'rdap';
  }

  try {
    await checkViaWhois('nic.ru', { timeoutMs: 3000 });
    return 'whois';
  } catch {
    return 'http';
  }
}

export async function findFreeDomains(
  domains,
  {
    count = 10,
    limit = 1000,
    concurrency = 2,
    delayMs = 250,
    source = 'http',
    onResult
  } = {}
) {
  const candidates = domains.slice(0, limit);
  const stats = {
    checked: 0,
    free: [],
    taken: 0,
    unknown: 0,
    unknownDomains: [],
    errors: []
  };

  let cursor = 0;
  let stopped = false;

  const worker = async () => {
    while (!stopped) {
      if (stats.free.length >= count) {
        stopped = true;
        return;
      }

      const index = cursor;
      cursor += 1;

      if (index >= candidates.length) {
        return;
      }

      const domain = candidates[index];

      try {
        const result = await checkDomain(domain, { source });
        stats.checked += 1;

        if (result.status === 'free') {
          stats.free.push(result);
          onResult?.(result, stats);

          if (stats.free.length >= count) {
            stopped = true;
          }
        } else if (result.status === 'taken') {
          stats.taken += 1;
        } else {
          stats.unknown += 1;
          stats.unknownDomains.push(domain);
        }
      } catch (error) {
        stats.errors.push({ domain, message: error.message });
      }

      if (!stopped && delayMs > 0) {
        await sleep(delayMs);
      }
    }
  };

  await Promise.all(
    Array.from({ length: Math.max(1, concurrency) }, () => worker())
  );

  return {
    ...stats,
    free: stats.free.slice(0, count)
  };
}
