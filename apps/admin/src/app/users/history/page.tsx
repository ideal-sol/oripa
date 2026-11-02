import type { Metadata } from "next";

import { MemberRankDiscrepancies } from "@/components/member-ranks/member-rank-discrepancies";

export const metadata: Metadata = { title: "ユーザー ランク変動" };

export default function UserHistoryPage() {
  return <MemberRankDiscrepancies />;
}
