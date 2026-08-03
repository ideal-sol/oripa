import type { Metadata } from "next";

import { OwnerPreviewRoutePage } from "@/components/shell/owner-preview-route-page";

export const metadata: Metadata = { title: "ユーザー 一覧" };

export default function UsersPage() {
  return <OwnerPreviewRoutePage routeId="users-list" />;
}
