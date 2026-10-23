import { describe, expect, it } from 'vitest';
import { badgeForRoute, pageBadges } from './page-badges';

const badges = {
  '/': { id: 'HOME', task: '' },
  '/requests': { id: 'REQ-LIST', task: '  фильтры  ' },
  '/requests/new': { id: 'REQ-NEW', task: '' },
  '/requests/:id': { id: 'REQ-CARD', task: '' },
  '/warehouses/:id/edit': { id: 'WH-EDIT', task: '' },
  '/warehouses/:id': { id: 'WH-VIEW', task: '' },
  '/hidden': { id: '', task: 'не показывать' }
};

describe('badgeForRoute', () => {
  it('matches exact routes', () => {
    expect(badgeForRoute('/requests', badges)).toEqual({
      route: '/requests',
      id: 'REQ-LIST',
      task: 'фильтры'
    });
  });

  it('prefers an exact route over a pattern', () => {
    expect(badgeForRoute('/requests/new', badges)?.id).toBe('REQ-NEW');
  });

  it('matches dynamic routes', () => {
    expect(badgeForRoute('/requests/42', badges)?.id).toBe('REQ-CARD');
  });

  it('prefers a more specific pattern', () => {
    expect(badgeForRoute('/warehouses/5/edit', badges)?.id).toBe('WH-EDIT');
    expect(badgeForRoute('/warehouses/5', badges)?.id).toBe('WH-VIEW');
  });

  it('hides a badge with an empty id', () => {
    expect(badgeForRoute('/hidden', badges)).toBeNull();
  });

  it('returns null for unknown routes', () => {
    expect(badgeForRoute('/unknown', badges)).toBeNull();
  });

  it('covers every route registered for the app', () => {
    expect(pageBadges['/requests/:id'].id).toBe('REQ-CARD');
    expect(badgeForRoute('/stocks/items/7/edit')?.id).toBe('STK-ITEM-EDIT');
    expect(badgeForRoute('/legal/privacy')?.id).toBe('LEGAL');
  });
});
