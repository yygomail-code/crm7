export function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }

  const date = new Date(value.replace(' ', 'T'));

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
}

export function formatRelative(value: string | null | undefined): string {
  if (!value) {
    return '';
  }

  const date = new Date(value.replace(' ', 'T'));

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  const seconds = Math.floor((Date.now() - date.getTime()) / 1000);

  if (seconds < 60) {
    return 'только что';
  }

  const minutes = Math.floor(seconds / 60);

  if (minutes < 60) {
    return `${minutes} мин назад`;
  }

  const hours = Math.floor(minutes / 60);

  if (hours < 24) {
    return `${hours} ч назад`;
  }

  const days = Math.floor(hours / 24);

  if (days === 1) {
    return 'вчера';
  }

  if (days < 7) {
    return `${days} дн назад`;
  }

  return formatDateTime(value);
}

export function formatDate(value: string | null | undefined): string {
  if (!value) {
    return '—';
  }

  const date = new Date(value.replace(' ', 'T'));

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return date.toLocaleDateString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric'
  });
}

export function formatMinutes(minutes: number | null | undefined): string {
  if (minutes === null || minutes === undefined) {
    return '—';
  }

  if (minutes < 60) {
    return `${minutes} мин`;
  }

  const hours = Math.floor(minutes / 60);
  const rest = minutes % 60;

  return rest > 0 ? `${hours} ч ${rest} мин` : `${hours} ч`;
}

export function formatSize(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} Б`;
  }

  if (bytes < 1024 * 1024) {
    return `${(bytes / 1024).toFixed(1)} КБ`;
  }

  return `${(bytes / 1024 / 1024).toFixed(1)} МБ`;
}

export function priorityLabel(priority: number): string {
  if (priority === 3) return 'Высокий';
  if (priority === 1) return 'Низкий';
  return 'Обычный';
}

export function formatPrice(value: number | null | undefined): string {
  if (value === null || value === undefined || !Number.isFinite(value)) {
    return '—';
  }

  return `${value.toLocaleString('ru-RU', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ₽`;
}

export const STOCK_ON_ORDER_TEXT = 'поз заказ';

export function stockQuantityText(quantity: number, unit = ''): string {
  if (quantity < 0) {
    return STOCK_ON_ORDER_TEXT;
  }

  const value = quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 });

  return unit ? `${value} ${unit}` : value;
}
