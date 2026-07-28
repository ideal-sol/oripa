"use client";

import { useRouter } from "next/navigation";
import {
  createContext,
  type ReactNode,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
} from "react";

import {
  AdminApiClient,
  AdminApiError,
} from "@/lib/admin-api/client";
import type {
  AdminIdentity,
  AdminLoginRequest,
  AdminPreauth,
  TotpEnrollment,
} from "@/lib/admin-api/generated";
import {
  createWebauthnCredential,
  getWebauthnAssertion,
} from "@/lib/admin-api/webauthn";

export type AuthPhase =
  | "loading"
  | "anonymous"
  | "mfa"
  | "enrollment"
  | "authenticated"
  | "expired";

interface AuthErrorState {
  message: string;
  requestId: string | null;
  retryAfter: number | null;
}

interface AdminAuthContextValue {
  admin: AdminIdentity | null;
  error: AuthErrorState | null;
  loading: boolean;
  phase: AuthPhase;
  preauth: AdminPreauth | null;
  totpEnrollment: TotpEnrollment | null;
  clearError: () => void;
  confirmTotpEnrollment: (code: string) => Promise<void>;
  enrollWebauthn: (label: string) => Promise<void>;
  freshTotp: (code: string) => Promise<void>;
  freshWebauthn: () => Promise<void>;
  login: (request: AdminLoginRequest) => Promise<void>;
  logout: () => Promise<void>;
  regenerateRecoveryCodes: () => Promise<string[]>;
  startTotpEnrollment: () => Promise<void>;
  verifyRecoveryCode: (code: string) => Promise<void>;
  verifyTotp: (code: string) => Promise<void>;
  verifyWebauthn: () => Promise<void>;
}

const AdminAuthContext = createContext<AdminAuthContextValue | null>(null);

