import { beforeEach, describe, expect, it, vi } from 'vitest';

vi.mock('../api/profile', () => ({
  loadAvatarUrl: vi.fn(),
  invalidateAvatar: vi.fn()
}));

import { invalidateAvatar, loadAvatarUrl } from '../api/profile';
import { avatars } from './avatar.svelte';

const mockedLoad = vi.mocked(loadAvatarUrl);
const mockedInvalidate = vi.mocked(invalidateAvatar);

describe('avatar store', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('returns null for a user without a loaded avatar', () => {
    expect(avatars.url(null)).toBeNull();
    expect(avatars.url(999)).toBeNull();
  });

  it('loads the avatar once and caches it', async () => {
    mockedLoad.mockResolvedValue('blob:user-11');

    await avatars.load(11);
    await avatars.load(11);

    expect(avatars.url(11)).toBe('blob:user-11');
    expect(mockedLoad).toHaveBeenCalledTimes(1);
    expect(mockedLoad).toHaveBeenCalledWith(11);
  });

  it('deduplicates concurrent loads', async () => {
    let resolve!: (value: string | null) => void;

    mockedLoad.mockImplementation(
      () =>
        new Promise<string | null>((done) => {
          resolve = done;
        })
    );

    const first = avatars.load(12);
    const second = avatars.load(12);

    resolve('blob:user-12');
    await Promise.all([first, second]);

    expect(mockedLoad).toHaveBeenCalledTimes(1);
    expect(avatars.url(12)).toBe('blob:user-12');
  });

  it('refreshes the avatar after a new upload', async () => {
    mockedLoad.mockResolvedValueOnce('blob:user-13-old');
    await avatars.load(13);

    mockedLoad.mockResolvedValueOnce('blob:user-13-new');
    await avatars.refresh(13);

    expect(mockedInvalidate).toHaveBeenCalledWith(13);
    expect(avatars.url(13)).toBe('blob:user-13-new');
  });
});
