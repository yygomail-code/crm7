import { describe, expect, it } from 'vitest';
import { avatarColor, avatarLetter, avatarName } from './avatar';

describe('avatar helpers', () => {
  it('returns a stable color for the same name', () => {
    expect(avatarColor('Иванов Иван')).toBe(avatarColor('Иванов Иван'));
    expect(avatarColor('admin')).toBe(avatarColor('admin'));
  });

  it('returns a color in the expected format', () => {
    expect(avatarColor('Иванов Иван')).toMatch(/^hsl\(\d+, 55%, 45%\)$/);
    expect(avatarColor('manager2')).toMatch(/^hsl\(\d+, 55%, 45%\)$/);
  });

  it('changes the color when the name changes', () => {
    expect(avatarColor('Иванов Иван')).not.toBe(avatarColor('Петров Пётр'));
  });

  it('uses the first letter of the name', () => {
    expect(avatarLetter('Иванов Иван')).toBe('И');
    expect(avatarLetter('admin')).toBe('A');
    expect(avatarLetter('')).toBe('•');
    expect(avatarLetter(null)).toBe('•');
  });

  it('falls back to the login when the name is empty', () => {
    expect(avatarName('', 'client')).toBe('client');
    expect(avatarName(null, 'client')).toBe('client');
    expect(avatarName('  ', 5)).toBe('5');
    expect(avatarName('Иванов Иван', 'client')).toBe('Иванов Иван');
  });
});
