import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface IdentityMutationOptions {
  csrf_token?: string;
}

export interface StorefrontIdentityClient {
  initializeCsrf(): Promise<StorefrontResponse<Schemas["UserSession"]>>;
  register(
    input: Schemas["UserRegistrationRequest"],
    options?: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PendingRegistration"]>>;
  login(
    input: Schemas["PasswordLoginRequest"],
    options?: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["UserSession"]>>;
  logout(
    options?: IdentityMutationOptions,
  ): Promise<StorefrontResponse<undefined>>;
  getCurrentSession(): Promise<StorefrontResponse<Schemas["UserSession"]>>;
  resendEmailVerification(
    input: Schemas["VerificationResendRequest"],
    options?: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["Accepted"]>>;
  completeEmailVerification(input: {
    user_id: string;
    hash: string;
  }): Promise<StorefrontResponse<Schemas["UserSession"]>>;
  startGoogleLogin(
    input: Schemas["ExternalIdentityStartRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ExternalIdentityStart"]>>;
  completeGoogleOidc(input: {
    code: string;
    state: string;
    iss?: "https://accounts.google.com";
  }): Promise<StorefrontResponse<Schemas["ExternalIdentitySession"]>>;
  startLineLogin(
    input: Schemas["ExternalIdentityStartRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ExternalIdentityStart"]>>;
  completeLineLogin(input: {
    code: string;
    state: string;
  }): Promise<StorefrontResponse<Schemas["ExternalIdentitySession"]>>;
  listExternalIdentities(): Promise<
    StorefrontResponse<Schemas["ExternalIdentityCollection"]>
  >;
  getLineFriendState(): Promise<
    StorefrontResponse<Schemas["LineFriendStatePresentation"]>
  >;
  startGoogleIdentityLink(
    input: Schemas["ExternalIdentityStartRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ExternalIdentityStart"]>>;
  startGoogleReauthentication(
    input: Schemas["ExternalIdentityStartRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ExternalIdentityStart"]>>;
  startLineIdentityLink(
    input: Schemas["ExternalIdentityStartRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ExternalIdentityStart"]>>;
  startLineReauthentication(
    input: Schemas["ExternalIdentityStartRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ExternalIdentityStart"]>>;
  reauthenticateUserPassword(
    input: Schemas["UserPasswordReauthenticationRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["UserReauthentication"]>>;
  unlinkGoogleIdentity(
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<undefined>>;
  unlinkLineIdentity(
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<undefined>>;
  requestPasswordReset(
    input: Schemas["PasswordResetRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PasswordResetAccepted"]>>;
  confirmPasswordReset(
    input: Schemas["PasswordResetConfirmRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PasswordResetCompleted"]>>;
  createEmailChangeRequest(
    input: Schemas["EmailChangeRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["EmailChangePending"]>>;
  completeEmailChange(
    input: Schemas["EmailChangeCompleteRequest"] & { request_id: string },
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["EmailChangeCompleted"]>>;
  changeUserPassword(
    input: Schemas["UserPasswordChangeRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["UserPasswordChanged"]>>;
  getSmsVerificationStatus(): Promise<
    StorefrontResponse<Schemas["SmsVerificationStatus"]>
  >;
  sendSmsVerification(
    input: Schemas["SmsVerificationSendRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["SmsVerificationAccepted"]>>;
  resendSmsVerification(
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["SmsVerificationAccepted"]>>;
  verifySmsCode(
    input: Schemas["SmsVerificationConfirmRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["SmsVerificationStatus"]>>;
}

function csrf(value: string | undefined): Record<string, string> | undefined {
  if (value === undefined) {
    return undefined;
  }
  if (!/^[0-9a-f]{64}$/.test(value)) {
    throw new TypeError("csrf_token is invalid");
  }
  return { "X-XSRF-TOKEN": value };
}

export function createStorefrontIdentityClient(
  transport: StorefrontTransport,
): StorefrontIdentityClient {
  const mutation = <T>(
    path: string,
    body: unknown,
    options: IdentityMutationOptions = {},
  ): Promise<StorefrontResponse<T>> =>
    transport.request<T>({
      path,
      method: "POST",
      body,
      headers: csrf(options.csrf_token),
      csrf: "required",
    });

  return {
    initializeCsrf: () =>
      transport.request({ path: "/auth/session", retry: false }),
    register: (input, options = {}) =>
      mutation<Schemas["PendingRegistration"]>(
        "/auth/register",
        input,
        options,
      ),
    login: (input, options = {}) =>
      mutation<Schemas["UserSession"]>("/auth/login", input, options),
    logout: (options = {}) =>
      transport.request<undefined>({
        path: "/auth/logout",
        method: "POST",
        headers: csrf(options.csrf_token),
        csrf: "required",
        retry: false,
      }),
    getCurrentSession: () =>
      transport.request({ path: "/auth/session", retry: false }),
    resendEmailVerification: (input, options = {}) =>
      mutation<Schemas["Accepted"]>(
        "/auth/email/verification-notification",
        input,
        options,
      ),
    completeEmailVerification: (input) => {
      if (
        !/^[0-9a-f-]{36}$/.test(input.user_id)
        || !/^[0-9a-f]{64}$/.test(input.hash)
      ) {
        throw new TypeError("email verification input is invalid");
      }
      return transport.request({
        path: `/auth/email/verify/${encodeURIComponent(input.user_id)}/${input.hash}`,
        retry: false,
      });
    },
    startGoogleLogin: (input, options) =>
      mutation<Schemas["ExternalIdentityStart"]>(
        "/auth/external/google/start",
        input,
        options,
      ),
    completeGoogleOidc: (input) => {
      if (!/^[0-9a-f]{64}$/.test(input.state) || input.code.length === 0) {
        throw new TypeError("OIDC callback input is invalid");
      }
      const query = new URLSearchParams({
        code: input.code,
        state: input.state,
      });
      if (input.iss !== undefined) {
        query.set("iss", input.iss);
      }
      return transport.request({
        path: `/auth/external/google/callback?${query.toString()}`,
      });
    },
    startLineLogin: (input, options) =>
      mutation<Schemas["ExternalIdentityStart"]>(
        "/auth/external/line/start",
        input,
        options,
      ),
    completeLineLogin: (input) => {
      if (!/^[0-9a-f]{64}$/.test(input.state) || input.code.length === 0) {
        throw new TypeError("LINE callback input is invalid");
      }
      const query = new URLSearchParams({
        code: input.code,
        state: input.state,
      });
      return transport.request({
        path: `/auth/external/line/callback?${query.toString()}`,
      });
    },
    listExternalIdentities: () =>
      transport.request({ path: "/me/external-identities" }),
    getLineFriendState: () =>
      transport.request({ path: "/me/line-friend-state" }),
    startGoogleIdentityLink: (input, options) =>
      mutation<Schemas["ExternalIdentityStart"]>(
        "/me/external-identities/google/link",
        input,
        options,
      ),
    startGoogleReauthentication: (input, options) =>
      mutation<Schemas["ExternalIdentityStart"]>(
        "/me/external-identities/google/reauthenticate",
        input,
        options,
      ),
    startLineIdentityLink: (input, options) =>
      mutation<Schemas["ExternalIdentityStart"]>(
        "/me/external-identities/line/link",
        input,
        options,
      ),
    startLineReauthentication: (input, options) =>
      mutation<Schemas["ExternalIdentityStart"]>(
        "/me/external-identities/line/reauthenticate",
        input,
        options,
      ),
    reauthenticateUserPassword: (input, options) =>
      mutation<Schemas["UserReauthentication"]>(
        "/me/password/reauthenticate",
        input,
        options,
      ),
    unlinkGoogleIdentity: (options) =>
      transport.request<undefined>({
        path: "/me/external-identities/google",
        method: "DELETE",
        headers: csrf(options.csrf_token),
        csrf: "required",
      }),
    unlinkLineIdentity: (options) =>
      transport.request<undefined>({
        path: "/me/external-identities/line",
        method: "DELETE",
        headers: csrf(options.csrf_token),
        csrf: "required",
      }),
    requestPasswordReset: (input, options) =>
      mutation<Schemas["PasswordResetAccepted"]>(
        "/auth/password/forgot",
        input,
        options,
      ),
    confirmPasswordReset: (input, options) =>
      mutation<Schemas["PasswordResetCompleted"]>(
        "/auth/password/reset",
        input,
        options,
      ),
    createEmailChangeRequest: (input, options) =>
      mutation<Schemas["EmailChangePending"]>(
        "/me/email-change-requests",
        input,
        options,
      ),
    completeEmailChange: (input, options) => {
      if (
        !/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(
          input.request_id,
        )
        || !/^[0-9a-f]{64}$/.test(input.token)
      ) {
        throw new TypeError("email change completion input is invalid");
      }
      const { request_id, ...body } = input;
      return mutation<Schemas["EmailChangeCompleted"]>(
        `/me/email-change-requests/${encodeURIComponent(request_id)}/complete`,
        body,
        options,
      );
    },
    changeUserPassword: (input, options) =>
      transport.request<Schemas["UserPasswordChanged"]>({
        path: "/me/password",
        method: "PUT",
        body: input,
        headers: csrf(options.csrf_token),
        csrf: "required",
      }),
    getSmsVerificationStatus: () =>
      transport.request({ path: "/me/sms-verification" }),
    sendSmsVerification: (input, options) =>
      mutation<Schemas["SmsVerificationAccepted"]>(
        "/me/sms-verification",
        input,
        options,
      ),
    resendSmsVerification: (options) =>
      mutation<Schemas["SmsVerificationAccepted"]>(
        "/me/sms-verification/resend",
        {},
        options,
      ),
    verifySmsCode: (input, options) =>
      mutation<Schemas["SmsVerificationStatus"]>(
        "/me/sms-verification/verify",
        input,
        options,
      ),
  };
}
