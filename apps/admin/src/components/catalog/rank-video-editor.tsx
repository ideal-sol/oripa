"use client";

import { PublicAssetPreview } from "@/components/catalog/public-asset-preview";
import type { AdminRankEffect, AdminRankVideoInput } from "@/lib/admin-api/generated";

export function videoWeightTotal(items: AdminRankVideoInput[]) {
  let units = 0;
  let valid = items.length >= 1 && items.length <= 10;
  const identifiers = new Set<string>();
  for (const item of items) {
    if (!/^(?:100(?:\.0{1,2})?|(?:0|[1-9][0-9]?)(?:\.[0-9]{1,2})?)$/.test(item.weight_percent)) {
      valid = false;
      continue;
    }
    const [whole, fraction = ""] = item.weight_percent.split(".");
    const weight = Number(whole) * 100 + Number(fraction.padEnd(2, "0"));
    units += weight;
    if (weight < 1 || !item.video_asset_id || identifiers.has(item.video_asset_id)) valid = false;
    identifiers.add(item.video_asset_id);
  }
  return { valid: valid && units === 10000, label: `${Math.floor(units / 100)}.${String(units % 100).padStart(2, "0")}%` };
}

export function RankVideoEditor({ name, items, materials, disabled, busy = false, onChange, onSave, onUnset }: {
  name: string;
  items: AdminRankVideoInput[];
  materials: AdminRankEffect[];
  disabled: boolean;
  busy?: boolean;
  onChange: (items: AdminRankVideoInput[]) => void;
  onSave?: () => void;
  onUnset?: () => void;
}) {
  const total = videoWeightTotal(items);
  function change(next: AdminRankVideoInput[]) {
    onChange(next.length === 1 ? [{ ...next[0], weight_percent: "100.00" }] : next);
  }
  return <fieldset className="catalog-rank-video-control" disabled={disabled || busy}>
    <legend>{name}の演出動画</legend>
    {items.length === 0 ? <span>未設定</span> : null}
    {items.map((item, index) => <div key={index}>
      <label>{name}の動画{index + 1}
        <select aria-label={`${name}の動画${index + 1}`} value={item.video_asset_id} onChange={(event) => change(items.map((entry, position) => position === index ? { ...entry, video_asset_id: event.target.value } : entry))}>
          <option value="">選択してください</option>
          {materials.map((material) => <option key={material.id} value={material.id} disabled={items.some((entry, position) => position !== index && entry.video_asset_id === material.id)}>{material.alt_text ?? material.id}</option>)}
        </select>
      </label>
      <PublicAssetPreview allowAuthenticatedContent asset={materials.find((material) => material.id === item.video_asset_id) ?? null} />
      {items.length === 1 ? <span>100%</span> : <label>割合（%）
        <input aria-label={`${name}の動画${index + 1}の割合`} inputMode="decimal" value={item.weight_percent} onChange={(event) => change(items.map((entry, position) => position === index ? { ...entry, weight_percent: event.target.value } : entry))} />
      </label>}
      {items.length > 1 ? <button className="secondary-button" type="button" onClick={() => change(items.filter((_, position) => position !== index))}>動画{index + 1}を削除</button> : null}
    </div>)}
    <button className="secondary-button" disabled={items.length >= 10} type="button" onClick={() => change([...items, { video_asset_id: "", weight_percent: "0.01" }])}>動画を追加</button>
    <p role="status">割合合計: {total.label}{total.valid ? "" : "（動画の重複を避け、合計100%にしてください）"}</p>
    {onSave ? <button className="primary-button" disabled={!total.valid} type="button" onClick={onSave}>{busy ? "保存中" : "動画を保存"}</button> : null}
    {onUnset ? <button className="secondary-button" type="button" onClick={onUnset}>未設定に戻す</button> : null}
  </fieldset>;
}
