"use client";

import { FormEvent, useState } from "react";

type Notice = {
  tone: "success" | "error";
  message: string;
};

export default function ContactForm() {
  const [form, setForm] = useState({
    name: "",
    email: "",
    phone: "",
    body: "",
  });
  const [notice, setNotice] = useState<Notice | null>(null);
  const [loading, setLoading] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLoading(true);
    setNotice(null);

    try {
      const response = await fetch(`${getPublicApiBaseUrl()}/contact-requests`, {
        method: "POST",
        headers: {
          accept: "application/json",
          "content-type": "application/json",
        },
        body: JSON.stringify(form),
      });
      const data = await response.json().catch(() => null);

      if (!response.ok) {
        throw new Error(validationMessage(data) ?? "お問い合わせの送信に失敗しました。");
      }

      setForm({ name: "", email: "", phone: "", body: "" });
      setNotice({ tone: "success", message: "お問い合わせを送信しました。" });
    } catch (error) {
      setNotice({ tone: "error", message: error instanceof Error ? error.message : "お問い合わせの送信に失敗しました。" });
    } finally {
      setLoading(false);
    }
  }

  return (
    <form className="contact-form" onSubmit={handleSubmit} noValidate>
      {notice && <p className={`contact-notice ${notice.tone}`}>{notice.message}</p>}
      <label>
        <span>氏名</span>
        <input value={form.name} onChange={(event) => setForm({ ...form, name: event.target.value })} required />
      </label>
      <label>
        <span>メールアドレス</span>
        <input type="email" value={form.email} onChange={(event) => setForm({ ...form, email: event.target.value })} required />
      </label>
      <label>
        <span>電話番号</span>
        <input value={form.phone} onChange={(event) => setForm({ ...form, phone: event.target.value })} inputMode="tel" required />
      </label>
      <label>
        <span>お問い合わせ内容</span>
        <textarea value={form.body} onChange={(event) => setForm({ ...form, body: event.target.value })} required />
      </label>
      <button className="public-primary-link dark" type="submit" disabled={loading}>{loading ? "送信中" : "送信する"}</button>
    </form>
  );
}

function validationMessage(data: unknown): string | null {
  if (!data || typeof data !== "object") {
    return null;
  }

  const errors = "errors" in data ? (data as { errors?: Record<string, string[]> }).errors : undefined;

  if (!errors) {
    return "message" in data && typeof (data as { message?: unknown }).message === "string"
      ? (data as { message: string }).message
      : null;
  }

  return Object.values(errors).flat().join(" / ");
}

function getPublicApiBaseUrl(): string {
  return process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api";
}
