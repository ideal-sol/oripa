import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "ガチャ 登録" };

export default function GachaCreatePage() {
  return <ModuleRoutePage routeId="gachas-create" />;
}
