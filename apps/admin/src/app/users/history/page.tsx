import type { Metadata } from "next";

import { OwnerPreviewRoutePage } from "@/components/shell/owner-preview-route-page";

export const metadata: Metadata = { title: "ユーザー 履歴" };

export default function UserHistoryPage() {
  return <OwnerPreviewRoutePage routeId="users-history" />;
}
