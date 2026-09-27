const PREFIX = 'crm.search.';
const LIMIT = 10;

export function clearHistory(): void {
  try {
    const keys: string[] = [];

    for (let index = 0; index < localStorage.length; index += 1) {
      const key = localStorage.key(index);

      if (key !== null && key.startsWith(PREFIX)) {
        keys.push(key);
      }
    }

    keys.forEach((key) => localStorage.removeItem(key));
  } catch {
    // приватный режим — истории нет
  }
}

export function pushQuery(list: string[], query: string): string[] {
  const value = query.trim();

  if (value === '') {
    return list;
  }

  return [value, ...list.filter((item) => item !== value)].slice(0, LIMIT);
}

export function getHistory(key: string): string[] {
  try {
    const raw = localStorage.getItem(PREFIX + key);
    const parsed: unknown = raw ? JSON.parse(raw) : [];

    return Array.isArray(parsed)
      ? parsed.filter((item): item is string => typeof item === 'string' && item !== '').slice(0, LIMIT)
      : [];
  } catch {
    return [];
  }
}

export function addHistory(key: string, query: string): void {
  const next = pushQuery(getHistory(key), query);

  try {
    localStorage.setItem(PREFIX + key, JSON.stringify(next));
  } catch {
    // приватный режим — история не сохраняется
  }
}
