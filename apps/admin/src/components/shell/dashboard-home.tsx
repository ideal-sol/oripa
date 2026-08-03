"use client";

import {
  AlertTriangle,
  KeyRound,
  RotateCcw,
  ShieldCheck,
  UserRound,
} from "lucide-react";
import { useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { DashboardModuleCard } from "@/components/shell/dashboard-module-card";
import {
  navigationLinksForPermissions,
} from "@/lib/permissions/admin-navigation";

export function DashboardHome() {
  const { admin, mfaRequired } = useAdminAuth();
  const { error, permissions, retry, role, status } = usePermissions();
  const [freshOpen, setFreshOpen] = useState(false);
  const [freshConfirmed, setFreshConfirmed] = useState(false);
  const modules = navigationLinksForPermissions(
    status === "ready" ? permissions : new Set(),
    status === "ready" && role === "owner",
  ).filter(
    (item) => item.id !== "dashboard" && item.implementation === "available",
  );

  return (
    <section className="workspace">
      <AdminPageHeader
        eyebrow="Administration"
        title="ダッシュボード"
        description="現在の管理セッションと利用可能なモジュール"
        action={<span className="status-pill">
          <span aria-hidden="true" />
          セッション有効
        </span>}
      />
      <div className="summary-grid">
        <article className="summary-item">
          <UserRound size={22} aria-hidden="true" />
          <div>
            <span>管理者ID</span>
            <strong className="admin-public-id">{admin?.id ?? "unknown"}</strong>
          </div>
        </article>
        <article className="summary-item">
          <ShieldCheck size={22} aria-hidden="true" />
          <div>
            <span>現在のRole</span>
            <strong>{role ?? admin?.role ?? "unknown"}</strong>
          </div>
        </article>
        <article className="summary-item">
          <KeyRound size={22} aria-hidden="true" />
          <div>
            <span>MFA Policy</span>
            <strong>{freshConfirmed ? "Fresh" : mfaRequired ? "必須" : "任意"}</strong>
          </div>
          <button
            className="secondary-button compact-button"
            onClick={() => setFreshOpen(true)}
            type="button"
          >
            再確認
          </button>
        </article>
      </div>
      {status === "ready" ? (
        <>
          <section className="permission-summary" aria-labelledby="permission-heading">
            <div>
              <span className="eyebrow">Effective permissions</span>
              <h2 id="permission-heading">有効Permission</h2>
            </div>
            <ul>
              {[...permissions].sort().map((permission) => (
                <li key={permission}>
                  <code>{permission}</code>
                </li>
              ))}
            </ul>
          </section>
          <section className="dashboard-modules" aria-labelledby="modules-heading">
            <div className="section-title">
              <h2 id="modules-heading">利用可能なモジュール</h2>
              <span>{modules.length}件</span>
            </div>
            {modules.length ? (
              <div className="module-grid">
                {modules.map((item) => (
                  <DashboardModuleCard item={item} key={item.id} />
                ))}
              </div>
            ) : (
              <div className="empty-workspace dashboard-empty" role="status">
                <h3>利用可能なモジュールはありません</h3>
                <p>有効Permissionが付与されるまで業務メニューは表示されません。</p>
              </div>
            )}
          </section>
        </>
      ) : status === "loading" || status === "idle" ? (
        <section className="empty-workspace" aria-live="polite">
          <p>有効Permissionを確認しています。</p>
        </section>
      ) : (
        <section className="permission-failure" role="alert">
          <AlertTriangle size={24} aria-hidden="true" />
          <div>
            <h2>Permissionを取得できませんでした</h2>
            <p>安全のため業務モジュールを非表示にしています。</p>
            {status === "rate_limited" && error?.retryAfter ? (
              <p>{error.retryAfter}秒後に再試行できます。</p>
            ) : null}
          </div>
          <button className="secondary-button" onClick={retry} type="button">
            <RotateCcw size={17} aria-hidden="true" />
            再試行
          </button>
        </section>
      )}
      <FreshMfaDialog
        onClose={() => setFreshOpen(false)}
        onSuccess={() => {
          setFreshConfirmed(true);
          setFreshOpen(false);
        }}
        open={freshOpen}
      />
    </section>
  );
}
