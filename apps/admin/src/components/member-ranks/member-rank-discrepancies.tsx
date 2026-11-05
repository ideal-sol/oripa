"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import { usePermissions } from "@/components/permissions/permission-provider";
import { AdminShell } from "@/components/shell/admin-shell";
import { AdminPageHeader } from "@/components/shell/admin-page-header";
import { AdminApiClient } from "@/lib/admin-api/client";
import type { AdminMemberRankDiscrepancies } from "@/lib/admin-api/generated";
import { rankName } from "./member-rank-labels";
import styles from "./member-ranks.module.css";

export function MemberRankDiscrepancies() {
  return <AdminShell><OwnerList /></AdminShell>;
}

function OwnerList() {
  const { role, status, retry } = usePermissions();
  if (status === "idle" || status === "loading") return <p role="status">権限を確認しています。</p>;
  if (status !== "ready") return <div role="alert">権限を確認できません。<button className="secondary-button" onClick={retry}>再取得</button></div>;
  if (role !== "owner") return <p role="alert">Ownerのみ閲覧できます。</p>;
  return <DiscrepancyList />;
}

function DiscrepancyList() {
  const client = useMemo(() => new AdminApiClient(), []);
  const [data, setData] = useState<AdminMemberRankDiscrepancies | null>(null);
  const [cursor, setCursor] = useState<string | undefined>();
  const [error, setError] = useState<string | null>(null);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    client.getMemberRankDiscrepancies(cursor, controller.signal).then(result => {
      if (!controller.signal.aborted) { setData(result); setError(null); }
    }).catch((caught: unknown) => {
      if (!controller.signal.aborted) setError(caught instanceof Error ? caught.message : "取得できませんでした。");
    });
    return () => controller.abort();
  }, [client, cursor, retry]);
  return <section className={`workspace ${styles.stack}`}>
    <AdminPageHeader eyebrow="Users" title="ランク変動" description="現在ランクの到達ラインと累計ポイントの乖離を確認します。自動降格は行いません。" />
    <div className={styles.header}><button className="secondary-button" type="button" onClick={() => { setData(null); setRetry(value => value + 1); }}>再取得</button></div>
    <div className={`${styles.panel} ${styles.stack}`}>
    {error ? <p role="alert">{error}</p> : null}
    {!data && !error ? <p role="status">読み込み中です。</p> : null}
    {data?.items.length === 0 ? <p>条件に該当する有効会員はいません。</p> : null}
    {data && data.items.length > 0 ? <div className={styles.scroll} tabIndex={0}><table className={styles.table}>
      <thead><tr><th>会員</th><th>現在ランク</th><th>累計pt</th><th>到達ライン</th><th>不足pt</th></tr></thead>
      <tbody>{data.items.map(item => <tr key={item.user_id}><td><Link href={`/users/${item.user_id}`}>{item.user_id}</Link></td>
        <td>{rankName(item.rank)}</td><td>{item.rank_points.toLocaleString()}</td><td>{item.reach_line.toLocaleString()}</td><td>{item.shortfall.toLocaleString()}</td></tr>)}</tbody>
    </table></div> : null}
    {data?.next_cursor ? <button className="secondary-button" type="button" onClick={() => { setCursor(data.next_cursor ?? undefined); setData(null); }}>次の会員を表示</button> : null}
    {cursor ? <button className="secondary-button" type="button" onClick={() => { setCursor(undefined); setData(null); }}>最初のページ</button> : null}
    </div>
  </section>;
}
