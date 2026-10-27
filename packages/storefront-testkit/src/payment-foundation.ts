import type { PublicComponents } from "@oripa/storefront-client";

export const PAYMENT_FOUNDATION_FIXTURES = Object.freeze({
  methods: { data: [
    {method: "credit_card", label: "クレジットカード", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "inline_card_form"},
    {method: "netridecash", label: "電子マネー（NetRideCash）", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "redirect"},
    {method: "paidy", label: "あと払い（ペイディ）", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "paidy_checkout"},
  ]},
  form_post: {type: "form_post", url: "https://secure.tc-site.com/access", fields: [
    {name: "clientip", value: "0123456789"}, {name: "payment_code", value: "10055"},
    {name: "cookie", value: "0198a001000070008000000000000099"}, {name: "price", value: "3000"},
    {name: "sendid", value: "syntheticmember1"}, {name: "email", value: "synthetic@example.test"},
  ]},
  client_event: {type: "inline_result", detail: {result: "success", charge_id: "0198a001-0000-7000-8000-000000000099"}},
} as const satisfies {
  methods: {data: readonly PublicComponents["schemas"]["PaymentMethodOption"][]};
  form_post: PublicComponents["schemas"]["PaymentFormPostAction"];
  client_event: PublicComponents["schemas"]["PaymentClientEvent"];
});
