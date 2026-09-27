import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { clearFilters, countActive, loadFilters, saveFilters } from './filters';

const storage = new Map<string, string>();

beforeEach(() => {
  storage.clear();
  Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: {
      getItem: (key: string) => storage.get(key) ?? null,
      setItem: (key: string, value: string) => void storage.set(key, value),
      removeItem: (key: string) => void storage.delete(key),
      key: (index: number) => [...storage.keys()][index] ?? null,
      get length() {
        return storage.size;
      }
    }
  });
});

afterEach(() => {
  storage.clear();
});

const defaults = { status: '', scope: 'mine', page: 1 };

describe('filters storage', () => {
  it('returns defaults when nothing saved', () => {
    expect(loadFilters('requests', defaults)).toEqual(defaults);
  });

  it('round-trips saved values', () => {
    saveFilters('requests', { status: 'new', scope: '', page: 3 });
    expect(loadFilters('requests', defaults)).toEqual({ status: 'new', scope: '', page: 3 });
  });

  it('keeps defaults for unknown fields and wrong types', () => {
    storage.set('crm.filters.requests', JSON.stringify({ status: 42, scope: 'others', extra: true }));
    expect(loadFilters('requests', defaults)).toEqual({ status: '', scope: 'others', page: 1 });
  });

  it('survives broken json', () => {
    storage.set('crm.filters.requests', '{oops');
    expect(loadFilters('requests', defaults)).toEqual(defaults);
  });

  it('clears saved values', () => {
    saveFilters('requests', { status: 'new', scope: '', page: 3 });
    clearFilters('requests');
    expect(loadFilters('requests', defaults)).toEqual(defaults);
  });

  it('counts changed fields only', () => {
    expect(countActive(defaults, defaults)).toBe(0);
    expect(countActive({ ...defaults, status: 'new' }, defaults)).toBe(1);
    expect(countActive({ status: 'new', scope: '', page: 3 }, defaults)).toBe(3);
  });
});
