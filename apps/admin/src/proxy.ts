import { NextRequest, NextResponse } from "next/server";

const localHosts = new Set(["localhost", "127.0.0.1", "::1"]);

export function proxy(request: NextRequest) {
  if (!isAllowedHost(request.headers.get("host"))) {
    return new NextResponse("Not Found", {
      status: 404,
      headers: securityHeaders(""),
    });
  }

  const nonce = crypto.randomUUID().replaceAll("-", "");
  const requestHeaders = new Headers(request.headers);
  requestHeaders.set("x-nonce", nonce);
  const response = NextResponse.next({
    request: {
      headers: requestHeaders,
    },
  });
  for (const [name, value] of Object.entries(securityHeaders(nonce))) {
    response.headers.set(name, value);
  }
  return response;
}

function isAllowedHost(hostHeader: string | null): boolean {
  if (!hostHeader) return false;
  let host: string;
  try {
    host = new URL(`http://${hostHeader}`).hostname.toLowerCase();
  } catch {
    return false;
  }
  const configured = new Set(
    (process.env.ADMIN_ALLOWED_HOSTS ?? "")
      .split(",")
      .map((value) => value.trim().toLowerCase())
      .filter(Boolean),
  );
  return localHosts.has(host) || configured.has(host);
}

function securityHeaders(nonce: string): Record<string, string> {
  const scriptSource = nonce
    ? `'nonce-${nonce}' 'strict-dynamic'`
    : "'none'";
  const imageSource = publicOrigin();
  return {
    "Cache-Control": "private, no-store",
    "Content-Security-Policy": [
      "default-src 'self'",
      `script-src ${scriptSource}`,
      "style-src 'self'",
      `img-src 'self' data:${imageSource ? ` ${imageSource}` : ""}`,
      "font-src 'self'",
      "connect-src 'self'",
      "object-src 'none'",
      "base-uri 'none'",
      "frame-ancestors 'none'",
      "form-action 'self'",
    ].join("; "),
    "Cross-Origin-Opener-Policy": "same-origin",
    "Cross-Origin-Resource-Policy": "same-site",
    "Permissions-Policy": "camera=(), microphone=(), geolocation=()",
    "Referrer-Policy": "strict-origin-when-cross-origin",
    "X-Content-Type-Options": "nosniff",
    "X-Frame-Options": "DENY",
    "X-Robots-Tag": "noindex, nofollow, noarchive",
  };
}

function publicOrigin(): string | null {
  const configured = process.env.V2_PUBLIC_ORIGIN;
  if (!configured) return null;
  try {
    const url = new URL(configured);
    if (url.protocol !== "https:" && url.protocol !== "http:") return null;
    return url.origin;
  } catch {
    return null;
  }
}

export const config = {
  matcher: ["/((?!_next/static|_next/image|favicon.ico).*)"],
};
