"use client";

import Link from "next/link";
import type { AdminMemberRankHistoryItem } from "@/lib/admin-api/generated";
import { historyReasons, manualKinds, rankDate, rankName } from "./member-rank-labels";
import styles from "./member-ranks.module.css";

export function MemberRankHistory({ items, points = false, showUser = false }: { items: AdminMemberRankHistoryItem[]; points?: boolean; showUser?: boolean }) {
  if (items.length === 0) return <p>履歴はありません。</p>;
  return <div className={styles.scroll} tabIndex={0}><table className={styles.table}>
    <thead><tr><th>日時（日本時間）</th>{showUser ? <th>会員</th> : null}<th>内容</th><th>集計月</th><th>{points ? "増減" : "ランク・ボーナス（無償）"}</th><th>管理者・理由</th></tr></thead>
    <tbody>{items.map((item) => <tr key={item.id}>
      <td>{rankDate(item.occurred_at)}</td>{showUser ? <td><Link href={`/users/${item.user_id}`}>{item.user_id}</Link></td> : null}
      <td>{historyReasons[item.reason] ?? item.reason}{item.kind ? <p>{manualKinds[item.kind]}</p> : null}{item.payment_id ? <p><Link href={`/payments/${item.payment_id}#adjustment-${item.adjustment_id}`}>元決済・取消記録</Link></p> : item.origin_id ? <p>{item.origin_id}</p> : null}</td>
      <td>{item.month.slice(0, 7)}</td>
      <td>{points ? <>{item.applied_delta?.toLocaleString()}<p>依頼：{item.requested_delta?.toLocaleString()}</p></> : <>
        {rankName(item.from_rank)} → {rankName(item.to_rank)}
        <p>設定版 {item.setting_revision} / {item.rank_points?.toLocaleString()}pt</p>
        {item.grant_bonus !== null ? <p>{item.grant_bonus ? "付与する" : "付与しない"}：{item.bonus_amount?.toLocaleString()}pt / 見送り{item.skip_count}回</p> : null}
      </>}</td>
      <td><p>{item.actor_id ?? "システム"}</p><p className={styles.reason}>{item.reason_text ?? "—"}</p></td>
    </tr>)}</tbody>
  </table></div>;
}
