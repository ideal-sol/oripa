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
