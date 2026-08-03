import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "各種設定 ページ設定" };

export default function PageSettingsPage() {
  return <ModuleRoutePage routeId="page-settings" />;
}
