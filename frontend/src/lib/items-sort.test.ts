import { describe, expect, it } from 'vitest';
import { sortItems, type SortableItem } from './items-sort';

const items: SortableItem[] = [
  { name: 'Болт', quantity: 5, warehouse_name: 'Склад Б' },
  { name: 'Гайка', quantity: 2, warehouse_name: 'Склад А' },
  { name: 'Шайба', quantity: 10, warehouse_name: 'Склад А' }
];

describe('items-sort helpers', () => {
  it('natural — сохраняет порядок добавления', () => {
    expect(sortItems(items, 'natural').map((item) => item.name)).toEqual(['Болт', 'Гайка', 'Шайба']);
  });

  it('сортирует по названию', () => {
    expect(sortItems(items, 'name_asc').map((item) => item.name)).toEqual(['Болт', 'Гайка', 'Шайба']);
    expect(sortItems(items, 'name_desc').map((item) => item.name)).toEqual(['Шайба', 'Гайка', 'Болт']);
  });

  it('сортирует по складу, внутри склада — по названию', () => {
    expect(sortItems(items, 'warehouse_asc').map((item) => item.name)).toEqual(['Гайка', 'Шайба', 'Болт']);
    expect(sortItems(items, 'warehouse_desc').map((item) => item.name)).toEqual(['Болт', 'Гайка', 'Шайба']);
  });

  it('сортирует по количеству', () => {
    expect(sortItems(items, 'qty_asc').map((item) => item.quantity)).toEqual([2, 5, 10]);
    expect(sortItems(items, 'qty_desc').map((item) => item.quantity)).toEqual([10, 5, 2]);
  });

  it('не мутирует исходный массив', () => {
    const source = [...items];
    sortItems(source, 'qty_desc');

    expect(source.map((item) => item.name)).toEqual(['Болт', 'Гайка', 'Шайба']);
  });

  it('позиции без склада идут первыми при сортировке по складу', () => {
    const mixed: SortableItem[] = [
      { name: 'Гайка', quantity: 1, warehouse_name: 'Склад А' },
      { name: 'Винт', quantity: 1, warehouse_name: null }
    ];

    expect(sortItems(mixed, 'warehouse_asc').map((item) => item.name)).toEqual(['Винт', 'Гайка']);
  });
});
