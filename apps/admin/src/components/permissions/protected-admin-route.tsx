"use client";

import { AlertTriangle, Clock3, LoaderCircle, RotateCcw, ShieldX } from "lucide-react";
import type { ReactNode } from "react";

import { usePermissions } from "@/components/permissions/permission-provider";
import type { AdminPermissionCode } from "@/lib/admin-api/generated";

export function ProtectedAdminRoute({
  children,
  permission,
}: {
  children: ReactNode;
  permission: AdminPermissionCode;
}) {
  const { error, hasPermission, retry, status } = usePermissions();

  if (status === "idle" || status === "loading") {
    return (
      <section className="module-state" role="status">
        <LoaderCircle className="spin" size={24} aria-hidden="true" />
        <h1>権限を確認しています</h1>
      </section>
    );
  }
  if (status === "forbidden" || (status === "ready" && !hasPermission(permission))) {
    return (
      <section className="module-state">
        <ShieldX size={28} aria-hidden="true" />
        <h1>アクセスできません</h1>
        <p>この管理ページを表示する権限がありません。</p>
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
        <p>安全のため業務モジュールを非表示にしています。</p>
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
