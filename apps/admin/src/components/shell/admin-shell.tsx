"use client";

import {
  ChevronDown,
  CircleUserRound,
  LogOut,
  Menu,
  PanelLeftClose,
  ShieldCheck,
  X,
} from "lucide-react";
import { type ReactNode, useEffect, useRef, useState } from "react";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { RouteGuard } from "@/components/auth/route-guard";
import { AdminNavigation } from "@/components/navigation/admin-navigation";

const roleLabel = {
  owner: "Owner",
  admin: "Admin",
  operator: "Operator",
} as const;

export function AdminShell({ children }: { children: ReactNode }) {
  const { admin, loading, logout } = useAdminAuth();
  const [mobileOpen, setMobileOpen] = useState(false);
  const [compact, setCompact] = useState(false);
  const mobileTrigger = useRef<HTMLButtonElement>(null);
  const mobileClose = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (!mobileOpen) return;
    mobileClose.current?.focus();
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setMobileOpen(false);
        mobileTrigger.current?.focus();
      }
    };
    document.addEventListener("keydown", closeOnEscape);
    return () => document.removeEventListener("keydown", closeOnEscape);
  }, [mobileOpen]);

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
            ref={mobileTrigger}
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
            onClick={() => {
              setMobileOpen(false);
              mobileTrigger.current?.focus();
            }}
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
              onClick={() => {
                setMobileOpen(false);
                mobileTrigger.current?.focus();
              }}
              ref={mobileClose}
              type="button"
            >
              <X size={19} />
            </button>
          </div>
          <AdminNavigation onNavigate={() => setMobileOpen(false)} />
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
