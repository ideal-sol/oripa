import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "バナー 登録" };

export default function BannerCreatePage() {
  return <ModuleRoutePage routeId="banners-create" />;
}
