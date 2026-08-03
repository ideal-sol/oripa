"use client";

import {
  ChevronDown,
  CircleUserRound,
  LogOut,
  Menu,
  PanelLeftClose,
  PanelLeftOpen,
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
  const [userMenuOpen, setUserMenuOpen] = useState(false);
  const mobileTrigger = useRef<HTMLButtonElement>(null);
  const mobileClose = useRef<HTMLButtonElement>(null);
  const sidebar = useRef<HTMLElement>(null);
  const userMenu = useRef<HTMLDivElement>(null);
  const userMenuTrigger = useRef<HTMLButtonElement>(null);

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

  useEffect(() => {
    if (!userMenuOpen) return;
    const closeMenu = (event: PointerEvent) => {
      if (!userMenu.current?.contains(event.target as Node)) {
        setUserMenuOpen(false);
      }
    };
    const closeOnEscape = (event: KeyboardEvent) => {
      if (event.key === "Escape") {
        setUserMenuOpen(false);
        userMenuTrigger.current?.focus();
      }
    };
    document.addEventListener("pointerdown", closeMenu);
    document.addEventListener("keydown", closeOnEscape);
    return () => {
      document.removeEventListener("pointerdown", closeMenu);
      document.removeEventListener("keydown", closeOnEscape);
    };
  }, [userMenuOpen]);

  function keepFocusInDrawer(event: React.KeyboardEvent<HTMLElement>) {
    if (!mobileOpen || event.key !== "Tab") return;
    const controls = sidebar.current?.querySelectorAll<HTMLElement>(
      'a[href], button:not([disabled])',
    );
    if (!controls?.length) return;
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  }

  return (
    <RouteGuard allow={["authenticated"]}>
      <a className="skip-link" href="#main-content">
        メインコンテンツへ
      </a>
      <div className={`admin-shell ${compact ? "sidebar-compact" : ""}`}>
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
        <aside
          aria-label="管理サイドバー"
          className={`admin-sidebar ${mobileOpen ? "is-open" : ""}`}
          onKeyDown={keepFocusInDrawer}
          ref={sidebar}
        >
          <div className="sidebar-heading">
            <div className="sidebar-brand">
              <span className="brand-mark" aria-hidden="true">
                <ShieldCheck size={20} />
              </span>
              <span className="sidebar-brand-copy">
                <strong>Oripa Admin</strong>
                <small>Platform Console</small>
              </span>
            </div>
            <button
              aria-label={compact ? "サイドバーを展開" : "サイドバーを折りたたむ"}
              className="icon-button desktop-only"
              onClick={() => setCompact((value) => !value)}
              title={compact ? "サイドバーを展開" : "サイドバーを折りたたむ"}
              type="button"
            >
              {compact ? <PanelLeftOpen size={18} /> : <PanelLeftClose size={18} />}
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
        </aside>
        <div className="admin-shell-body">
          <header className="admin-header">
            <div className="header-leading">
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
              <div className="header-context">
                <strong>管理コンソール</strong>
                <span>Administration</span>
              </div>
            </div>
            <div className="account-menu" ref={userMenu}>
              <button
                aria-expanded={userMenuOpen}
                aria-haspopup="menu"
                aria-label="ユーザーメニュー"
                className="account-trigger"
                onClick={() => setUserMenuOpen((open) => !open)}
                ref={userMenuTrigger}
                type="button"
              >
                <span className="account-avatar" aria-hidden="true">
                  <CircleUserRound size={21} />
                </span>
                <span className="role-block">
                  <strong>{admin ? roleLabel[admin.role] : "Unknown"}</strong>
                  <small>管理セッション</small>
                </span>
                <ChevronDown size={16} aria-hidden="true" />
              </button>
              {userMenuOpen ? (
                <div className="account-popover" role="menu">
                  <div className="account-summary">
                    <span>Current admin</span>
                    <strong>{admin ? roleLabel[admin.role] : "Unknown"}</strong>
                    <small>{admin?.id ?? "unknown"}</small>
                  </div>
                  <button
                    disabled={loading}
                    onClick={() => void logout()}
                    role="menuitem"
                    type="button"
                  >
                    <LogOut size={17} aria-hidden="true" />
                    ログアウト
                  </button>
                </div>
              ) : null}
            </div>
          </header>
          <main className="admin-main" id="main-content" tabIndex={-1}>
            {children}
          </main>
        </div>
      </div>
    </RouteGuard>
  );
}
