import { AuthFrame } from "@/components/auth/auth-frame";
import { RecoveryPanel } from "@/components/auth/recovery-panel";
import { RouteGuard } from "@/components/auth/route-guard";

export default function AdminRecoveryPage() {
  return (
    <RouteGuard allow={["mfa", "authenticated"]}>
      <AuthFrame
        description="1回限りのコードを使用または再生成します。"
        title="リカバリーコード"
      >
        <RecoveryPanel />
      </AuthFrame>
    </RouteGuard>
  );
}
