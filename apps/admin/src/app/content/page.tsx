import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "コンテンツ" };

export default function ContentPage() {
  return <ModuleRoutePage routeId="content" />;
}
