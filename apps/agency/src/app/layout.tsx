import type { Metadata } from "next";
import type { ReactNode } from "react";
import "../../../admin/src/app/globals.css";
import "./agency.css";

export const metadata: Metadata = { title: "代理店管理 | Oripa", robots: { index: false, follow: false } };

export default function RootLayout({ children }: { children: ReactNode }) {
  return <html lang="ja" className="agency-theme"><body>{children}</body></html>;
}
