import Link from "next/link";
import { headers } from "next/headers";

export default async function NotFound() {
  const host = (await headers()).get("host") ?? "";
  const isAdmin = host.startsWith("admin.");

  if (isAdmin) {
    return (
      <main className="admin-error-page">
        <section className="admin-error-panel">
          <div className="admin-error-code">404</div>
          <div className="admin-error-copy">
            <span className="section-kicker">Page not found</span>
            <h1>管理画面のページが見つかりません</h1>
            <p>URLが変更されたか、アクセスしようとした管理機能が存在しません。左メニューから目的の画面へ戻ってください。</p>
          </div>
          <div className="admin-error-actions">
            <Link className="primary-button" href="/">管理トップへ戻る</Link>
          </div>
        </section>
      </main>
    );
  }

  return (
    <main className="public-error-page">
      <section className="public-error-hero">
        <div className="public-error-media" aria-hidden="true">
          <span>404</span>
        </div>
        <div className="public-error-copy">
          <span className="public-kicker">LUXE PACK</span>
          <h1>お探しのページが見つかりません</h1>
          <p>ページが移動したか、公開が終了している可能性があります。トップページから公開中のガチャやお知らせをご確認ください。</p>
          <div className="public-error-actions">
            <Link className="public-primary-link dark" href="/">トップへ戻る</Link>
            <Link className="public-secondary-link" href="/contact">お問い合わせ</Link>
          </div>
        </div>
      </section>
    </main>
  );
}
