const PREFIX = 'crm.search.';
const LIMIT = 10;

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
