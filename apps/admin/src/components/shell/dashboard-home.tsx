"use client";

import { KeyRound, ShieldCheck } from "lucide-react";
import { useState } from "react";

import { FreshMfaDialog } from "@/components/auth/fresh-mfa-dialog";
import { useAdminAuth } from "@/components/auth/admin-auth-provider";

export function DashboardHome() {
  const { admin } = useAdminAuth();
  const [freshOpen, setFreshOpen] = useState(false);
  const [freshConfirmed, setFreshConfirmed] = useState(false);

  return (
    <section className="workspace">
      <header className="workspace-header">
        <div>
          <span className="eyebrow">Administration</span>
          <h1>管理ホーム</h1>
          <p>現在の管理セッションと認証状態を確認できます。</p>
        </div>
        <span className="status-pill">
          <span aria-hidden="true" />
          Session active
        </span>
      </header>
      <div className="summary-grid">
        <article className="summary-item">
          <ShieldCheck size={22} aria-hidden="true" />
          <div>
            <span>Admin Role</span>
            <strong>{admin?.role ?? "unknown"}</strong>
          </div>
        </article>
        <article className="summary-item">
          <KeyRound size={22} aria-hidden="true" />
          <div>
            <span>MFA</span>
            <strong>{freshConfirmed ? "Fresh" : "Verified"}</strong>
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
      <section className="empty-workspace" aria-labelledby="workspace-empty-title">
        <LayoutGraphic />
        <h2 id="workspace-empty-title">業務モジュールは未設定です</h2>
        <p>利用可能な管理機能は、後続の権限設定後に表示されます。</p>
      </section>
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

function LayoutGraphic() {
  return (
    <div className="empty-graphic" aria-hidden="true">
      <span />
      <span />
      <span />
    </div>
  );
}
