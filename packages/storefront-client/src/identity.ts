import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface IdentityMutationOptions {
  csrf_token: string;
}

export interface StorefrontIdentityClient {
  startGoogleLogin(
    input: Schemas["ExternalIdentityStartRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ExternalIdentityStart"]>>;
  completeGoogleOidc(input: {
    code: string;
    state: string;
    iss?: "https://accounts.google.com";
  }): Promise<StorefrontResponse<Schemas["ExternalIdentitySession"]>>;
  listExternalIdentities(): Promise<
    StorefrontResponse<Schemas["ExternalIdentityCollection"]>
  >;
  startGoogleIdentityLink(
    input: Schemas["ExternalIdentityStartRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["ExternalIdentityStart"]>>;
  startGoogleReauthentication(
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
  requestPasswordReset(
    input: Schemas["PasswordResetRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["PasswordResetAccepted"]>>;
  confirmPasswordReset(
    input: Schemas["PasswordResetConfirmRequest"],
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<Schemas["UserSession"]>>;
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

function csrf(value: string): Record<string, string> {
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
    options: IdentityMutationOptions,
  ): Promise<StorefrontResponse<T>> =>
    transport.request<T>({
      path,
      method: "POST",
      body,
      headers: csrf(options.csrf_token),
      csrf: "required",
    });

  return {
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
    listExternalIdentities: () =>
      transport.request({ path: "/me/external-identities" }),
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
    requestPasswordReset: (input, options) =>
      mutation<Schemas["PasswordResetAccepted"]>(
        "/auth/password/forgot",
        input,
        options,
      ),
    confirmPasswordReset: (input, options) =>
      mutation<Schemas["UserSession"]>("/auth/password/reset", input, options),
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
