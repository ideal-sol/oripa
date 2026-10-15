import { useState } from "react";
import { fireEvent, render, screen } from "@testing-library/react";
import { describe, expect, it, vi } from "vitest";
import { RankVideoEditor, videoWeightTotal } from "@/components/catalog/rank-video-editor";
import type { AdminRankEffect, AdminRankVideoInput } from "@/lib/admin-api/generated";

const materials = Array.from({ length: 11 }, (_, index) => ({
  id: `video-${index}`, media_type: "video", mime_type: "video/mp4", alt_text: `Video ${index}`,
  is_public: true, is_default: false, public_path: null,
})) as AdminRankEffect[];

describe("RankVideoEditor", () => {
  it("validates exact integer totals, decimal precision, distinct IDs and row limits", () => {
    const item = { video_asset_id: "video-0", weight_percent: "100.00" };
    expect(videoWeightTotal([item]).valid).toBe(true);
    for (const items of [[], [item, item], [{ ...item, weight_percent: "100.001" }],
      [{ ...item, weight_percent: "0" }], [{ ...item, weight_percent: "99.99" }],
      Array.from({ length: 11 }, (_, index) => ({ video_asset_id: `video-${index}`, weight_percent: index ? "1" : "90" }))]) {
      expect(videoWeightTotal(items).valid).toBe(false);
    }
    expect(videoWeightTotal([{ ...item, weight_percent: "99.99" }, { video_asset_id: "video-1", weight_percent: "0.01" }])).toEqual({ valid: true, label: "100.00%" });
  });

  it("adds, validates, saves and removes weighted rows, and caps the list at ten", () => {
    const save = vi.fn();
    function Fixture() {
      const [items, setItems] = useState<AdminRankVideoInput[]>([{ video_asset_id: "video-0", weight_percent: "100.00" }]);
      return <RankVideoEditor name="A" materials={materials} items={items} onChange={setItems} onSave={() => save(items)} disabled={false} />;
    }
    render(<Fixture />);
    fireEvent.click(screen.getByRole("button", { name: "動画を追加" }));
    expect(screen.getByRole("button", { name: "動画を保存" })).toBeDisabled();
    fireEvent.change(screen.getByLabelText("Aの動画2"), { target: { value: "video-1" } });
    fireEvent.change(screen.getByLabelText("Aの動画1の割合"), { target: { value: "80.00" } });
    fireEvent.change(screen.getByLabelText("Aの動画2の割合"), { target: { value: "20.00" } });
    fireEvent.click(screen.getByRole("button", { name: "動画を保存" }));
    expect(save).toHaveBeenCalledWith([{ video_asset_id: "video-0", weight_percent: "80.00" }, { video_asset_id: "video-1", weight_percent: "20.00" }]);
    fireEvent.click(screen.getByRole("button", { name: "動画2を削除" }));
    fireEvent.click(screen.getByRole("button", { name: "動画を保存" }));
    expect(save).toHaveBeenLastCalledWith([{ video_asset_id: "video-0", weight_percent: "100.00" }]);
    for (let index = 1; index < 10; index++) fireEvent.click(screen.getByRole("button", { name: "動画を追加" }));
    expect(screen.getAllByRole("combobox")).toHaveLength(10);
    expect(screen.getByRole("button", { name: "動画を追加" })).toBeDisabled();
  });

  it("does not allow unprivileged changes", () => {
    render(<RankVideoEditor name="A" materials={materials} items={[{ video_asset_id: "video-0", weight_percent: "100.00" }]} onChange={vi.fn()} onSave={vi.fn()} disabled />);
    expect(screen.getByRole("combobox")).toBeDisabled();
    expect(screen.getByRole("button", { name: "動画を保存" })).toBeDisabled();
  });
});
