import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "ガチャ 履歴" };

export default function GachaHistoryPage() {
  return <ModuleRoutePage routeId="gachas-history" />;
}
