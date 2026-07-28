"use client";

import {
  createContext,
  type ReactNode,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { AdminApiClient, AdminApiError } from "@/lib/admin-api/client";
import {
  ADMIN_PERMISSION_CODES,
  type AdminPermissionCode,
  type AdminRole,
} from "@/lib/admin-api/generated";

export type PermissionStatus =
  | "idle"
  | "loading"
  | "ready"
  | "forbidden"
  | "rate_limited"
  | "error";

interface PermissionError {
  requestId: string | null;
  retryAfter: number | null;
}

interface PermissionContextValue {
  error: PermissionError | null;
  hasPermission: (permission: AdminPermissionCode) => boolean;
  permissions: ReadonlySet<AdminPermissionCode>;
  requestId: string | null;
  retry: () => void;
  role: AdminRole | null;
  status: PermissionStatus;
}

interface PermissionResult {
  error: PermissionError | null;
  key: string;
  permissions: AdminPermissionCode[];
  requestId: string | null;
  role: AdminRole | null;
  status: Exclude<PermissionStatus, "idle" | "loading">;
}

const PermissionContext = createContext<PermissionContextValue | null>(null);
const knownPermissions = new Set<string>(ADMIN_PERMISSION_CODES);

export function PermissionProvider({ children }: { children: ReactNode }) {
  const { admin, expireSession, phase } = useAdminAuth();
  const client = useMemo(() => new AdminApiClient(), []);
  const [result, setResult] = useState<PermissionResult | null>(null);
  const [attempt, setAttempt] = useState(0);
  const requestKey = admin ? `${admin.id}:${attempt}` : `anonymous:${attempt}`;

  useEffect(() => {
    if (phase !== "authenticated" || !admin) {
      return;
    }

    const controller = new AbortController();
    client
      .getPermissions(controller.signal)
      .then((response) => {
        const unique = new Set(response.permissions);
        if (
          response.role !== admin.role ||
          unique.size !== response.permissions.length ||
          response.permissions.some((permission) => !knownPermissions.has(permission))
        ) {
          throw new Error("Invalid permission response.");
        }
        setResult({
          error: null,
          key: requestKey,
          permissions: [...response.permissions],
          requestId: response.request_id,
          role: response.role,
          status: "ready",
        });
      })
      .catch((cause: unknown) => {
        if (controller.signal.aborted) return;
        if (cause instanceof AdminApiError) {
          if (cause.status === 401) {
            expireSession();
            return;
          }
          setResult({
            error: {
              requestId: cause.requestId,
              retryAfter: cause.retryAfter,
            },
            key: requestKey,
            permissions: [],
            requestId: null,
            role: null,
            status:
              cause.status === 403
                ? "forbidden"
                : cause.status === 429
                  ? "rate_limited"
                  : "error",
          });
          return;
        }
        setResult({
          error: { requestId: null, retryAfter: null },
          key: requestKey,
          permissions: [],
          requestId: null,
          role: null,
          status: "error",
        });
      });

    return () => controller.abort();
  }, [admin, client, expireSession, phase, requestKey]);

  const activeResult = result?.key === requestKey ? result : null;
  const status: PermissionStatus =
    phase !== "authenticated" || !admin
      ? "idle"
      : activeResult?.status ?? "loading";

  const permissionSet = useMemo(
    () =>
      new Set<AdminPermissionCode>(
        activeResult?.status === "ready" ? activeResult.permissions : [],
      ),
    [activeResult],
  );
  const hasPermission = useCallback(
    (permission: AdminPermissionCode) =>
      status === "ready" && permissionSet.has(permission),
    [permissionSet, status],
  );
  const value = useMemo<PermissionContextValue>(
    () => ({
      error: activeResult?.error ?? null,
      hasPermission,
      permissions: permissionSet,
      requestId: activeResult?.requestId ?? null,
      retry: () => setAttempt((value) => value + 1),
      role: activeResult?.role ?? null,
      status,
    }),
    [activeResult, hasPermission, permissionSet, status],
  );

  return (
    <PermissionContext.Provider value={value}>
      {children}
    </PermissionContext.Provider>
  );
}

export function usePermissions(): PermissionContextValue {
  const context = useContext(PermissionContext);
  if (!context) {
    throw new Error("PermissionProvider is required.");
  }
  return context;
}
