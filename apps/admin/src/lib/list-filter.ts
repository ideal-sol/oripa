export type PageSearchParams = Record<string, string | string[] | undefined>;

export function initialListFilter<T extends string>(
  value: string | string[] | undefined,
  allowed: readonly T[],
  fallback: T,
): T {
  const candidate = Array.isArray(value) ? value[0] : value;
  return candidate && allowed.includes(candidate as T)
    ? candidate as T
    : fallback;
}
