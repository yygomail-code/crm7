export function isoDate(date: Date): string {
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');

  return `${date.getFullYear()}-${month}-${day}`;
}

export function todayIso(): string {
  return isoDate(new Date());
}

export function daysAgoIso(days: number): string {
  const date = new Date();
  date.setDate(date.getDate() - days);

  return isoDate(date);
}

export function defaultPeriod(days = 30): { from: string; to: string } {
  return { from: daysAgoIso(days - 1), to: todayIso() };
}

export function clampDates(from: string, to: string): { from: string; to: string } {
  const today = todayIso();
  let nextFrom = from;
  let nextTo = to;

  if (nextFrom > today) {
    nextFrom = today;
  }

  if (nextTo > today) {
    nextTo = today;
  }

  if (nextFrom !== '' && nextTo !== '' && nextTo < nextFrom) {
    nextTo = nextFrom;
  }

  return { from: nextFrom, to: nextTo };
}

export function clampPeriod(from: string, to: string): { from: string; to: string } {
  const fallback = defaultPeriod();
  const validFrom = /^\d{4}-\d{2}-\d{2}$/.test(from) ? from : fallback.from;
  const validTo = /^\d{4}-\d{2}-\d{2}$/.test(to) ? to : fallback.to;

  return clampDates(validFrom, validTo);
}
