import type { Metadata } from "next";
import { headers } from "next/headers";
import type { ReactNode } from "react";

import { AdminAuthProvider } from "@/components/auth/admin-auth-provider";
import { PermissionProvider } from "@/components/permissions/permission-provider";

import "./globals.css";

export const metadata: Metadata = {
  title: {
    default: "Oripa Admin",
    template: "%s | Oripa Admin",
  },
  description: "Oripa Platform Administration",
  referrer: "strict-origin-when-cross-origin",
  robots: {
    follow: false,
    index: false,
    nocache: true,
    googleBot: {
      follow: false,
      index: false,
      noarchive: true,
      noimageindex: true,
      nosnippet: true,
    },
  },
};

export const dynamic = "force-dynamic";

export default async function RootLayout({
  children,
}: Readonly<{ children: ReactNode }>) {
  await headers();
  return (
    <html lang="ja">
      <body>
        <AdminAuthProvider>
          <PermissionProvider>{children}</PermissionProvider>
        </AdminAuthProvider>
      </body>
    </html>
  );
}
