import type { Metadata } from "next";

import { GachaNoticeSettings } from "@/components/settings/gacha-notice-settings";

export const metadata: Metadata = { title: "各種設定 ガチャ注意事項設定" };

export default function GachaNoticeSettingsPage() {
  return <GachaNoticeSettings />;
}
