import { cookies } from "next/headers";
import { NextResponse } from "next/server";

export async function GET(request: Request) {
  return logout(request);
}

export async function POST(request: Request) {
  return logout(request);
}

async function logout(request: Request) {
  const cookieStore = await cookies();
  cookieStore.delete("oripa_admin_session");

  const response = NextResponse.redirect(redirectUrl(request, "/"), { status: 303 });
  response.cookies.set("oripa_admin_session", "", {
    path: "/",
    sameSite: "lax",
    secure: true,
    maxAge: 0,
  });

  return response;
}

function redirectUrl(request: Request, path: string) {
  const host = request.headers.get("x-forwarded-host") ?? request.headers.get("host") ?? "admin.luxe-pack.biz";
  const proto = request.headers.get("x-forwarded-proto") ?? "https";

  return new URL(path, `${proto}://${host}`);
}
