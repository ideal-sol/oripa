"use client";

import Link from "next/link";
import { useEffect, useMemo } from "react";

export default function ErrorPage({
  error,
  reset,
}: {
  error: Error & { digest?: string };
  reset: () => void;
}) {
  const isAdmin = useMemo(() => {
    if (typeof window === "undefined") {
      return false;
    }

    return window.location.hostname.startsWith("admin.");
  }, []);

  useEffect(() => {
    console.error(error);
  }, [error]);

  if (isAdmin) {
    return (
      <main className="admin-error-page">
        <section className="admin-error-panel admin-error-panel-danger">
          <div className="admin-error-code">Error</div>
          <div className="admin-error-copy">
            <span className="section-kicker">System error</span>
            <h1>管理画面でエラーが発生しました</h1>
            <p>一時的な通信エラー、または画面の読み込みに失敗した可能性があります。再試行しても解消しない場合は操作内容を控えて管理者へ共有してください。</p>
            {error.digest ? <small>Digest: {error.digest}</small> : null}
          </div>
          <div className="admin-error-actions">
            <button type="button" onClick={reset}>再読み込み</button>
            <Link className="secondary-button" href="/">管理トップへ戻る</Link>
          </div>
        </section>
      </main>
    );
  }

  return (
    <main className="public-error-page">
      <section className="public-error-hero public-error-hero-danger">
        <div className="public-error-media" aria-hidden="true">
          <span>!</span>
        </div>
        <div className="public-error-copy">
          <span className="public-kicker">LUXE PACK</span>
          <h1>ページの表示中にエラーが発生しました</h1>
          <p>時間をおいて再度お試しください。ポイント購入や抽選の途中で発生した場合は、履歴をご確認のうえ必要に応じてお問い合わせください。</p>
          <div className="public-error-actions">
            <button type="button" onClick={reset}>もう一度試す</button>
            <Link className="public-secondary-link" href="/">トップへ戻る</Link>
          </div>
        </div>
      </section>
    </main>
  );
}
