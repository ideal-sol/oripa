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
  AdminInvitationAcceptanceRequest,
  AdminLoginRequest,
  AdminLoginResult,
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
  mfaRequired: boolean;
  phase: AuthPhase;
  preauth: AdminLoginResult | null;
  totpEnrollment: TotpEnrollment | null;
  acceptInvitation: (request: AdminInvitationAcceptanceRequest) => Promise<void>;
  clearError: () => void;
  confirmTotpEnrollment: (code: string) => Promise<boolean>;
  enrollWebauthn: (label: string) => Promise<boolean>;
  expireSession: () => void;
  freshPassword: (password: string) => Promise<void>;
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
  const [mfaRequired, setMfaRequired] = useState(false);
  const [preauth, setPreauth] = useState<AdminLoginResult | null>(null);
  const [enrollmentToken, setEnrollmentToken] = useState<string | null>(null);
  const [totpEnrollment, setTotpEnrollment] = useState<TotpEnrollment | null>(null);
  const [error, setError] = useState<AuthErrorState | null>(null);
  const [loading, setLoading] = useState(false);

  const expireSession = useCallback(() => {
    setAdmin(null);
    setPhase("expired");
  }, []);

  const captureError = useCallback((cause: unknown) => {
    if (cause instanceof AdminApiError) {
      setError({
        message: cause.message,
        requestId: cause.requestId,
        retryAfter: cause.retryAfter,
      });
      if (cause.isSessionExpired) {
        expireSession();
      }
      return;
    }
    setError({
      message: "認証処理を完了できませんでした。",
      requestId: null,
      retryAfter: null,
    });
  }, [expireSession]);

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
        setMfaRequired(session.mfa_required ?? true);
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

  const applyAuthenticationResult = useCallback(
    (result: AdminLoginResult) => {
      setMfaRequired(result.mfa_required);
      if (result.status === "authenticated" && result.admin) {
        setAdmin(result.admin);
        setPreauth(null);
        setEnrollmentToken(null);
        setPhase("authenticated");
        router.replace("/");
        return;
      }
      if (result.status === "enrollment_required" && result.transaction_token) {
        setAdmin(null);
        setPreauth(null);
        setEnrollmentToken(result.transaction_token);
        setPhase("enrollment");
        router.replace("/auth/enroll");
        return;
      }
      if (result.status === "mfa_required" && result.transaction_token) {
        setAdmin(null);
        setEnrollmentToken(null);
        setPreauth(result);
        setPhase("mfa");
        router.replace("/auth/mfa");
        return;
      }
      throw new Error("Unexpected authentication response.");
    },
    [router],
  );

  const completeMfa = useCallback(
    async (request: Parameters<AdminApiClient["verifyMfa"]>[1]) => {
      const transactionToken = preauth?.transaction_token;
      if (!transactionToken) throw new Error("Missing authentication transaction.");
      const session = await withRequest(() =>
        client.verifyMfa(transactionToken, request),
      );
      setAdmin(session.admin ?? null);
      setMfaRequired(session.mfa_required ?? true);
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
      mfaRequired,
      phase,
      preauth,
      totpEnrollment,
      clearError: () => setError(null),
      login: async (request) => {
        const result = await withRequest(() => client.login(request));
        applyAuthenticationResult(result);
      },
      acceptInvitation: async (request) => {
        const result = await withRequest(() => client.acceptInvitation(request));
        applyAuthenticationResult(result);
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
        const result = await withRequest(() =>
          client.confirmTotp(enrollmentToken, {
            code,
            enrollment_token: totpEnrollment.enrollment_token,
          }),
        );
        setTotpEnrollment(null);
        if (result.authenticated && result.admin) {
          setAdmin(result.admin);
          setEnrollmentToken(null);
          setPhase("authenticated");
          router.replace("/");
          return true;
        }
        return false;
      },
      enrollWebauthn: async (label) => {
        if (!enrollmentToken) throw new Error("Missing enrollment transaction.");
        const options = await withRequest(() =>
          client.createWebauthnOptions(enrollmentToken, { label }),
        );
        const credential = await withRequest(() =>
          createWebauthnCredential(options.options),
        );
        const result = await withRequest(() =>
          client.registerWebauthn(enrollmentToken, {
            challenge_token: options.challenge_token,
            credential,
          }),
        );
        if (result.authenticated && result.admin) {
          setAdmin(result.admin);
          setEnrollmentToken(null);
          setPhase("authenticated");
          router.replace("/");
          return true;
        }
        return false;
      },
      expireSession,
      freshTotp: async (code) => {
        const result = await withRequest(() =>
          client.reauthenticate({ method: "totp", code }),
        );
        setAdmin(result.admin);
      },
      freshPassword: async (password) => {
        const result = await withRequest(() =>
          client.reauthenticate({ method: "password", password }),
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
      applyAuthenticationResult,
      client,
      completeMfa,
      error,
      loading,
      mfaRequired,
      phase,
      preauth,
      router,
      totpEnrollment,
      withRequest,
      enrollmentToken,
      expireSession,
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
