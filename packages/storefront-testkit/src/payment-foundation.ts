import type { PublicComponents } from "@oripa/storefront-client";

export const PAYMENT_FOUNDATION_FIXTURES = Object.freeze({
  methods: { data: [
    {method: "credit_card", label: "クレジットカード", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "inline_card_form"},
    {method: "netridecash", label: "電子マネー（NetRideCash）", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "redirect"},
    {method: "paidy", label: "あと払い（ペイディ）", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "paidy_checkout"},
  ]},
  form_post: {type: "form_post", url: "https://provider.example.test/checkout", fields: [{name: "sendid", value: "synthetic-order"}, {name: "amount", value: "1000"}]},
  client_event: {type: "inline_result", detail: {result: "success", charge_id: "0198a001-0000-7000-8000-000000000099"}},
} as const satisfies {
  methods: {data: readonly PublicComponents["schemas"]["PaymentMethodOption"][]};
  form_post: PublicComponents["schemas"]["PaymentFormPostAction"];
  client_event: PublicComponents["schemas"]["PaymentClientEvent"];
});
