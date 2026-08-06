import type { Metadata } from "next";

import { ReferralPointSettings } from "@/components/settings/referral-point-settings";

export const metadata: Metadata = { title: "各種設定 紹介ポイント設定" };

export default function ReferralSettingsPage() {
  return <ReferralPointSettings />;
}
