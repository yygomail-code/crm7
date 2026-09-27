import { describe, expect, it } from 'vitest';
import { clampDates, clampPeriod, daysAgoIso, defaultPeriod, todayIso } from './period';

describe('period helpers', () => {
  it('defaults to the last 30 days', () => {
    const period = defaultPeriod();

    expect(period.to).toBe(todayIso());
    expect(period.from).toBe(daysAgoIso(29));
  });

  it('does not allow dates in the future', () => {
    const tomorrow = daysAgoIso(-1);
    const period = clampPeriod(tomorrow, tomorrow);

    expect(period.from).toBe(todayIso());
    expect(period.to).toBe(todayIso());
  });

  it('does not allow "to" earlier than "from"', () => {
    const period = clampPeriod(daysAgoIso(5), daysAgoIso(10));

    expect(period.from).toBe(daysAgoIso(5));
    expect(period.to).toBe(daysAgoIso(5));
  });

  it('keeps a valid period as is', () => {
    const period = clampPeriod(daysAgoIso(10), daysAgoIso(2));

    expect(period.from).toBe(daysAgoIso(10));
    expect(period.to).toBe(daysAgoIso(2));
  });

  it('replaces invalid input with the default period', () => {
    const period = clampPeriod('', 'not-a-date');

    expect(period).toEqual(defaultPeriod());
  });

  it('keeps empty dates empty (no filter)', () => {
    expect(clampDates('', '')).toEqual({ from: '', to: '' });
  });

  it('limits a single empty bound by the other one', () => {
    expect(clampDates('', daysAgoIso(3))).toEqual({ from: '', to: daysAgoIso(3) });
    expect(clampDates(daysAgoIso(3), '')).toEqual({ from: daysAgoIso(3), to: '' });
  });

  it('fixes an inverted range without touching empty values', () => {
    expect(clampDates(daysAgoIso(1), daysAgoIso(5))).toEqual({
      from: daysAgoIso(1),
      to: daysAgoIso(1)
    });
  });
});
