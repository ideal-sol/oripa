import type { Metadata } from "next";
import { MemberRankSettings } from "@/components/settings/member-rank-settings";

export const metadata: Metadata = { title: "各種設定 会員ランク設定" };

export default function MemberRankSettingsPage() {
  return <MemberRankSettings />;
}
