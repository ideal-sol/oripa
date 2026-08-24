"use client";

import Image from "@tiptap/extension-image";
import TextAlign from "@tiptap/extension-text-align";
import { TableKit } from "@tiptap/extension-table/kit";
import { EditorContent, useEditor } from "@tiptap/react";
import StarterKit from "@tiptap/starter-kit";
import {
  forwardRef,
  useEffect,
  useImperativeHandle,
} from "react";

export interface RichTextEditorHandle {
  insertText: (value: string) => void;
}

export const RichTextEditor = forwardRef<RichTextEditorHandle, {
  disabled?: boolean;
  label: string;
  onChange: (value: string) => void;
  value: string;
}>(function RichTextEditor({ disabled = false, label, onChange, value }, ref) {
  const editor = useEditor({
    immediatelyRender: false,
    content: value,
    editable: !disabled,
    extensions: [
      StarterKit.configure({
        heading: { levels: [1, 2, 3] },
        link: { autolink: false, openOnClick: false },
      }),
      TextAlign.configure({
        alignments: ["left", "center", "right"],
        types: ["heading", "paragraph"],
      }),
      Image.configure({ allowBase64: false }),
      TableKit.configure({ table: { resizable: false } }),
    ],
    editorProps: {
      attributes: {
        "aria-label": label,
        class: "rich-text-editor-content",
      },
      handleDrop: (_view, event) =>
        Array.from(event.dataTransfer?.files ?? []).some((file) => file.type.startsWith("image/")),
      handlePaste: (_view, event) => {
        const files = Array.from(event.clipboardData?.files ?? []);
        const html = event.clipboardData?.getData("text/html") ?? "";
        return files.some((file) => file.type.startsWith("image/")) || /<img\b/i.test(html);
      },
    },
    onUpdate: ({ editor: current }) => {
      onChange(current.getHTML());
    },
  });

  useEffect(() => {
    editor?.setEditable(!disabled);
  }, [disabled, editor]);

  useEffect(() => {
    if (editor && editor.getHTML() !== value) {
      editor.commands.setContent(value, { emitUpdate: false });
    }
  }, [editor, value]);

  useImperativeHandle(ref, () => ({
    insertText(token: string) {
      editor?.chain().focus().insertContent(token).run();
    },
  }), [editor]);

  if (!editor) return <div aria-label={`${label}を準備中`} className="rich-text-editor is-loading" />;

  function setLink() {
    if (!editor) return;
    const current = editor.getAttributes("link").href as string | undefined;
    const url = window.prompt("リンクURLを入力してください", current ?? "https://");
    if (url === null) return;
    if (url.trim() === "") {
      editor.chain().focus().extendMarkRange("link").unsetLink().run();
      return;
    }
    editor.chain().focus().extendMarkRange("link").setLink({ href: url.trim() }).run();
  }

  function insertImage() {
    if (!editor) return;
    const input = window.prompt("HTTPS画像URLを入力してください", "https://");
    if (input === null) return;
    const url = httpsImageUrl(input);
    if (!url) {
      window.alert("画像URLは絶対HTTPS URLを入力してください。");
      return;
    }
    editor.chain().focus().setImage({ src: url }).run();
  }

  const button = (labelText: string, action: () => void, active = false, unavailable = false) => (
    <button
      aria-label={labelText}
      aria-pressed={active}
      className={active ? "is-active" : undefined}
      disabled={disabled || unavailable}
      onClick={action}
      type="button"
    >
      {labelText}
    </button>
  );

  return (
    <div className={`rich-text-editor${disabled ? " is-disabled" : ""}`}>
      <div aria-label={`${label}の書式`} className="rich-text-toolbar" role="toolbar">
        {button("段落", () => editor.chain().focus().setParagraph().run(), editor.isActive("paragraph"))}
        {button("H2", () => editor.chain().focus().toggleHeading({ level: 2 }).run(), editor.isActive("heading", { level: 2 }))}
        {button("H3", () => editor.chain().focus().toggleHeading({ level: 3 }).run(), editor.isActive("heading", { level: 3 }))}
        {button("太字", () => editor.chain().focus().toggleBold().run(), editor.isActive("bold"))}
        {button("斜体", () => editor.chain().focus().toggleItalic().run(), editor.isActive("italic"))}
        {button("下線", () => editor.chain().focus().toggleUnderline().run(), editor.isActive("underline"))}
        {button("打消線", () => editor.chain().focus().toggleStrike().run(), editor.isActive("strike"))}
        {button("箇条書き", () => editor.chain().focus().toggleBulletList().run(), editor.isActive("bulletList"))}
        {button("番号付き", () => editor.chain().focus().toggleOrderedList().run(), editor.isActive("orderedList"))}
        {button("左揃え", () => editor.chain().focus().setTextAlign("left").run(), editor.isActive({ textAlign: "left" }))}
        {button("中央揃え", () => editor.chain().focus().setTextAlign("center").run(), editor.isActive({ textAlign: "center" }))}
        {button("右揃え", () => editor.chain().focus().setTextAlign("right").run(), editor.isActive({ textAlign: "right" }))}
        {button("リンク", setLink, editor.isActive("link"))}
        {button("画像URL", insertImage)}
        {button("区切り線", () => editor.chain().focus().setHorizontalRule().run())}
        {button("元に戻す", () => editor.chain().focus().undo().run(), false, !editor.can().undo())}
        {button("やり直す", () => editor.chain().focus().redo().run(), false, !editor.can().redo())}
      </div>
      <EditorContent editor={editor} />
    </div>
  );
});

function httpsImageUrl(value: string): string | null {
  try {
    const parsed = new URL(value.trim());
    if (parsed.protocol !== "https:" || !parsed.hostname || parsed.username || parsed.password) return null;
    return parsed.toString();
  } catch {
    return null;
  }
}
