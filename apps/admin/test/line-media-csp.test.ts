import { NextRequest } from "next/server";
import { afterEach, describe, expect, it, vi } from "vitest";
import { proxy } from "@/proxy";

const origin = "https://synthetic-upload.s3.ap-northeast-1.amazonaws.com";
function policy(path: string, host = "localhost") {
  return proxy(new NextRequest(`https://${host}${path}`, { headers: { host } }))
    .headers.get("Content-Security-Policy")!;
}
function connect(path: string, host = "localhost") {
  return policy(path, host).split("; ").find((part) => part.startsWith("connect-src"));
}

afterEach(() => vi.unstubAllEnvs());

describe("LINE direct upload CSP", () => {
  it.each(["/line-deliveries", "/line-templates", "/line-chat"])("permits one configured HTTPS upload origin on %s", (path) => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", `${origin}/`);
    expect(connect(path)).toBe(`connect-src 'self' ${origin}`);
    expect(policy(path)).toContain("object-src 'none'");
    expect(policy(path)).toContain("frame-ancestors 'none'");
    expect(policy(path)).toContain("form-action 'self'");
    expect(policy(path)).toContain("'strict-dynamic'");
  });
  it.each(["/login", "/catalog", "/contacts", "/line-deliveries/unknown", "/api/admin/line-media"])("does not widen %s", (path) => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", origin);
    expect(connect(path)).toBe("connect-src 'self'");
  });
  it("keeps unknown hosts denied without an upload exception", () => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", origin);
    expect(connect("/line-deliveries", "untrusted.example.test")).toBe("connect-src 'self'");
  });
  it.each([undefined, ""])("defaults to self when unconfigured (%s)", (value) => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", value);
    expect(connect("/line-deliveries")).toBe("connect-src 'self'");
  });
  it.each(["http://upload.example.test", "https://*.example.test", "https://upload.example.test/path", "https://upload.example.test?x=1", "https://upload.example.test#fragment", "https://user:password@upload.example.test", "https://upload.example.test; connect-src *", "https://upload.example.test https://other.example.test", "https://upload.example.test\n", "https://bad..example.test"])("fails closed for malformed origin %s", (value) => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", value);
    expect(() => connect("/line-deliveries")).toThrow();
  });
  it("does not infer the upload origin from the CDN or request", () => {
    vi.stubEnv("V2_LINE_MEDIA_UPLOAD_ORIGIN", "");
    vi.stubEnv("V2_ASSET_PUBLIC_BASE_URL", "https://cdn.example.test");
    expect(connect("/line-deliveries")).toBe("connect-src 'self'");
  });
});
