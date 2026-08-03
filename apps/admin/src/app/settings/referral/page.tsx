import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "各種設定 紹介ポイント設定" };

export default function ReferralSettingsPage() {
  return <ModuleRoutePage routeId="referral-settings" />;
}
