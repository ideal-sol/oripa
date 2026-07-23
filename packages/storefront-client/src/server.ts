import { ApiProblemError, StorefrontTransportError } from "./errors.js";
import {
  createIdempotencyKey,
  createTransport,
} from "./transport.js";
import type {
  ServerStorefrontClientConfig,
  StorefrontTransport,
} from "./types.js";

export { ApiProblemError, StorefrontTransportError, createIdempotencyKey };
export type {
  ServerStorefrontClientConfig,
  StorefrontRequestOptions,
  StorefrontResponse,
  StorefrontResponseMetadata,
} from "./types.js";

export function createServerStorefrontClient(
  configuration: ServerStorefrontClientConfig,
): StorefrontTransport {
  return createTransport({
    ...configuration,
    credentials: "same-origin",
    server_safe_only: true,
  });
}
