import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "ガチャ シミュレーション" };

export default function GachaSimulationPage() {
  return <ModuleRoutePage routeId="gachas-simulation" />;
}
