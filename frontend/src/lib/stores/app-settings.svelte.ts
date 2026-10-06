import { apiRequest } from '../api/client';
import { auth } from './auth.svelte';

interface AppSettingsResponse {
  sales_enabled: boolean;
  email_export_enabled: boolean;
  stock_reserve_enabled: boolean;
  stock_allow_zero: boolean;
  prices_enabled: boolean;
  groups_enabled: boolean;
  requests_enabled: boolean;
  cart_enabled: boolean;
  substitutions_enabled: boolean;
  manager_assign_enabled: boolean;
  app_title: string;
  client_label: string;
  demo_mode: boolean;
}

class AppSettingsStore {
  salesEnabled = $state(true);

  emailExportEnabled = $state(true);

  stockReserveEnabled = $state(false);

  allowZeroStock = $state(false);

  pricesEnabled = $state(false);

  groupsEnabled = $state(false);

  requestsEnabled = $state(true);

  cartEnabled = $state(true);

  substitutionsEnabled = $state(true);

  managerAssignEnabled = $state(true);

  appTitle = $state('CRM7');

  clientLabel = $state('Клиент');

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
      this.requestsEnabled = data.requests_enabled !== false;
      this.cartEnabled = data.cart_enabled !== false;
      this.substitutionsEnabled = data.substitutions_enabled !== false;
      this.managerAssignEnabled = data.manager_assign_enabled !== false;
      this.appTitle = (data.app_title || 'CRM7').trim() || 'CRM7';
      this.clientLabel = (data.client_label || 'Клиент').trim() || 'Клиент';
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
    groupsEnabled = this.groupsEnabled,
    requestsEnabled = this.requestsEnabled,
    cartEnabled = this.cartEnabled,
    substitutionsEnabled = this.substitutionsEnabled,
    managerAssignEnabled = this.managerAssignEnabled,
    appTitle = this.appTitle,
    clientLabel = this.clientLabel
  ): void {
    this.salesEnabled = salesEnabled;
    this.emailExportEnabled = emailExportEnabled;
    this.stockReserveEnabled = stockReserveEnabled;
    this.allowZeroStock = allowZeroStock;
    this.pricesEnabled = pricesEnabled;
    this.groupsEnabled = groupsEnabled;
    this.requestsEnabled = requestsEnabled;
    this.cartEnabled = cartEnabled;
    this.substitutionsEnabled = substitutionsEnabled;
    this.managerAssignEnabled = managerAssignEnabled;
    this.appTitle = appTitle;
    this.clientLabel = clientLabel;
  }

  reset(): void {
    this.salesEnabled = true;
    this.emailExportEnabled = true;
    this.stockReserveEnabled = false;
    this.allowZeroStock = false;
    this.pricesEnabled = false;
    this.groupsEnabled = false;
    this.requestsEnabled = true;
    this.cartEnabled = true;
    this.substitutionsEnabled = true;
    this.managerAssignEnabled = true;
    this.appTitle = 'CRM7';
    this.clientLabel = 'Клиент';
    this.demoMode = false;
    this.loadedFor = null;
  }
}

export const appSettings = new AppSettingsStore();