export function AdminAuthProvider({ children }: { children: ReactNode }) {
  const router = useRouter();
  const client = useMemo(() => new AdminApiClient(), []);
  const [phase, setPhase] = useState<AuthPhase>("loading");
  const [admin, setAdmin] = useState<AdminIdentity | null>(null);
  const [preauth, setPreauth] = useState<AdminPreauth | null>(null);
  const [enrollmentToken, setEnrollmentToken] = useState<string | null>(null);
  const [totpEnrollment, setTotpEnrollment] = useState<TotpEnrollment | null>(null);
  const [error, setError] = useState<AuthErrorState | null>(null);
  const [loading, setLoading] = useState(false);

  const captureError = useCallback((cause: unknown) => {
    if (cause instanceof AdminApiError) {
      setError({
        message: cause.message,
        requestId: cause.requestId,
        retryAfter: cause.retryAfter,
      });
      if (cause.isSessionExpired) {
        setAdmin(null);
        setPhase("expired");
      }
      return;
    }
    setError({
      message: "認証処理を完了できませんでした。",
      requestId: null,
      retryAfter: null,
    });
  }, []);

  const withRequest = useCallback(
    async <T,>(operation: () => Promise<T>): Promise<T> => {
      setLoading(true);
      setError(null);
      try {
        return await operation();
      } catch (cause) {
        captureError(cause);
        throw cause;
      } finally {
        setLoading(false);
      }
    },
    [captureError],
  );

  useEffect(() => {
    const controller = new AbortController();
    client
      .getSession(controller.signal)
      .then((session) => {
        if (session.authenticated && session.admin) {
          setAdmin(session.admin);
          setPhase("authenticated");
        } else {
          setPhase("anonymous");
        }
      })
      .catch((cause) => {
        if (!controller.signal.aborted) {
          captureError(cause);
          setPhase("anonymous");
        }
      });
    return () => controller.abort();
  }, [captureError, client]);

  const completeMfa = useCallback(
    async (request: Parameters<AdminApiClient["verifyMfa"]>[1]) => {
      if (!preauth) throw new Error("Missing authentication transaction.");
      const session = await withRequest(() =>
        client.verifyMfa(preauth.transaction_token, request),
      );
      setAdmin(session.admin ?? null);
      setPreauth(null);
      if (session.requires_mfa_enrollment && session.enrollment_transaction_token) {
        setEnrollmentToken(session.enrollment_transaction_token);
        setPhase("enrollment");
        router.replace("/auth/enroll");
      } else {
        setEnrollmentToken(null);
        setPhase("authenticated");
        router.replace("/");
      }
    },
    [client, preauth, router, withRequest],
  );

  const value = useMemo<AdminAuthContextValue>(
    () => ({
      admin,
      error,
      loading,
      phase,
      preauth,
      totpEnrollment,
      clearError: () => setError(null),
      login: async (request) => {
        const result = await withRequest(() => client.login(request));
        setPreauth(result);
        setPhase("mfa");
        router.replace("/auth/mfa");
      },
      verifyTotp: (code) => completeMfa({ method: "totp", code }),
      verifyRecoveryCode: (code) =>
        completeMfa({ method: "recovery_code", code }),
      verifyWebauthn: async () => {
        if (!preauth?.webauthn) {
          throw new Error("WebAuthn is not available for this transaction.");
        }
        const credential = await withRequest(() =>
          getWebauthnAssertion(preauth.webauthn!.options),
        );
        await completeMfa({
          method: "webauthn",
          challenge_token: preauth.webauthn.challenge_token,
          credential,
        });
      },
      startTotpEnrollment: async () => {
        if (!enrollmentToken) throw new Error("Missing enrollment transaction.");
        const result = await withRequest(() => client.beginTotp(enrollmentToken));
        setTotpEnrollment(result);
      },
      confirmTotpEnrollment: async (code) => {
        if (!enrollmentToken || !totpEnrollment) {
          throw new Error("Missing TOTP enrollment transaction.");
        }
        await withRequest(() =>
          client.confirmTotp(enrollmentToken, {
            code,
            enrollment_token: totpEnrollment.enrollment_token,
          }),
        );
        setTotpEnrollment(null);
      },
      enrollWebauthn: async (label) => {
        if (!enrollmentToken) throw new Error("Missing enrollment transaction.");
        const options = await withRequest(() =>
          client.createWebauthnOptions(enrollmentToken, { label }),
        );
        const credential = await withRequest(() =>
          createWebauthnCredential(options.options),
        );
        await withRequest(() =>
          client.registerWebauthn(enrollmentToken, {
            challenge_token: options.challenge_token,
            credential,
          }),
        );
      },
      freshTotp: async (code) => {
        const result = await withRequest(() =>
          client.reauthenticate({ method: "totp", code }),
        );
        setAdmin(result.admin);
      },
      freshWebauthn: async () => {
        const options = await withRequest(() =>
          client.createReauthenticationWebauthnOptions(),
        );
        const credential = await withRequest(() =>
          getWebauthnAssertion(options.options),
        );
        const result = await withRequest(() =>
          client.reauthenticate({
            method: "webauthn",
            challenge_token: options.challenge_token,
            credential,
          }),
        );
        setAdmin(result.admin);
      },
      regenerateRecoveryCodes: async () => {
        const result = await withRequest(() => client.regenerateRecoveryCodes());
        return result.recovery_codes;
      },
      logout: async () => {
        try {
          await withRequest(() => client.logout());
        } finally {
          setAdmin(null);
          setPreauth(null);
          setEnrollmentToken(null);
          setTotpEnrollment(null);
          setPhase("anonymous");
          router.replace("/login");
        }
      },
    }),
    [
      admin,
      client,
      completeMfa,
      error,
      loading,
      phase,
      preauth,
      router,
      totpEnrollment,
      withRequest,
      enrollmentToken,
    ],
  );

  return (
    <AdminAuthContext.Provider value={value}>
      {children}
    </AdminAuthContext.Provider>
  );
}

export function useAdminAuth(): AdminAuthContextValue {
  const context = useContext(AdminAuthContext);
  if (!context) throw new Error("AdminAuthProvider is required.");
  return context;
}
