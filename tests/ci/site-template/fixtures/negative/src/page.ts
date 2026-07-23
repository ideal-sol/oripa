import { createStorefrontClient } from "@oripa/storefront-client";

export async function drawDirectly() {
  const requestId = Math.random();
  return fetch(`/api/v2/draw?request=${requestId}`);
}
