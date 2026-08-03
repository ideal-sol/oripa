import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "ユーザー 履歴" };

export default function UserHistoryPage() {
  return <ModuleRoutePage routeId="users-history" />;
}
