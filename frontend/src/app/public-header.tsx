"use client";

import Link from "next/link";
import Image from "next/image";
import { useEffect, useState } from "react";

type Wallet = {
  total_balance: number;
};

type UserSession = {
  token_type: "Bearer";
  access_token: string;
  user: {
    name: string;
    email: string;
    wallet?: Wallet | null;
  };
};

const sessionStorageKey = "oripa_user_session";
const sessionChangedEvent = "oripa-session-changed";

export default function PublicHeader() {
  const [session, setSession] = useState<UserSession | null>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    let active = true;

    async function restoreSession(): Promise<void> {
      const rawSession = window.localStorage.getItem(sessionStorageKey);

      if (rawSession) {
        try {
          const restoredSession = JSON.parse(rawSession) as UserSession;
          await Promise.resolve();

          if (!active) {
            return;
          }

          setSession(restoredSession);
        } catch {
          window.localStorage.removeItem(sessionStorageKey);
        }
      } else if (active) {
        setSession(null);
      }

      if (active) {
        setReady(true);
      }
    }

    function handleSessionChange(): void {
      void restoreSession();
    }

    window.addEventListener("storage", handleSessionChange);
    window.addEventListener(sessionChangedEvent, handleSessionChange);

    void restoreSession();

    return () => {
      active = false;
      window.removeEventListener("storage", handleSessionChange);
      window.removeEventListener(sessionChangedEvent, handleSessionChange);
    };
  }, []);

  async function handleLogout(): Promise<void> {
    const currentSession = session;
    window.localStorage.removeItem(sessionStorageKey);
    window.dispatchEvent(new Event(sessionChangedEvent));
    setSession(null);

    if (!currentSession) {
      return;
    }

    await fetch(`${getPublicApiBaseUrl()}/logout`, {
      method: "POST",
      headers: {
        accept: "application/json",
        authorization: `${currentSession.token_type} ${currentSession.access_token}`,
      },
    }).catch(() => undefined);
  }

  return (
    <header className="public-header">
      <Link className="public-logo" href="/">
        <Image className="public-logo-image" src="/logo.png" alt="Luxe Pack" width={64} height={58} priority unoptimized />
      </Link>
      <nav aria-label="公開メニュー">
        <Link href="/#gachas">ガチャ</Link>
        <Link href="/#information">お知らせ</Link>
        {ready && session ? (
          <>
            <Link href="/points/purchase">ポイント購入</Link>
            <Link href="/mypage/prizes">景品BOX</Link>
            <Link href="/mypage/points">ポイント履歴</Link>
            <Link href="/mypage/draws">抽選履歴</Link>
            <Link href="/mypage/shipping">配送履歴</Link>
            <Link href="/mypage/profile">プロフィール</Link>
            <span className="public-user-chip">{session.user.name}</span>
            <button className="public-nav-button" type="button" onClick={() => void handleLogout()}>
              ログアウト
            </button>
          </>
        ) : (
          <>
            <Link href="/login">ログイン</Link>
            <Link href="/login?mode=register">会員登録</Link>
          </>
        )}
      </nav>
    </header>
  );
}

function getPublicApiBaseUrl(): string {
  return process.env.NEXT_PUBLIC_API_BASE_URL ?? "/api";
}
