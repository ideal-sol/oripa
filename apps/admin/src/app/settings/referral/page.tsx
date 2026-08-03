import type { Metadata } from "next";

import { OwnerPreviewRoutePage } from "@/components/shell/owner-preview-route-page";

export const metadata: Metadata = { title: "各種設定 紹介ポイント設定" };

export default function ReferralSettingsPage() {
  return <OwnerPreviewRoutePage routeId="referral-settings" />;
}
