import { createStorefrontClient } from "@oripa/storefront-client";

export const client = createStorefrontClient({
  baseUrl: process.env.NEXT_PUBLIC_ORIPA_API_BASE_URL ?? "",
});
