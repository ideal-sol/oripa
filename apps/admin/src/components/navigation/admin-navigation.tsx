"use client";

import { ChevronDown } from "lucide-react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { useMemo, useState } from "react";

import { NavigationIcon } from "@/components/navigation/navigation-icon";
import { usePermissions } from "@/components/permissions/permission-provider";
import {
  activeNavigationItem,
  type AdminNavigationGroupId,
  navigationForPermissions,
} from "@/lib/permissions/admin-navigation";

export function AdminNavigation({
  compact = false,
  onNavigate,
  onRequestExpand,
}: {
  compact?: boolean;
  onNavigate?: () => void;
  onRequestExpand?: () => void;
}) {
  const pathname = usePathname();
  const { permissions, role, status } = usePermissions();
  const nodes = useMemo(
    () => navigationForPermissions(
      status === "ready" ? permissions : new Set(),
      status === "ready" && role === "owner",
    ),
    [permissions, role, status],
  );
  const activeItem = activeNavigationItem(pathname, nodes);
  const activeGroup = nodes.find(
    (node) =>
      node.kind === "group" &&
      node.children.some((item) => item.id === activeItem?.id),
  );
  const activeGroupId = activeGroup?.kind === "group" ? activeGroup.id : null;
  const [manualExpansion, setManualExpansion] = useState<{
    group: AdminNavigationGroupId | null;
    pathname: string;
  } | null>(null);
  const expandedGroup = manualExpansion?.pathname === pathname
    ? manualExpansion.group
    : activeGroupId;

  return (
    <nav aria-label="管理ナビゲーション">
      {nodes.map((node) => {
        if (node.kind === "link") {
          const active = activeItem?.id === node.id;
          return (
            <Link
              aria-current={active ? "page" : undefined}
              className={`nav-item ${active ? "active" : ""}`}
              href={node.path}
              key={node.id}
              onClick={onNavigate}
              title={node.label}
            >
              <NavigationIcon name={node.icon} size={19} aria-hidden="true" />
              <span>{node.label}</span>
            </Link>
          );
        }

        const expanded = expandedGroup === node.id;
        const active = activeGroupId === node.id;
        const controlsId = `admin-nav-${node.id}`;
        return (
          <section className="nav-group" key={node.id}>
            <button
              aria-controls={controlsId}
              aria-expanded={expanded}
              className={`nav-item nav-parent ${active ? "active" : ""}`}
              onClick={() => {
                if (compact) {
                  onRequestExpand?.();
                  setManualExpansion({ group: node.id, pathname });
                  return;
                }
                setManualExpansion({
                  group: expandedGroup === node.id ? null : node.id,
                  pathname,
                });
              }}
              title={node.label}
              type="button"
            >
              <NavigationIcon name={node.icon} size={19} aria-hidden="true" />
              <span>{node.label}</span>
              <ChevronDown
                aria-hidden="true"
                className="nav-chevron"
                size={16}
              />
            </button>
            <div
              className="nav-children"
              hidden={!expanded}
              id={controlsId}
            >
              {node.children.map((item) => {
                const childActive = activeItem?.id === item.id;
                return (
                  <Link
                    aria-current={childActive ? "page" : undefined}
                    className={`nav-child ${childActive ? "active" : ""}`}
                    href={item.path}
                    key={item.id}
                    onClick={onNavigate}
                  >
                    <span>{item.label}</span>
                  </Link>
                );
              })}
            </div>
          </section>
        );
      })}
    </nav>
  );
}
