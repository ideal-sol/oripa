import { NextResponse } from "next/server";

export async function GET(request: Request) {
  const requestUrl = new URL(request.url);
  const token = requestUrl.searchParams.get("token");

  if (!token) {
    return redirectToLogin(request, "invalid");
  }

  const verificationUrl = decodeToken(token);

  if (!verificationUrl) {
    return redirectToLogin(request, "invalid");
  }

  try {
    const response = await fetch(verificationUrl, {
      cache: "no-store",
      headers: {
        accept: "application/json",
      },
    });

    return redirectToLogin(request, response.ok ? "success" : "invalid");
  } catch {
    return redirectToLogin(request, "invalid");
  }
}

function decodeToken(token: string): string | null {
  try {
    const normalized = token.replace(/-/g, "+").replace(/_/g, "/");
    const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, "=");
    const value = Buffer.from(padded, "base64").toString("utf8");
    const url = new URL(value);

    return url.protocol === "http:" || url.protocol === "https:" ? url.toString() : null;
  } catch {
    return null;
  }
}

function redirectToLogin(request: Request, status: "success" | "invalid") {
  return NextResponse.redirect(redirectUrl(request, `/login?email_verified=${status}`), { status: 303 });
}

function redirectUrl(request: Request, path: string) {
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host") ?? "luxe-pack.biz";
  const proto = request.headers.get("x-forwarded-proto") ?? "https";

  return new URL(path, `${proto}://${host}`);
}
