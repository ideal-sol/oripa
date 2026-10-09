export function assetPublicOrigin(configured: string | undefined): string | null {
  if (!configured) return null;
  if (/[^A-Za-z0-9:/.-]/.test(configured) || !/^https:\/\/[A-Za-z0-9.-]+(?::[0-9]{1,5})?\/?$/.test(configured)) {
    throw new Error("Invalid Asset CDN origin.");
  }
  const url = new URL(configured);
  if (url.hostname.split(".").some((label) => !/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i.test(label))) {
    throw new Error("Invalid Asset CDN origin.");
  }
  return url.origin;
}

export function assetDeliveryUrl(
  path: string | null | undefined,
  origin: string | null,
  localPath = path,
): string | null {
  if (!origin) return localPath ?? null;
  if (!path) return null;
  const relative = path.startsWith(`${origin}/`) ? path.slice(origin.length) : path;
  if (relative.length > 512 || /[^A-Za-z0-9/_.-]/.test(relative) || !/^\/(gacha|top-banner|rank-masters|rank-effects)\/[A-Za-z0-9/_.-]+$/.test(relative)) return null;
  if (relative.slice(1).split("/").some((segment) => !segment || segment === "." || segment === "..")) return null;
  return `${origin}${relative}`;
}
