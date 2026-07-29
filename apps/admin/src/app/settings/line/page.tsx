import type { Metadata } from "next";

import { LineMessagingSettings } from "@/components/line/line-messaging-settings";

export const metadata: Metadata = { title: "LINE設定" };

export default function LineSettingsPage() {
  return <LineMessagingSettings />;
}
