import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "レポート" };

export default function ReportsPage() {
  return <ModuleRoutePage routeId="reports" />;
}
