"use client";

import { AlertTriangle, Clock3, LoaderCircle, RotateCcw, ShieldX } from "lucide-react";
import type { ReactNode } from "react";

import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminShell } from "@/components/shell/admin-shell";
import { ModulePlaceholder } from "@/components/shell/module-placeholder";
import {
  type AdminRouteId,
  navigationItem,
} from "@/lib/permissions/admin-navigation";

export function OwnerPreviewRoutePage({ routeId }: { routeId: AdminRouteId }) {
  const item = navigationItem(routeId);
  if (!item.ownerOnly) throw new Error("Owner preview route must be owner-only.");

  return (
    <AdminShell>
      <OwnerPreviewBoundary>
        <ModulePlaceholder item={item} />
      </OwnerPreviewBoundary>
    </AdminShell>
  );
}

function OwnerPreviewBoundary({ children }: { children: ReactNode }) {
  const { error, retry, role, status } = usePermissions();

  if (status === "idle" || status === "loading") {
    return (
      <section className="module-state" role="status">
        <LoaderCircle className="spin" size={24} aria-hidden="true" />
        <h1>権限を確認しています</h1>
      </section>
    );
  }
  if (status === "forbidden" || (status === "ready" && role !== "owner")) {
    return (
      <section className="module-state">
        <ShieldX size={28} aria-hidden="true" />
        <h1>アクセスできません</h1>
        <p>このPreviewページを表示する権限がありません。</p>
      </section>
    );
  }
  if (status === "rate_limited") {
    return (
      <section className="module-state" role="alert">
        <Clock3 size={26} aria-hidden="true" />
        <h1>しばらく待ってから再試行してください</h1>
        {error?.retryAfter ? <p>{error.retryAfter}秒後に再試行できます。</p> : null}
        <RetryButton onClick={retry} />
      </section>
    );
  }
  if (status !== "ready") {
    return (
      <section className="module-state" role="alert">
        <AlertTriangle size={26} aria-hidden="true" />
        <h1>権限情報を取得できませんでした</h1>
        <p>安全のためPreviewページを非表示にしています。</p>
        <RetryButton onClick={retry} />
      </section>
    );
  }
  return children;
}

function RetryButton({ onClick }: { onClick: () => void }) {
  return (
    <button className="secondary-button" onClick={onClick} type="button">
      <RotateCcw size={17} aria-hidden="true" />
      再試行
    </button>
  );
}
