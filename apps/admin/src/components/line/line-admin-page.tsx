"use client";
import type { ReactNode } from "react";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { Breadcrumb } from "@/components/navigation/breadcrumb";
import { navigationItem } from "@/lib/permissions/admin-navigation";
import styles from "./line-admin.module.css";

export function LineAdminPage({ kind, title, children }: { kind: "line-chat" | "line-templates" | "line-deliveries"; title: string; children: ReactNode }) {
  const permission = kind === "line-chat" ? "line.chat.read" : kind === "line-templates" ? "line.template.read" : "line.bot.read";
  return <AdminShell><ProtectedAdminRoute permission={permission}><div className={`workspace ${styles.surface}`}><Breadcrumb item={navigationItem(kind)} /><AdminPageHeader eyebrow="LINE Management" title={title} />{children}</div></ProtectedAdminRoute></AdminShell>;
}
