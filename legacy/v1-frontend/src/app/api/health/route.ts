import { NextResponse } from "next/server";

export async function GET() {
  return NextResponse.json({
    app: "ok",
    apiBaseUrl: process.env.NEXT_PUBLIC_API_BASE_URL,
    timestamp: new Date().toISOString(),
  });
}
