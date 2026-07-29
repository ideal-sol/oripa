"use client";

import { RefreshCw } from "lucide-react";

export function CatalogConflictBoundary({
  onReload,
}: {
  onReload: () => void;
}) {
  return (
    <section aria-live="assertive" className="catalog-conflict" role="alert">
      <div>
        <strong>最新状態との競合を検出しました</strong>
        <p>入力内容は確定されていません。最新のMasterを再取得してください。</p>
      </div>
      <button className="secondary-button" onClick={onReload} type="button">
        <RefreshCw size={16} aria-hidden="true" />
        再取得
      </button>
    </section>
  );
}
