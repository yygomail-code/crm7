import { invalidateAvatar, loadAvatarUrl } from '../api/profile';

class AvatarStore {
  private urls = $state<Record<number, string | null>>({});

  private pending = new Map<number, Promise<void>>();

  url(userId: number | null | undefined): string | null {
    if (!userId) {
      return null;
    }

    return this.urls[userId] ?? null;
  }

  load(userId: number): Promise<void> {
    if (this.urls[userId] !== undefined) {
      return Promise.resolve();
    }

    const running = this.pending.get(userId);

    if (running) {
      return running;
    }

    const task = loadAvatarUrl(userId)
      .then((url) => {
        this.urls[userId] = url;
      })
      .finally(() => {
        this.pending.delete(userId);
      });

    this.pending.set(userId, task);

    return task;
  }

  async refresh(userId: number): Promise<void> {
    invalidateAvatar(userId);

    const previous = this.urls[userId] ?? null;
    const url = await loadAvatarUrl(userId);

    this.urls[userId] = url;

    if (previous !== null && previous !== url) {
      URL.revokeObjectURL(previous);
    }
  }
}

export const avatars = new AvatarStore();
