import { apiRequest } from '../api/client';
import { auth } from './auth.svelte';

interface AppSettingsResponse {
  sales_enabled: boolean;
  email_export_enabled: boolean;
  stock_reserve_enabled: boolean;
  stock_allow_zero: boolean;
  prices_enabled: boolean;
  groups_enabled: boolean;
  demo_mode: boolean;
}

class AppSettingsStore {
  salesEnabled = $state(true);

  emailExportEnabled = $state(true);

  stockReserveEnabled = $state(false);

  allowZeroStock = $state(false);

  pricesEnabled = $state(false);

  groupsEnabled = $state(false);

  demoMode = $state(false);

  loadedFor = $state<number | null>(null);

  loading = $state(false);

  get loaded(): boolean {
    return this.loadedFor !== null;
  }

  async load(): Promise<void> {
    const userId = auth.user?.id ?? null;

    if (userId === null || this.loading) {
      return;
    }

    this.loading = true;

    try {
      const data = await apiRequest<AppSettingsResponse>('/settings/app', { auth: true });
      this.salesEnabled = Boolean(data.sales_enabled);
      this.emailExportEnabled = data.email_export_enabled !== false;
      this.stockReserveEnabled = data.stock_reserve_enabled === true;
      this.allowZeroStock = data.stock_allow_zero === true;
      this.pricesEnabled = data.prices_enabled === true;
      this.groupsEnabled = data.groups_enabled === true;
      this.demoMode = data.demo_mode === true;
      this.loadedFor = userId;
    } catch {
      // служебная настройка: интерфейс не блокируем при сбое загрузки
    } finally {
      this.loading = false;
    }
  }

  set(
    salesEnabled: boolean,
    emailExportEnabled = this.emailExportEnabled,
    stockReserveEnabled = this.stockReserveEnabled,
    allowZeroStock = this.allowZeroStock,
    pricesEnabled = this.pricesEnabled,
    groupsEnabled = this.groupsEnabled
  ): void {
    this.salesEnabled = salesEnabled;
    this.emailExportEnabled = emailExportEnabled;
    this.stockReserveEnabled = stockReserveEnabled;
    this.allowZeroStock = allowZeroStock;
    this.pricesEnabled = pricesEnabled;
    this.groupsEnabled = groupsEnabled;
  }

  reset(): void {
    this.salesEnabled = true;
    this.emailExportEnabled = true;
    this.stockReserveEnabled = false;
    this.allowZeroStock = false;
    this.pricesEnabled = false;
    this.groupsEnabled = false;
    this.demoMode = false;
    this.loadedFor = null;
  }
}

export const appSettings = new AppSettingsStore();
