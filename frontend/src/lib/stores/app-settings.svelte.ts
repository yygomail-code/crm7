import { apiRequest } from '../api/client';
import { auth } from './auth.svelte';

interface AppSettingsResponse {
  sales_enabled: boolean;
  email_export_enabled: boolean;
  mail_configured: boolean;
  stock_reserve_enabled: boolean;
  stock_allow_zero: boolean;
  prices_enabled: boolean;
  groups_enabled: boolean;
  requests_enabled: boolean;
  cart_enabled: boolean;
  substitutions_enabled: boolean;
  manager_assign_enabled: boolean;
  warehouses_enabled: boolean;
  reports_enabled: boolean;
  single_name: string;
  app_title: string;
  client_label: string;
  photo_ratio: string;
  photo_fit: string;
  demo_mode: boolean;
}

class AppSettingsStore {
  salesEnabled = $state(true);

  emailExportEnabled = $state(true);

  mailConfigured = $state(false);

  stockReserveEnabled = $state(false);

  allowZeroStock = $state(false);

  pricesEnabled = $state(false);

  groupsEnabled = $state(false);

  requestsEnabled = $state(true);

  cartEnabled = $state(true);

  substitutionsEnabled = $state(true);

  managerAssignEnabled = $state(true);

  warehousesEnabled = $state(true);

  reportsEnabled = $state(true);

  singleName = $state('Основной склад');

  appTitle = $state('CRM7');

  clientLabel = $state('Клиент');

  photoRatio = $state('square');

  photoFit = $state('contain');

  demoMode = $state(false);

  loadedFor = $state<number | null>(null);

  loading = $state(false);

  get loaded(): boolean {
    return this.loadedFor !== null;
  }

  get photoAspect(): string {
    return this.photoRatio === 'landscape'
      ? '4 / 3'
      : this.photoRatio === 'portrait'
        ? '3 / 4'
        : this.photoRatio === 'square'
          ? '1 / 1'
          : 'auto';
  }

  get photoFactor(): number {
    return this.photoRatio === 'landscape' ? 4 / 3 : this.photoRatio === 'portrait' ? 3 / 4 : 1;
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
      this.mailConfigured = data.mail_configured === true;
      this.stockReserveEnabled = data.stock_reserve_enabled === true;
      this.allowZeroStock = data.stock_allow_zero === true;
      this.pricesEnabled = data.prices_enabled === true;
      this.groupsEnabled = data.groups_enabled === true;
      this.requestsEnabled = data.requests_enabled !== false;
      this.cartEnabled = data.cart_enabled !== false;
      this.substitutionsEnabled = data.substitutions_enabled !== false;
      this.managerAssignEnabled = data.manager_assign_enabled !== false;
      this.warehousesEnabled = data.warehouses_enabled !== false;
      this.reportsEnabled = data.reports_enabled !== false;
      this.singleName = (data.single_name || 'Основной склад').trim() || 'Основной склад';
      this.appTitle = (data.app_title || 'CRM7').trim() || 'CRM7';
      this.clientLabel = (data.client_label || 'Клиент').trim() || 'Клиент';
      this.photoRatio = ['dynamic', 'square', 'landscape', 'portrait'].includes(data.photo_ratio)
        ? data.photo_ratio
        : 'square';
      this.photoFit = data.photo_fit === 'cover' ? 'cover' : 'contain';
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
    mailConfigured = this.mailConfigured,
    stockReserveEnabled = this.stockReserveEnabled,
    allowZeroStock = this.allowZeroStock,
    pricesEnabled = this.pricesEnabled,
    groupsEnabled = this.groupsEnabled,
    requestsEnabled = this.requestsEnabled,
    cartEnabled = this.cartEnabled,
    substitutionsEnabled = this.substitutionsEnabled,
    managerAssignEnabled = this.managerAssignEnabled,
    warehousesEnabled = this.warehousesEnabled,
    singleName = this.singleName,
    appTitle = this.appTitle,
    clientLabel = this.clientLabel,
    photoRatio = this.photoRatio,
    photoFit = this.photoFit
  ): void {
    this.salesEnabled = salesEnabled;
    this.emailExportEnabled = emailExportEnabled;
    this.mailConfigured = mailConfigured;
    this.stockReserveEnabled = stockReserveEnabled;
    this.allowZeroStock = allowZeroStock;
    this.pricesEnabled = pricesEnabled;
    this.groupsEnabled = groupsEnabled;
    this.requestsEnabled = requestsEnabled;
    this.cartEnabled = cartEnabled;
    this.substitutionsEnabled = substitutionsEnabled;
    this.managerAssignEnabled = managerAssignEnabled;
    this.warehousesEnabled = warehousesEnabled;
    this.singleName = singleName;
    this.appTitle = appTitle;
    this.clientLabel = clientLabel;
    this.photoRatio = photoRatio;
    this.photoFit = photoFit;
  }

  reset(): void {
    this.salesEnabled = true;
    this.emailExportEnabled = true;
    this.mailConfigured = false;
    this.stockReserveEnabled = false;
    this.allowZeroStock = false;
    this.pricesEnabled = false;
    this.groupsEnabled = false;
    this.requestsEnabled = true;
    this.cartEnabled = true;
    this.substitutionsEnabled = true;
    this.managerAssignEnabled = true;
    this.warehousesEnabled = true;
    this.singleName = 'Основной склад';
    this.appTitle = 'CRM7';
    this.clientLabel = 'Клиент';
    this.photoRatio = 'square';
    this.photoFit = 'contain';
    this.demoMode = false;
    this.loadedFor = null;
  }
}

export const appSettings = new AppSettingsStore();
