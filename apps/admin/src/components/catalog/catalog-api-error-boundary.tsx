import { AlertTriangle, Clock3, RotateCcw, ShieldX } from "lucide-react";

import { AdminApiError } from "@/lib/admin-api/client";

export function CatalogApiErrorBoundary({
  error,
  retry,
}: {
  error: AdminApiError;
  retry: () => void;
}) {
  const forbidden = error.status === 403;
  const rateLimited = error.status === 429;
  const message = catalogProblemMessage(error);
  const Icon = forbidden ? ShieldX : rateLimited ? Clock3 : AlertTriangle;
  return (
    <section className="catalog-state" role="alert">
      <Icon size={28} aria-hidden="true" />
      <h2>
        {forbidden
          ? "アクセスできません"
          : rateLimited
            ? "しばらく待ってください"
            : "操作を完了できませんでした"}
      </h2>
      <p>
        {rateLimited && error.retryAfter
          ? `${error.retryAfter}秒後に再試行できます。`
          : message}
      </p>
      {!forbidden ? (
        <button className="secondary-button" onClick={retry} type="button">
          <RotateCcw size={16} aria-hidden="true" />
          再試行
        </button>
      ) : null}
    </section>
  );
}

export function catalogProblemMessage(error: AdminApiError): string {
  switch (error.code) {
    case "CATALOG_GACHA_PUBLISH_INPUT_REQUIRED":
      return "公開に必要な項目が入力されていません。入力内容を確認してください。";
    case "CATALOG_GACHA_PUBLISH_PRIZE_INSUFFICIENT":
      return "公開に必要な景品が不足しています。Rankごとの景品登録状況を確認してください。";
    case "CATALOG_GACHA_PUBLISH_LIFECYCLE_INVALID":
    case "CATALOG_GACHA_MANAGEMENT_TRANSITION_INVALID":
    case "CATALOG_GACHA_SCHEDULE_CONFLICT":
      return "現在の状態では指定した操作を実行できません。状態を確認してから再度お試しください。";
    case "CATALOG_GACHA_UNPUBLISH_INVALID":
    case "CATALOG_GACHA_UNPUBLISH_CONFLICT":
      return "現在の状態ではガチャを非公開にできません。状態を確認してから再度お試しください。";
    case "CATALOG_GACHA_PUBLISH_INVENTORY_INVALID":
      return "景品在庫とガチャの総口数の整合性を確認してください。";
    case "CATALOG_GACHA_PUBLISH_INTERNAL_FAILURE":
      return "ガチャを保存できませんでした。時間をおいて再度お試しください。";
    default:
      return error.status === 403
        ? "この操作を実行する権限がありません。"
        : "時間をおいて再度お試しください。";
  }
}
