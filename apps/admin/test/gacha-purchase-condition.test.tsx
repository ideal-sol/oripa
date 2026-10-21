import { fireEvent, render, screen } from "@testing-library/react";
import { useState } from "react";
import { describe, expect, it } from "vitest";
import { PurchaseCondition, type PurchaseFields } from "@/components/catalog/purchase-condition";

describe("Gacha purchase conditions", () => {
  it("requires bounded integers, clears unspecified values and summarizes all conditions", () => {
    function Form() {
      const [value, setValue] = useState<PurchaseFields>({});
      return <PurchaseCondition value={value} audience="line_users" rank={{ member_rank_mode: "at_least", member_rank_level: 4 }} onChange={setValue} />;
    }
    render(<Form />);
    fireEvent.change(screen.getByLabelText("購入回数"), { target: { value: "eq" } });
    expect(screen.getByLabelText("購入回数の値")).toBeRequired();
    fireEvent.change(screen.getByLabelText("購入回数の値"), { target: { value: "0" } });
    expect(screen.getByLabelText("購入回数の値")).toBeValid();
    fireEvent.change(screen.getByLabelText("購入回数"), { target: { value: "gte" } });
    expect(screen.getByLabelText("購入回数の値")).toBeInvalid();
    fireEvent.change(screen.getByLabelText("購入回数の値"), { target: { value: "3" } });
    fireEvent.change(screen.getByLabelText("購入額"), { target: { value: "lt" } });
    fireEvent.change(screen.getByLabelText("購入額の値"), { target: { value: "50000" } });
    expect(screen.getByLabelText("対象条件の要約")).toHaveTextContent("LINEユーザー／ゴールド以上／購入回数3回以上／購入額50,000円未満");
    fireEvent.change(screen.getByLabelText("購入回数"), { target: { value: "none" } });
    fireEvent.change(screen.getByLabelText("購入回数"), { target: { value: "eq" } });
    expect(screen.getByLabelText("購入回数の値")).toHaveValue(null);
  });

  it("disables published inputs and shows unrestricted defaults", () => {
    render(<PurchaseCondition value={{}} audience="all_users" rank={{}} disabled onChange={() => { throw new Error("immutable"); }} />);
    expect(screen.getByLabelText("購入回数")).toBeDisabled();
    expect(screen.getByLabelText("購入額")).toBeDisabled();
    expect(screen.getByLabelText("対象条件の要約")).toHaveTextContent("条件なし（すべてのユーザー）");
  });
});
