import { apiRequest } from '../api/client';

export const FILTER_STORAGE_PREFIX = 'crm.filters.';

interface FiltersResponse {
  filters: Record<string, Record<string, unknown>>;
}

class FilterPrefsStore {
  private timers: Record<string, ReturnType<typeof setTimeout>> = {};

  loadedFor = $state<number | null>(null);

  /**
   * Загружает настройки фильтров пользователя из БД и наполняет ими локальный кэш,
   * чтобы они были доступны синхронно и переносились между устройствами/сессиями.
   */
  async load(userId: number): Promise<void> {
    try {
      const data = await apiRequest<FiltersResponse>('/preferences/filters', { auth: true });

      for (const [key, value] of Object.entries(data.filters ?? {})) {
        try {
          localStorage.setItem(FILTER_STORAGE_PREFIX + key, JSON.stringify(value));
        } catch {
          // приватный режим — кэш недоступен
        }
      }
    } catch {
      // офлайн/сбой — работаем на локальной копии
    } finally {
      this.loadedFor = userId;
    }
  }

  write(key: string, value: Record<string, unknown>): void {
    if (this.timers[key]) {
      clearTimeout(this.timers[key]);
    }

    this.timers[key] = setTimeout(() => {
      delete this.timers[key];
      void apiRequest(`/preferences/filters/${encodeURIComponent(key)}`, {
        method: 'PUT',
        auth: true,
        body: value
      }).catch(() => {
        // офлайн/сбой — остаётся локальная копия
      });
    }, 400);
  }

  remove(key: string): void {
    if (this.timers[key]) {
      clearTimeout(this.timers[key]);
      delete this.timers[key];
    }

    void apiRequest(`/preferences/filters/${encodeURIComponent(key)}`, {
      method: 'DELETE',
      auth: true
    }).catch(() => {
      // офлайн/сбой — не критично
    });
  }
}

export const filterPrefs = new FilterPrefsStore();
