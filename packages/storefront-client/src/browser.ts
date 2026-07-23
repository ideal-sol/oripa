import { ApiProblemError, StorefrontTransportError } from "./errors.js";
import {
  createIdempotencyKey,
  createTransport,
} from "./transport.js";
import type {
  BrowserStorefrontClientConfig,
  StorefrontTransport,
} from "./types.js";

export { ApiProblemError, StorefrontTransportError, createIdempotencyKey };
export type {
  BrowserStorefrontClientConfig,
  StorefrontRequestOptions,
  StorefrontResponse,
  StorefrontResponseMetadata,
} from "./types.js";

export function createBrowserStorefrontClient(
  configuration: BrowserStorefrontClientConfig,
): StorefrontTransport {
  return createTransport({
    ...configuration,
    credentials: "include",
    server_safe_only: false,
  });
}
