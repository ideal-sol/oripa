import type { Metadata } from "next";

import Link from "next/link";
import { ProtectedAdminRoute } from "@/components/permissions/protected-admin-route";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminShell } from "@/components/shell/admin-shell";

export const metadata: Metadata = { title: "ガチャ 登録" };

export default function GachaCreatePage() {
  return <AdminShell><ProtectedAdminRoute permission="catalog.manage"><div className="workspace">
    <AdminPageHeader eyebrow="ガチャ管理" title="ガチャ登録" description="登録するガチャ種別を選択してください。保存後の種別変更はできません。" />
    <nav className="catalog-toolbar" aria-label="ガチャ種別">
      <Link className="primary-button" href="/catalog/gachas/new/standard">通常ガチャを登録</Link>
      <Link className="primary-button" href="/catalog/gachas/new/signup">新規登録限定ガチャを登録</Link>
      <Link className="primary-button" href="/catalog/gachas/new/login">ログインガチャを登録</Link>
    </nav>
  </div></ProtectedAdminRoute></AdminShell>;
}
