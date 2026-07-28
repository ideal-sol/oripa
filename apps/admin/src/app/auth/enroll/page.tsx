import { AuthFrame } from "@/components/auth/auth-frame";
import { EnrollmentForm } from "@/components/auth/enrollment-form";
import { RouteGuard } from "@/components/auth/route-guard";

export default function AdminEnrollmentPage() {
  return (
    <RouteGuard allow={["enrollment"]}>
      <AuthFrame
        description="管理アカウントに必要な認証器を登録してください。"
        title="認証器の登録"
      >
        <EnrollmentForm />
      </AuthFrame>
    </RouteGuard>
  );
}
