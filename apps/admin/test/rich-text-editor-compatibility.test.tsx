import { fireEvent, render, screen, waitFor, within } from "@testing-library/react";
import { afterAll, beforeAll, describe, expect, it, vi } from "vitest";

import { RichTextEditor } from "@/components/rich-text/rich-text-editor";

describe("Rich text dependency compatibility", () => {
  beforeAll(() => {
    Object.defineProperty(Range.prototype, "getClientRects", { configurable: true, value: () => [] });
    Object.defineProperty(Range.prototype, "getBoundingClientRect", { configurable: true, value: () => new DOMRect() });
  });

  afterAll(() => {
    Reflect.deleteProperty(Range.prototype, "getClientRects");
    Reflect.deleteProperty(Range.prototype, "getBoundingClientRect");
  });

  it("loads the aligned starter kit, image, table, and text alignment extensions", async () => {
    render(<RichTextEditor label="本文" onChange={vi.fn()} value={'<h2 style="text-align: center"><strong>見出し</strong></h2><table><tbody><tr><td><p>セル</p></td></tr></tbody></table><img src="https://example.com/image.png" alt="画像">'} />);
    const editor = await screen.findByLabelText("本文");
    expect(within(editor).getByRole("heading", { level: 2 })).toHaveStyle({ textAlign: "center" });
    expect(editor.querySelector("h2 strong")).toHaveTextContent("見出し");
    expect(within(editor).getByRole("cell")).toHaveTextContent("セル");
    expect(within(editor).getByRole("img", { name: "画像" })).toHaveAttribute("src", "https://example.com/image.png");
  });

  it("inserts an HTTPS image and emits persisted HTML using the real image extension", async () => {
    const onChange = vi.fn();
    vi.spyOn(window, "prompt").mockReturnValue("https://example.com/image.png");
    render(<RichTextEditor label="本文" onChange={onChange} value="<p>本文</p>" />);
    fireEvent.click(await screen.findByRole("button", { name: "画像URL" }));
    await waitFor(() => expect(onChange).toHaveBeenCalledWith(expect.stringContaining('src="https://example.com/image.png"')));
    expect(within(screen.getByLabelText("本文")).getByRole("img")).toHaveAttribute("src", "https://example.com/image.png");
  });

  it.each(["http://example.com/image.png", "data:image/png;base64,invalid", "https://user:password@example.com/image.png"])("keeps rejecting disallowed image URLs: %s", async (url) => {
    const onChange = vi.fn();
    vi.spyOn(window, "prompt").mockReturnValue(url);
    const alert = vi.spyOn(window, "alert").mockImplementation(() => undefined);
    render(<RichTextEditor label="本文" onChange={onChange} value="<p>本文</p>" />);
    const imageButton = await screen.findByRole("button", { name: "画像URL" });
    onChange.mockClear();
    fireEvent.click(imageButton);
    expect(alert).toHaveBeenCalledOnce();
    expect(onChange).not.toHaveBeenCalled();
    expect(within(screen.getByLabelText("本文")).queryByRole("img")).not.toBeInTheDocument();
  });
});
