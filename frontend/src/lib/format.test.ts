import { describe, expect, it, vi } from 'vitest';
import { formatMinutes, formatRelative, formatSize, priorityLabel } from './format';

describe('formatMinutes', () => {
  it('returns dash for null', () => {
    expect(formatMinutes(null)).toBe('—');
  });

  it('formats minutes and hours', () => {
    expect(formatMinutes(45)).toBe('45 мин');
    expect(formatMinutes(60)).toBe('1 ч');
    expect(formatMinutes(135)).toBe('2 ч 15 мин');
  });
});

describe('formatSize', () => {
  it('formats bytes, kilobytes and megabytes', () => {
    expect(formatSize(500)).toBe('500 Б');
    expect(formatSize(2048)).toBe('2.0 КБ');
    expect(formatSize(5 * 1024 * 1024)).toBe('5.0 МБ');
  });
});

describe('priorityLabel', () => {
  it('maps priority codes', () => {
    expect(priorityLabel(3)).toBe('Высокий');
    expect(priorityLabel(2)).toBe('Обычный');
    expect(priorityLabel(1)).toBe('Низкий');
  });
});

describe('formatRelative', () => {
  it('handles empty values', () => {
    expect(formatRelative(null)).toBe('');
  });

  it('formats recent timestamps', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-20T12:00:00'));

    expect(formatRelative('2026-09-20 11:59:30')).toBe('только что');
    expect(formatRelative('2026-09-20 11:30:00')).toBe('30 мин назад');
    expect(formatRelative('2026-09-20 09:00:00')).toBe('3 ч назад');
    expect(formatRelative('2026-09-19 12:00:00')).toBe('вчера');
    expect(formatRelative('2026-09-17 12:00:00')).toBe('3 дн назад');

    vi.useRealTimers();
  });

  it('falls back to full date for old values', () => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-09-20T12:00:00'));

    const result = formatRelative('2026-01-05 10:00:00');
    expect(result).toContain('2026');

    vi.useRealTimers();
  });
});
