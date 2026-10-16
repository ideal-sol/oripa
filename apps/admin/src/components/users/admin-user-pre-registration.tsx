"use client";

import { useEffect, useState } from "react";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminUserPreRegistrationResponse } from "@/lib/admin-api/generated";

export function AdminUserPreRegistration({ userId }: { userId: string }) {
  const [data, setData] = useState<AdminUserPreRegistrationResponse["data"] | null>(null);
  const [error, setError] = useState(false);
  useEffect(() => {
    const controller = new AbortController();
    new AdminApiClient().getUserPreRegistration(userId, controller.signal)
      .then((response) => { if (!controller.signal.aborted) setData(response.data); })
      .catch(() => { if (!controller.signal.aborted) setError(true); });
    return () => controller.abort();
  }, [userId]);
  const date = (value: string | null) => value === null ? "未完了" : new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "medium", timeZone: "Asia/Tokyo" }).format(new Date(value));
  return <section className="admin-user-summary"><h2>事前登録特典</h2>{error ? <p role="alert">事前登録情報を取得できませんでした。</p> : data ? <dl className="admin-user-definition-grid">
    <div><dt>事前登録日時</dt><dd>{data.pre_registered_at === null ? "専用申込なし" : date(data.pre_registered_at)}</dd></div>
    <div><dt>メール／SMS認証</dt><dd>{data.email_verified ? "メール完了" : "メール未完了"}／{data.sms_verified ? "SMS完了" : "SMS未完了"}</dd></div>
    <div><dt>現在の登録日時</dt><dd>{date(data.created_at)}</dd></div>
    <div><dt>本登録切替・付与日時</dt><dd>{date(data.activated_at)}</dd></div>
    <div><dt>付与した無償ポイント</dt><dd>{data.point_amount ?? "未付与"}</dd></div>
    <div><dt>有効期限</dt><dd>{date(data.expires_at)}</dd></div>
  </dl> : <p role="status">読み込み中です</p>}</section>;
}
