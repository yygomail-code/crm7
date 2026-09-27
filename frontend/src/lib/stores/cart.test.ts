import { beforeEach, describe, expect, it } from 'vitest';
import { cart } from './cart.svelte';

const item = {
  warehouseId: 1,
  warehouseName: 'Склад Москва',
  name: 'Товар Тестовый',
  unit: 'шт',
  quantity: 1
};

describe('cart store', () => {
  beforeEach(() => {
    cart.setUser(null);
    cart.clear();
  });

  it('keeps carts of different users apart', () => {
    cart.setUser(1);
    cart.clear();
    cart.add(item);

    expect(cart.count).toBe(1);

    cart.setUser(2);
    expect(cart.count).toBe(0);

    cart.setUser(1);
    expect(cart.count).toBe(1);
  });

  it('adds items and merges duplicates', () => {
    cart.add(item);
    expect(cart.quantityOf(1, 'Товар Тестовый')).toBe(1);

    cart.add({ ...item, quantity: 2 });
    expect(cart.items.length).toBe(1);
    expect(cart.quantityOf(1, 'Товар Тестовый')).toBe(3);
  });

  it('keeps the same product from different warehouses apart', () => {
    cart.add(item);
    cart.add({ ...item, warehouseId: 2, warehouseName: 'Склад Белореченск' });

    expect(cart.items.length).toBe(2);
    expect(cart.quantityOf(1, 'Товар Тестовый')).toBe(1);
    expect(cart.quantityOf(2, 'Товар Тестовый')).toBe(1);
    expect(cart.quantityOf(3, 'Товар Тестовый')).toBe(0);
  });

  it('increases and decreases quantity', () => {
    cart.add(item);

    cart.increaseAt(1, 'Товар Тестовый');
    expect(cart.quantityOf(1, 'Товар Тестовый')).toBe(2);

    cart.decreaseAt(1, 'Товар Тестовый');
    expect(cart.quantityOf(1, 'Товар Тестовый')).toBe(1);
  });

  it('removes the row when quantity reaches zero', () => {
    cart.add(item);
    cart.decreaseAt(1, 'Товар Тестовый');

    expect(cart.items.length).toBe(0);
    expect(cart.count).toBe(0);
  });

  it('removes a row by index', () => {
    cart.add(item);
    cart.add({ ...item, warehouseId: 2, warehouseName: 'Склад Белореченск' });
    cart.remove(0);

    expect(cart.items.length).toBe(1);
    expect(cart.items[0].warehouseId).toBe(2);
  });
});
