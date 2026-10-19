import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { describe, expect, it } from "vitest";
import { MemberRankCondition, memberRankConditionLabel } from "@/components/catalog/member-rank-condition";

describe("member rank Gacha conditions", () => {
  it("requires a rank for a condition and clears it when unspecified", () => {
    function Form() {
      const [mode, setMode] = useState<"none" | "at_least" | "only" | "at_most">("none");
      const [level, setLevel] = useState<number | null>(null);
      return <><MemberRankCondition mode={mode} level={level} onChange={(next, rank) => { setMode(next); setLevel(rank); }} /><output>{memberRankConditionLabel({ member_rank_mode: mode, member_rank_level: level })}</output></>;
    }
    render(<Form />);
    fireEvent.change(screen.getByLabelText("会員ランク条件"), { target: { value: "at_least" } });
    expect(screen.getByLabelText("条件の会員ランク")).toBeRequired();
    expect(screen.getByLabelText("条件の会員ランク")).toBeInvalid();
    fireEvent.change(screen.getByLabelText("条件の会員ランク"), { target: { value: "4" } });
    expect(screen.getByRole("status")).toHaveTextContent("ゴールド以上");
    fireEvent.change(screen.getByLabelText("会員ランク条件"), { target: { value: "none" } });
    expect(screen.queryByLabelText("条件の会員ランク")).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText("会員ランク条件"), { target: { value: "only" } });
    expect(screen.getByLabelText("条件の会員ランク")).toHaveValue("");
  });

  it("keeps published conditions visible and immutable", () => {
    render(<MemberRankCondition disabled mode="only" level={3} onChange={() => { throw new Error("immutable"); }} />);
    expect(screen.getByLabelText("会員ランク条件")).toBeDisabled();
    expect(screen.getByLabelText("条件の会員ランク")).toBeDisabled();
    expect(screen.getByLabelText("条件の会員ランク")).toHaveValue("3");
    expect(memberRankConditionLabel({ member_rank_mode: "at_most", member_rank_level: 2 })).toBe("ブロンズ以下");
  });
});
