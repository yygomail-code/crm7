const PREFIX = 'crm.filters.';

export function loadFilters<T extends Record<string, unknown>>(key: string, defaults: T): T {
  try {
    const raw = localStorage.getItem(PREFIX + key);

    if (raw === null) {
      return { ...defaults };
    }

    const parsed: unknown = JSON.parse(raw);

    if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
      return { ...defaults };
    }

    const result: Record<string, unknown> = { ...defaults };

    for (const field of Object.keys(defaults)) {
      const value = (parsed as Record<string, unknown>)[field];

      if (value === undefined || value === null) {
        continue;
      }

      if (typeof value === typeof defaults[field]) {
        result[field] = value;
      }
    }

    return result as T;
  } catch {
    return { ...defaults };
  }
}

export function saveFilters(key: string, value: Record<string, unknown>): void {
  try {
    localStorage.setItem(PREFIX + key, JSON.stringify(value));
  } catch {
    // приватный режим - молча пропускаем
  }
}

export function clearFilters(key: string): void {
  try {
    localStorage.removeItem(PREFIX + key);
  } catch {
    // приватный режим - молча пропускаем
  }
}

export function countActive<T extends Record<string, unknown>>(value: T, defaults: T): number {
  return Object.keys(defaults).filter(
    (field) => String(value[field] ?? '') !== String(defaults[field] ?? '')
  ).length;
}
