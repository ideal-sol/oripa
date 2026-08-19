"use client";

import { RefreshCw } from "lucide-react";

import type { AdminApiError } from "@/lib/admin-api/client";

export function CatalogConflictBoundary({
  error,
  onReload,
}: {
  error?: AdminApiError;
  onReload: () => void;
}) {
  const publishedReference = error?.code === "CATALOG_PUBLISHED_REFERENCE_CONFLICT";
  const revisionConflict = !error || error.code === "CATALOG_REVISION_CONFLICT";
  return (
    <section aria-live="assertive" className="catalog-conflict" role="alert">
      <div>
        <strong>
          {revisionConflict
            ? "最新状態との競合を検出しました"
            : publishedReference
              ? "公開中Gachaの参照により変更できません"
              : "Catalogの変更を完了できませんでした"}
        </strong>
        <p>
          {revisionConflict
            ? "入力内容は確定されていません。最新のMasterを再取得してください。"
            : publishedReference
              ? "公開中Gachaから参照されているため、SlugまたはArchiveは変更できません。"
              : error?.message}
        </p>
      </div>
      <button className="secondary-button" onClick={onReload} type="button">
        <RefreshCw size={16} aria-hidden="true" />
        {revisionConflict ? "再取得" : "閉じる"}
      </button>
    </section>
  );
}
