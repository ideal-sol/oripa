import type { AdminMemberRankCode, AdminMemberRankManualKind } from "@/lib/admin-api/generated";

export const rankNames: Record<AdminMemberRankCode, string> = {
  beginner: "ビギナー", bronze: "ブロンズ", silver: "シルバー", gold: "ゴールド",
  platinum: "プラチナ", diamond: "ダイヤモンド", vip: "VIP",
};
export const manualKinds: Record<AdminMemberRankManualKind, string> = {
  transfer: "引き継ぎ", support: "問い合わせ対応", payment_incident: "決済事故の対応", other: "その他",
};
export const historyReasons: Record<string, string> = {
  promotion: "昇格", demotion: "降格", manual: "手動変更", promotion_reversal: "昇格の取消",
  payment: "決済", manual_rank_points: "ランクポイント手動調整",
};
export function rankName(value: string | null | undefined): string {
  return value && value in rankNames ? rankNames[value as AdminMemberRankCode] : "—";
}
export function rankDate(value: string | null | undefined): string {
  return value ? new Intl.DateTimeFormat("ja-JP", { timeZone: "Asia/Tokyo", dateStyle: "medium", timeStyle: "short" }).format(new Date(value)) : "—";
}
