export const dynamic = "force-dynamic";

export function GET() {
  return Response.json({
    checks: {
      process: "ok",
    },
    component: "apps/admin",
    readiness_scope: "process",
    status: "ok",
  });
}
