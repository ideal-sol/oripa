"use client";

import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { DashboardSalesLayout } from "@/components/shell/dashboard-sales-layout";

export function DashboardHome() {
  const { error, permissions, retry, status } = usePermissions();
  const salesState = status === "ready"
    ? permissions.has("reporting.financial.read") ? "ready" : "error"
    : status === "loading" || status === "idle"
      ? "loading"
      : "error";

  return (
    <section className="workspace">
      <AdminPageHeader
        eyebrow="Administration"
        title="ダッシュボード"
        description="決済売上とガチャで消費されたポイントを確認できます。"
        action={<span className="status-pill is-active">JST・V2実Data</span>}
      />
      <DashboardSalesLayout
        onRetry={retry}
        retryAfter={status === "rate_limited" ? error?.retryAfter ?? undefined : undefined}
        state={salesState}
      />
    </section>
  );
}
