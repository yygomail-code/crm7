export type ItemsSortKey =
  | 'natural'
  | 'name_asc'
  | 'name_desc'
  | 'warehouse_asc'
  | 'warehouse_desc'
  | 'qty_desc'
  | 'qty_asc';

export interface SortableItem {
  name: string;
  quantity: number;
  warehouse_name?: string | null;
}

export const ITEMS_SORT_OPTIONS: { value: ItemsSortKey; label: string }[] = [
  { value: 'natural', label: 'Сортировка' },
  { value: 'name_asc', label: 'Название: А–Я' },
  { value: 'name_desc', label: 'Название: Я–А' },
  { value: 'warehouse_asc', label: 'Склад: А–Я' },
  { value: 'warehouse_desc', label: 'Склад: Я–А' },
  { value: 'qty_desc', label: 'Количество: по убыванию' },
  { value: 'qty_asc', label: 'Количество: по возрастанию' }
];


type Comparator = (a: SortableItem, b: SortableItem) => number;

function warehouseText(item: SortableItem): string {
  return (item.warehouse_name ?? '').trim();
}

function byName(a: SortableItem, b: SortableItem): number {
  return a.name.localeCompare(b.name, 'ru');
}

const comparators: Record<Exclude<ItemsSortKey, 'natural'>, Comparator> = {
  name_asc: byName,
  name_desc: (a, b) => byName(b, a),
  warehouse_asc: (a, b) => warehouseText(a).localeCompare(warehouseText(b), 'ru') || byName(a, b),
  warehouse_desc: (a, b) => warehouseText(b).localeCompare(warehouseText(a), 'ru') || byName(a, b),
  qty_desc: (a, b) => b.quantity - a.quantity || byName(a, b),
  qty_asc: (a, b) => a.quantity - b.quantity || byName(a, b)
};

export function sortItems<T extends SortableItem>(items: T[], sort: ItemsSortKey): T[] {
  if (sort === 'natural') {
    return items;
  }

  return [...items].sort(comparators[sort]);
}
