import type {
  PublicComponents,
  StorefrontResponse,
  StorefrontTransport,
} from "./types.js";

type Schemas = PublicComponents["schemas"];

export interface StorefrontPointProductClient {
  listPointProducts(): Promise<
    StorefrontResponse<Schemas["PointProductCollection"]>
  >;
}

export function createStorefrontPointProductClient(
  transport: StorefrontTransport,
): StorefrontPointProductClient {
  return {
    listPointProducts: () => transport.request({ path: "/point-products" }),
  };
}
