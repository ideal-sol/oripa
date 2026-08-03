import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "バナー 一覧" };

export default function BannersPage() {
  return <ModuleRoutePage routeId="banners" />;
}
