export const dynamic = "force-dynamic";

export function GET() {
  return Response.json({
    component: "apps/admin",
    production_ready: false,
    stage: "skeleton",
    status: "ok",
  });
}
