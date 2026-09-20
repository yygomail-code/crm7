import { describe, expect, it } from 'vitest';
import { matchRoute } from './match-route';

describe('matchRoute', () => {
  it('matches static paths', () => {
    expect(matchRoute('/requests', '/requests')).toEqual({});
    expect(matchRoute('/requests', '/clients')).toBeNull();
  });

  it('extracts parameters', () => {
    expect(matchRoute('/requests/:id', '/requests/42')).toEqual({ id: '42' });
    expect(matchRoute('/clients/:id', '/clients/7')).toEqual({ id: '7' });
  });

  it('respects segment count', () => {
    expect(matchRoute('/requests/:id', '/requests/42/edit')).toBeNull();
    expect(matchRoute('/requests/:id', '/requests')).toBeNull();
  });

  it('decodes parameter values', () => {
    expect(matchRoute('/clients/:id', '/clients/%D0%9A%D0%BB%D0%B8%D0%B5%D0%BD%D1%82')).toEqual({
      id: 'Клиент'
    });
  });
});
