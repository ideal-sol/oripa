"use client";

import { useAdminAuth } from "@/components/auth/admin-auth-provider";
import { AuthFrame } from "@/components/auth/auth-frame";
import { LoginForm } from "@/components/auth/login-form";
import { RouteGuard } from "@/components/auth/route-guard";

export default function LoginPage() {
  const { phase } = useAdminAuth();
  return (
    <RouteGuard allow={["anonymous", "expired"]}>
      <AuthFrame
        description="管理者アカウントで続行してください。"
        title="管理画面にログイン"
      >
        {phase === "expired" ? (
          <div className="notice notice-warning" role="status">
            管理セッションの有効期限が切れました。再度ログインしてください。
          </div>
        ) : null}
        <LoginForm />
      </AuthFrame>
    </RouteGuard>
  );
}
