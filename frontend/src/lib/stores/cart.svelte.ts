export interface CartItem {
  warehouseId: number;
  warehouseName: string;
  name: string;
  unit: string;
  quantity: number;
  stockLevelId?: number | null;
}

const STORAGE_PREFIX = 'crm.cart';

function storageKey(userId: number | null): string {
  return userId === null ? `${STORAGE_PREFIX}.guest` : `${STORAGE_PREFIX}.${userId}`;
}

function read(userId: number | null): CartItem[] {
  try {
    const raw = localStorage.getItem(storageKey(userId));

    if (!raw) {
      return [];
    }

    const parsed = JSON.parse(raw) as CartItem[];

    return Array.isArray(parsed) ? parsed.filter((item) => item && item.name) : [];
  } catch {
    return [];
  }
}

class CartStore {
  private userId: number | null = null;

  items = $state<CartItem[]>(read(null));

  setUser(userId: number | null): void {
    if (this.userId === userId) {
      return;
    }

    this.userId = userId;
    this.items = read(userId);
  }

  get count(): number {
    return this.items.length;
  }

  add(item: CartItem): void {
    const quantity = Math.max(0.001, item.quantity);
    const existing = this.items.find(
      (row) => row.name === item.name && row.warehouseId === item.warehouseId
    );

    if (existing) {
      existing.quantity = Number((existing.quantity + quantity).toFixed(3));
    } else {
      this.items = [...this.items, { ...item, quantity }];
    }

    this.persist();
  }

  remove(index: number): void {
    this.items = this.items.filter((_, i) => i !== index);
    this.persist();
  }

  quantityOf(warehouseId: number, name: string): number {
    const item = this.items.find((row) => row.name === name && row.warehouseId === warehouseId);

    return item ? item.quantity : 0;
  }

  increaseAt(warehouseId: number, name: string): void {
    const index = this.indexOf(warehouseId, name);

    if (index < 0) {
      return;
    }

    const items = [...this.items];
    items[index] = { ...items[index], quantity: Number((items[index].quantity + 1).toFixed(3)) };
    this.items = items;
    this.persist();
  }

  decreaseAt(warehouseId: number, name: string): void {
    const index = this.indexOf(warehouseId, name);

    if (index < 0) {
      return;
    }

    const next = Number((this.items[index].quantity - 1).toFixed(3));

    if (next <= 0) {
      this.remove(index);
      return;
    }

    const items = [...this.items];
    items[index] = { ...items[index], quantity: next };
    this.items = items;
    this.persist();
  }

  private indexOf(warehouseId: number, name: string): number {
    return this.items.findIndex((row) => row.name === name && row.warehouseId === warehouseId);
  }

  clear(): void {
    this.items = [];
    this.persist();
  }

  toText(): string {
    if (this.items.length === 0) {
      return '';
    }

    const lines = this.items.map(
      (item) =>
        `— ${item.name}: ${item.quantity.toLocaleString('ru-RU', { maximumFractionDigits: 3 })} ${item.unit}`.trim() +
        ` (${item.warehouseName})`
    );

    return 'Позиции со склада:\n' + lines.join('\n');
  }

  private persist(): void {
    try {
      localStorage.setItem(storageKey(this.userId), JSON.stringify(this.items));
    } catch {
      // приватный режим — корзина живёт до перезагрузки
    }
  }
}

export const cart = new CartStore();
