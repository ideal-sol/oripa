"use client";

import {
  ChevronDown,
  CircleUserRound,
  ContactRound,
  FileBarChart,
  Gift,
  LayoutDashboard,
  LogOut,
  Menu,
  PackageSearch,
  PanelLeftClose,
  ShieldCheck,
  X,
} from "lucide-react";
import Link from "next/link";
import { type ReactNode, useState } from "react";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { RouteGuard } from "@/components/auth/route-guard";

const futureNavigation = [
  { icon: Gift, label: "カタログ" },
  { icon: PackageSearch, label: "配送" },
  { icon: FileBarChart, label: "レポート" },
  { icon: ContactRound, label: "コンテンツ" },
];

const roleLabel = {
  owner: "Owner",
  admin: "Admin",
  operator: "Operator",
} as const;

export function AdminShell({ children }: { children: ReactNode }) {
  const { admin, loading, logout } = useAdminAuth();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [compact, setCompact] = useState(false);

  return (
    <RouteGuard allow={["authenticated"]}>
      <a className="skip-link" href="#main-content">
        メインコンテンツへ
      </a>
      <div className={`admin-shell ${compact ? "sidebar-compact" : ""}`}>
        <header className="admin-header">
          <button
            aria-expanded={mobileOpen}
            aria-label="ナビゲーションを開く"
            className="icon-button mobile-menu-button"
            onClick={() => setMobileOpen(true)}
            type="button"
          >
            <Menu size={21} />
          </button>
          <div className="header-brand">
            <span className="brand-mark" aria-hidden="true">
              <ShieldCheck size={20} />
            </span>
            <strong>Oripa Admin</strong>
          </div>
          <div className="header-account">
            <div className="role-block">
              <span>{admin ? roleLabel[admin.role] : "Unknown"}</span>
              <small>管理セッション</small>
            </div>
            <CircleUserRound size={24} aria-hidden="true" />
            <ChevronDown size={16} aria-hidden="true" />
          </div>
        </header>
        {mobileOpen ? (
          <button
            aria-label="ナビゲーションを閉じる"
            className="mobile-overlay"
            onClick={() => setMobileOpen(false)}
            type="button"
          />
        ) : null}
        <aside className={`admin-sidebar ${mobileOpen ? "is-open" : ""}`}>
          <div className="sidebar-heading">
            <span className="sidebar-wordmark">Platform</span>
            <button
              aria-label={compact ? "サイドバーを展開" : "サイドバーを折りたたむ"}
              className="icon-button desktop-only"
              onClick={() => setCompact((value) => !value)}
              title={compact ? "サイドバーを展開" : "サイドバーを折りたたむ"}
              type="button"
            >
              <PanelLeftClose size={18} />
            </button>
            <button
              aria-label="ナビゲーションを閉じる"
              className="icon-button mobile-only"
              onClick={() => setMobileOpen(false)}
              type="button"
            >
              <X size={19} />
            </button>
          </div>
          <nav aria-label="管理ナビゲーション">
            <Link aria-current="page" className="nav-item active" href="/">
              <LayoutDashboard size={19} aria-hidden="true" />
              <span>ホーム</span>
            </Link>
            {futureNavigation.map(({ icon: Icon, label }) => (
              <button
                className="nav-item"
                disabled
                key={label}
                title={`${label}は未実装`}
                type="button"
              >
                <Icon size={19} aria-hidden="true" />
                <span>{label}</span>
              </button>
            ))}
          </nav>
          <div className="sidebar-footer">
            <button
              className="nav-item logout-button"
              disabled={loading}
              onClick={logout}
              type="button"
            >
              <LogOut size={19} aria-hidden="true" />
              <span>ログアウト</span>
            </button>
          </div>
        </aside>
        <main className="admin-main" id="main-content" tabIndex={-1}>
          {children}
        </main>
      </div>
    </RouteGuard>
  );
}
