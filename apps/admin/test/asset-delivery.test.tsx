import { render, screen } from "@testing-library/react";
import { NextRequest } from "next/server";
import { afterEach, describe, expect, it, vi } from "vitest";

import { AssetDeliveryProvider } from "@/components/catalog/asset-delivery-provider";
import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import { assetDeliveryUrl, assetPublicOrigin } from "@/lib/asset-delivery";
import { proxy } from "@/proxy";

const origin = "https://cdn.example.test";
const asset = { id: "10000000-0000-4000-8000-000000000001", media_type: "image" as const, mime_type: "image/webp", alt_text: "Prize", public_path: "/gacha/2026/10/prize-1_v2.webp", is_public: false };

afterEach(() => vi.unstubAllEnvs());

describe("Asset CDN delivery", () => {
  it.each(["gacha", "top-banner", "rank-masters", "rank-effects"])("resolves %s without changing the API origin", (prefix) => {
    expect(assetDeliveryUrl(`/${prefix}/2026/10/file-1_v2.webp`, origin)).toBe(`${origin}/${prefix}/2026/10/file-1_v2.webp`);
  });

  it.each(["/admin-assets/gacha/a.png", "/admin/api/a", "/fixture/a.png", "//evil.test/a", "/gacha/../a", "/gacha/./a", "/gacha//a", "/gacha/a%2fb", "/gacha/a\\b", "/gacha/a?x=1", "/gacha/", "/static-assets/a", "/gacha/" + "a".repeat(512), "https://evil.test/gacha/a"])("hides invalid path %s", (path) => {
    expect(assetDeliveryUrl(path, origin, "/admin/api/fallback")).toBeNull();
  });

  it.each(["http://cdn.example.test", "https://cdn.example.test/path", "https://user@cdn.example.test", "https://*.example.test", "https://cdn.example.test?x=1", "https://cdn.example.test; img-src *"])("rejects invalid origin %s", (value) => {
    expect(() => assetPublicOrigin(value)).toThrow();
  });

  it("accepts only the configured absolute banner origin", () => {
    expect(assetPublicOrigin(`${origin}/`)).toBe(origin);
    expect(assetDeliveryUrl(`${origin}/top-banner/banner.png`, origin)).toBe(`${origin}/top-banner/banner.png`);
    expect(assetDeliveryUrl("https://cdn.example.test.evil/top-banner/banner.png", origin)).toBeNull();
  });

  it("rejects trailing control characters and canonicalizes origins", () => {
    expect(() => assetPublicOrigin(`${origin}\n`)).toThrow();
    expect(assetDeliveryUrl("/gacha/a.png\n", origin)).toBeNull();
    expect(assetPublicOrigin("https://CDN.example.test:443/")).toBe(origin);
  });

  it("keeps legacy local preview when unset", () => {
    expect(assetPublicOrigin(undefined)).toBeNull();
    render(<PublicAssetPreview asset={{ ...asset, public_path: null }} allowAuthenticatedContent />);
    expect(screen.getByAltText("Prize")).toHaveAttribute("src", `/admin/api/v2/catalog/presentation-assets/${asset.id}/content`);
  });

  it.each([true, false])("uses direct CDN preview for is_public=%s", (isPublic) => {
    render(<AssetDeliveryProvider origin={origin}><PublicAssetPreview asset={{ ...asset, is_public: isPublic }} allowAuthenticatedContent /></AssetDeliveryProvider>);
    expect(screen.getByAltText("Prize")).toHaveAttribute("src", `${origin}${asset.public_path}`);
  });

  it("does not fall back to authenticated content for unresolved CDN keys", () => {
    render(<AssetDeliveryProvider origin={origin}><PublicAssetPreview asset={{ ...asset, public_path: "/fixture/a.png" }} allowAuthenticatedContent /></AssetDeliveryProvider>);
    expect(screen.getByRole("img", { name: "Previewなし" })).toBeInTheDocument();
  });

  it("uses CDN for private video", () => {
    render(<AssetDeliveryProvider origin={origin}><PublicAssetPreview asset={{ ...asset, media_type: "video", mime_type: "video/quicktime", public_path: "/rank-effects/a.mov" }} allowAuthenticatedContent /></AssetDeliveryProvider>);
    expect(screen.getByLabelText("Prize")).toHaveAttribute("src", `${origin}/rank-effects/a.mov`);
  });

  it("adds only the exact configured origin to image/media CSP", () => {
    vi.stubEnv("V2_ASSET_PUBLIC_BASE_URL", origin);
    const response = proxy(new NextRequest("http://localhost/catalog", { headers: { host: "localhost" } }));
    const policy = response.headers.get("Content-Security-Policy")!;
    expect(policy).toContain(`img-src 'self' data: ${origin}`);
    expect(policy).toContain(`media-src 'self' ${origin}`);
    expect(policy).toContain("connect-src 'self'");
    expect(policy).not.toContain("*");
  });

  it("keeps same-origin media CSP without configuration", () => {
    vi.stubEnv("V2_ASSET_PUBLIC_BASE_URL", "");
    const response = proxy(new NextRequest("http://localhost/catalog", { headers: { host: "localhost" } }));
    expect(response.headers.get("Content-Security-Policy")).toContain("media-src 'self';");
  });
});
