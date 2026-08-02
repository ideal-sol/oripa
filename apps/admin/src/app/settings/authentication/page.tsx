import type { Metadata } from "next";

import { AdminAuthenticationSettings } from "@/components/auth/admin-authentication-settings";

export const metadata: Metadata = { title: "管理者認証" };

export default function AdminAuthenticationSettingsPage() {
  return <AdminAuthenticationSettings />;
}
