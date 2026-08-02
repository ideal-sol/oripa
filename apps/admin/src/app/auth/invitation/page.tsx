"use client";

import { AuthFrame } from "@/components/auth/auth-frame";
import { InvitationForm } from "@/components/auth/invitation-form";
import { RouteGuard } from "@/components/auth/route-guard";

export default function InvitationAcceptancePage() {
  return (
    <RouteGuard allow={["anonymous", "expired"]}>
      <AuthFrame
        description="有効な招待を受け取った管理者だけが利用できます。"
        title="管理者招待を受け入れる"
      >
        <InvitationForm />
      </AuthFrame>
    </RouteGuard>
  );
}
