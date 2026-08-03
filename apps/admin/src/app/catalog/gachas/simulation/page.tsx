import type { Metadata } from "next";

import { OwnerPreviewRoutePage } from "@/components/shell/owner-preview-route-page";

export const metadata: Metadata = { title: "ガチャ シミュレーション" };

export default function GachaSimulationPage() {
  return <OwnerPreviewRoutePage routeId="gachas-simulation" />;
}
