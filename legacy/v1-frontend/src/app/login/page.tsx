import Image from "next/image";
import Link from "next/link";
import AuthClient from "./auth-client";
import PublicHeader from "../public-header";

type LoginPageProps = {
  searchParams?: Promise<{
    email_verified?: string | string[];
    mode?: string | string[];
    redirect?: string | string[];
  }>;
};

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const params = await searchParams;
  const mode = params?.mode === "register" ? "register" : "login";
  const redirect = typeof params?.redirect === "string" ? params.redirect : "/";
  const emailVerified = typeof params?.email_verified === "string" ? params.email_verified : null;
  const initialMessage = emailVerified === "success"
    ? "メールアドレス確認が完了しました。ログインしてください。"
    : emailVerified === "invalid"
      ? "メールアドレス確認URLが無効、または有効期限切れです。"
      : null;

  return (
    <main className="public-shell">
      <PublicHeader />
      <AuthClient initialMessage={initialMessage} initialMode={mode} redirectTo={redirect} />
      <footer className="public-footer">
        <div>
          <Image className="public-footer-logo" src="/lp-logo.png" alt="Luxe Pack" width={296} height={71} unoptimized />
          <span>Account</span>
        </div>
        <nav aria-label="アカウント補助">
          <Link href="/points/purchase">ポイント購入</Link>
          <Link href="/mypage/prizes">景品BOX</Link>
        </nav>
      </footer>
    </main>
  );
}
