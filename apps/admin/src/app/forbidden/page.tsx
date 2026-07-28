import { ShieldX } from "lucide-react";
import Link from "next/link";

export default function ForbiddenPage() {
  return (
    <main className="full-page-state">
      <ShieldX size={30} aria-hidden="true" />
      <h1>アクセスできません</h1>
      <p>この操作を実行する権限がありません。</p>
      <Link className="secondary-button" href="/">
        管理ホームへ
      </Link>
    </main>
  );
}
