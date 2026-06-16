import { cookies } from "next/headers";
import { NextResponse } from "next/server";

const adminApiBase = process.env.INTERNAL_ADMIN_API_BASE_URL
  ?? process.env.NEXT_PUBLIC_ADMIN_API_BASE_URL
  ?? "http://backend:8000/admin/api";

export async function POST(request: Request) {
  const formData = await request.formData();
  const email = String(formData.get("email") ?? "");
  const password = String(formData.get("password") ?? "");

  const response = await fetch(`${adminApiBase}/login`, {
    method: "POST",
    headers: {
      accept: "application/json",
      "content-type": "application/json",
    },
    body: JSON.stringify({
      email,
      password,
      device_name: "admin-dashboard-form",
    }),
  });

  if (!response.ok) {
    return NextResponse.redirect(redirectUrl(request, "/?login_error=1"), { status: 303 });
  }

  const session = await response.json();
  const cookieStore = await cookies();
  cookieStore.set("oripa_admin_session", JSON.stringify(session), {
    path: "/",
    sameSite: "lax",
    secure: true,
    maxAge: 60 * 60 * 24,
  });

  return NextResponse.redirect(redirectUrl(request, "/"), { status: 303 });
}

function redirectUrl(request: Request, path: string) {
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host") ?? "admin.luxe-pack.biz";
  const proto = request.headers.get("x-forwarded-proto") ?? "https";

  return new URL(path, `${proto}://${host}`);
}
