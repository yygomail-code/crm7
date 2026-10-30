import { describe, expect, it } from 'vitest';
import { buildPageItems } from './pagination';

describe('buildPageItems', () => {
  it('returns empty list for zero pages', () => {
    expect(buildPageItems(1, 0)).toEqual([]);
  });

  it('lists all pages when total is small', () => {
    expect(buildPageItems(2, 5)).toEqual([1, 2, 3, 4, 5]);
    expect(buildPageItems(1, 7)).toEqual([1, 2, 3, 4, 5, 6, 7]);
  });

  it('adds trailing ellipsis at the start', () => {
    expect(buildPageItems(1, 10)).toEqual([1, 2, 3, 4, 5, '...', 10]);
    expect(buildPageItems(3, 10)).toEqual([1, 2, 3, 4, 5, '...', 10]);
  });

  it('adds leading ellipsis at the end', () => {
    expect(buildPageItems(8, 10)).toEqual([1, '...', 6, 7, 8, 9, 10]);
    expect(buildPageItems(10, 10)).toEqual([1, '...', 6, 7, 8, 9, 10]);
  });

  it('adds both ellipsis in the middle', () => {
    expect(buildPageItems(5, 10)).toEqual([1, '...', 4, 5, 6, '...', 10]);
  });
});
