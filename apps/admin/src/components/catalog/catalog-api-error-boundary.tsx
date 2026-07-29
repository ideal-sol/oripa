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
  const Icon = forbidden ? ShieldX : rateLimited ? Clock3 : AlertTriangle;
  return (
    <section className="catalog-state" role="alert">
      <Icon size={28} aria-hidden="true" />
      <h2>
        {forbidden
          ? "アクセスできません"
          : rateLimited
            ? "しばらく待ってください"
            : "Catalogを取得できませんでした"}
      </h2>
      <p>
        {rateLimited && error.retryAfter
          ? `${error.retryAfter}秒後に再試行できます。`
          : "Request IDを確認して再試行してください。"}
      </p>
      {error.requestId ? <code>Request ID: {error.requestId}</code> : null}
      {!forbidden ? (
        <button className="secondary-button" onClick={retry} type="button">
          <RotateCcw size={16} aria-hidden="true" />
          再試行
        </button>
      ) : null}
    </section>
  );
}
