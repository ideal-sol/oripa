import { AuthFrame } from "@/components/auth/auth-frame";
import { MfaForm } from "@/components/auth/mfa-form";
import { RouteGuard } from "@/components/auth/route-guard";

export default function AdminMfaPage() {
  return (
    <RouteGuard allow={["mfa"]}>
      <AuthFrame
        description="登録済みの認証方法で本人確認を完了してください。"
        title="多要素認証"
      >
        <MfaForm />
      </AuthFrame>
    </RouteGuard>
  );
}
