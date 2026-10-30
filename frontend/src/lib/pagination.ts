export type PageItem = number | '...';

export function buildPageItems(current: number, count: number): PageItem[] {
  if (count <= 0) {
    return [];
  }

  if (count <= 7) {
    return Array.from({ length: count }, (_, index) => index + 1);
  }

  const items: PageItem[] = [];

  if (current <= 3) {
    for (let index = 1; index <= 5; index += 1) {
      items.push(index);
    }

    items.push('...', count);
    return items;
  }

  if (current >= count - 2) {
    items.push(1, '...');

    for (let index = count - 4; index <= count; index += 1) {
      items.push(index);
    }

    return items;
  }

  items.push(1, '...', current - 1, current, current + 1, '...', count);

  return items;
}
