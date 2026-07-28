"use client";

import { useRouter } from "next/navigation";
import { type ReactNode, useEffect } from "react";

import { AuthLoading } from "./auth-status";
import { type AuthPhase, useAdminAuth } from "./admin-auth-provider";

const phaseRoute: Partial<Record<AuthPhase, string>> = {
  anonymous: "/login",
  authenticated: "/",
  enrollment: "/auth/enroll",
  expired: "/login",
  mfa: "/auth/mfa",
};

export function RouteGuard({
  allow,
  children,
}: {
  allow: AuthPhase[];
  children: ReactNode;
}) {
  const router = useRouter();
  const { phase } = useAdminAuth();
  const accepted = allow.includes(phase);

  useEffect(() => {
    if (!accepted && phase !== "loading") {
      router.replace(phaseRoute[phase] ?? "/login");
    }
  }, [accepted, phase, router]);

  if (!accepted) return <AuthLoading label="セッションを確認中" />;
  return children;
}
