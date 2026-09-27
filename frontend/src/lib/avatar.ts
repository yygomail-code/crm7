export function avatarColor(seed: string | number): string {
  const text = String(seed);
  let hash = 0;

  for (let index = 0; index < text.length; index += 1) {
    hash = (hash * 31 + text.charCodeAt(index)) | 0;
  }

  const hue = Math.abs(hash) % 360;

  return `hsl(${hue}, 55%, 45%)`;
}

export function avatarLetter(name: string | null | undefined): string {
  const value = (name ?? '').trim();

  return value === '' ? '•' : value[0]!.toUpperCase();
}

export function avatarName(
  name: string | null | undefined,
  fallback: string | number | null | undefined
): string {
  const value = (name ?? '').trim();

  return value !== '' ? value : String(fallback ?? '').trim();
}
