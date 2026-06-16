import Link from "next/link";
import AuthClient from "./auth-client";
import PublicHeader from "../public-header";

type LoginPageProps = {
  searchParams?: Promise<{
    mode?: string | string[];
    redirect?: string | string[];
  }>;
};

export default async function LoginPage({ searchParams }: LoginPageProps) {
  const params = await searchParams;
  const mode = params?.mode === "register" ? "register" : "login";
  const redirect = typeof params?.redirect === "string" ? params.redirect : "/";

  return (
    <main className="public-shell">
      <PublicHeader />
      <AuthClient initialMode={mode} redirectTo={redirect} />
      <footer className="public-footer">
        <div>
          <strong>Luxe Pack</strong>
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
