import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "QA Draw" };

export default function QaPage() {
  return <ModuleRoutePage routeId="qa" />;
}
