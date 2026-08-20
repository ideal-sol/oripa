import {
  ApiProblemError,
  StorefrontTransportError,
  isAuthProblemError,
  isDrawProblemError,
  isFulfillmentProblemError,
} from "./errors.js";
import {
  CLIENT_VERSION_HEADER,
  SITE_VERSION_HEADER,
  STOREFRONT_CLIENT_VERSION,
  USER_CSRF_INITIALIZATION_PATH,
  USER_XSRF_COOKIE,
} from "./constants.js";
import {
  createIdempotencyKey,
  createTransport,
} from "./transport.js";
import {
  createCsrfManagedStorefrontDrawClient,
  type BrowserStorefrontDrawClient,
} from "./draw.js";
import {
  createCsrfManagedStorefrontPrizeShippingClient,
  type BrowserStorefrontPrizeShippingClient,
} from "./prize-shipping.js";
import {
  createCsrfManagedStorefrontContentContactClient,
  type BrowserStorefrontContentContactClient,
} from "./content-contact.js";
import type {
  BrowserStorefrontClientConfig,
  CsrfInitializer,
  StorefrontTransport,
} from "./types.js";

export {
  USER_CSRF_INITIALIZATION_PATH,
  USER_SESSION_COOKIE,
  USER_XSRF_COOKIE,
  XSRF_TOKEN_HEADER,
} from "./constants.js";
export {
  ApiProblemError,
  StorefrontTransportError,
  createIdempotencyKey,
  isAuthProblemError,
  isDrawProblemError,
  isFulfillmentProblemError,
};
export type {
  BrowserStorefrontClientConfig,
  StorefrontRequestOptions,
  StorefrontResponse,
  StorefrontResponseMetadata,
} from "./types.js";
export type {
  BrowserCreateDrawOptions,
  BrowserStorefrontDrawClient,
} from "./draw.js";
export type { DrawProblemCode } from "./errors.js";
export type { FulfillmentProblemCode } from "./errors.js";
export {
  FULFILLMENT_MUTATION_RETRY_SEMANTICS,
} from "./prize-shipping.js";
export type {
  BrowserPrizeShippingMutationOptions,
  BrowserPrizeShippingNonRetryableMutationOptions,
  BrowserStorefrontPrizeShippingClient,
  FulfillmentMutationRetrySemantics,
} from "./prize-shipping.js";
export type {
  BrowserContactSubmissionOptions,
  BrowserStorefrontContentContactClient,
} from "./content-contact.js";

function readDocumentCookie(name: string): string | undefined {
  if (typeof document === "undefined") {
    return undefined;
  }
  const prefix = `${name}=`;
  return document.cookie
    .split(";")
    .map((part) => part.trim())
    .find((part) => part.startsWith(prefix))
    ?.slice(prefix.length);
}

function defaultCsrfInitializer(
  configuration: BrowserStorefrontClientConfig,
): CsrfInitializer {
  const fetchImplementation = configuration.fetch ?? globalThis.fetch;

  return async ({ signal }) => {
    if (typeof fetchImplementation !== "function") {
      throw new StorefrontTransportError(
        "CSRF_INITIALIZATION_FAILED",
        "CSRF initialization fetch is unavailable",
      );
    }
    const response = await fetchImplementation(
      `${configuration.base_url}${USER_CSRF_INITIALIZATION_PATH}`,
      {
        method: "GET",
        credentials: "include",
        cache: "no-store",
        signal,
        headers: {
          Accept: "application/json",
          [CLIENT_VERSION_HEADER]:
            configuration.client_version ?? STOREFRONT_CLIENT_VERSION,
          [SITE_VERSION_HEADER]: configuration.site_version,
        },
      },
    );
    if (!response.ok && response.status !== 401) {
      throw new StorefrontTransportError(
        "CSRF_INITIALIZATION_FAILED",
        `CSRF initialization failed with status ${response.status}`,
      );
    }
    if (response.status === 401) {
      const problem = await response.clone().json().catch(() => undefined) as
        | { code?: unknown }
        | undefined;
      if (problem?.code !== "SESSION_EXPIRED") {
        throw new StorefrontTransportError(
          "CSRF_INITIALIZATION_FAILED",
          "CSRF initialization was rejected",
        );
      }
    }
  };
}

export function createBrowserStorefrontClient(
  configuration: BrowserStorefrontClientConfig,
): StorefrontTransport {
  return createTransport({
    ...configuration,
    csrf_initializer:
      configuration.csrf_initializer ?? defaultCsrfInitializer(configuration),
    csrf_token_reader: () =>
      (configuration.cookie_reader ?? readDocumentCookie)(USER_XSRF_COOKIE),
    credentials: "include",
    server_safe_only: false,
  });
}

export function createBrowserStorefrontDrawClient(
  configuration: BrowserStorefrontClientConfig,
): BrowserStorefrontDrawClient {
  return createCsrfManagedStorefrontDrawClient(
    createBrowserStorefrontClient(configuration),
  );
}

export function createBrowserStorefrontPrizeShippingClient(
  configuration: BrowserStorefrontClientConfig,
): BrowserStorefrontPrizeShippingClient {
  return createCsrfManagedStorefrontPrizeShippingClient(
    createBrowserStorefrontClient(configuration),
  );
}

export function createBrowserStorefrontContentContactClient(
  configuration: BrowserStorefrontClientConfig,
): BrowserStorefrontContentContactClient {
  return createCsrfManagedStorefrontContentContactClient(
    createBrowserStorefrontClient(configuration),
  );
}
