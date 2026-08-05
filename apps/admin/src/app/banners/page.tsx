import type { Metadata } from "next";

import { BannerManagementWorkspace } from "@/components/banners/banner-management-workspace";

export const metadata: Metadata = { title: "バナー管理" };

export default function BannersPage() {
  return <BannerManagementWorkspace />;
}
