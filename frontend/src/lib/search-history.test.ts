import { describe, expect, it } from 'vitest';
import { pushQuery } from './search-history';

describe('search history', () => {
  it('ignores empty queries', () => {
    expect(pushQuery([], '   ')).toEqual([]);
  });

  it('puts the newest query first', () => {
    expect(pushQuery(['б'], 'а')).toEqual(['а', 'б']);
  });

  it('moves a repeated query to the front', () => {
    expect(pushQuery(['а', 'б', 'в'], 'б')).toEqual(['б', 'а', 'в']);
  });

  it('keeps at most ten queries', () => {
    const list = Array.from({ length: 10 }, (_, index) => `q${index}`);

    expect(pushQuery(list, 'new')).toHaveLength(10);
    expect(pushQuery(list, 'new')[0]).toBe('new');
    expect(pushQuery(list, 'new')).not.toContain('q9');
  });

  it('trims the query', () => {
    expect(pushQuery([], '  альпина  ')).toEqual(['альпина']);
  });
});
