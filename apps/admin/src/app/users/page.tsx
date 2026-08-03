import type { Metadata } from "next";

import { ModuleRoutePage } from "@/components/shell/module-route-page";

export const metadata: Metadata = { title: "ユーザー 一覧" };

export default function UsersPage() {
  return <ModuleRoutePage routeId="users-list" />;
}
