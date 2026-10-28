import type { PublicComponents } from "@oripa/storefront-client";

export const PAYMENT_FOUNDATION_FIXTURES = Object.freeze({
  methods: { data: [
    {method: "credit_card", label: "クレジットカード", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "inline_card_form"},
    {method: "netridecash", label: "電子マネー（NetRideCash）", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "redirect"},
    {method: "paidy", label: "あと払い（ペイディ）", available: false, unavailable_reason: "provider_unavailable", saved_cards_enabled: false, flow: "paidy_checkout"},
  ]},
  form_post: {type: "form_post", url: "https://provider.example.test/checkout", fields: [{name: "sendid", value: "synthetic-order"}, {name: "amount", value: "1000"}]},
  paidy_checkout: {
    type: "paidy_checkout", script_url: "https://apps.paidy.com/", public_key: "pk_synthetic_public_only",
    payload: {amount: 1000, currency: "JPY", store_name: "Synthetic store", description: "コイン購入",
      buyer: {email: "member@example.test", phone: "09000000000"},
      buyer_data: {user_id: "0198a001-0000-7000-8000-000000000099", age: 0, ltv: 0, order_count: 0, order_count_last3months: 0, order_amount_last3months: 0, number_of_points: 0},
      order: {order_ref: "0198a001-0000-7000-8000-000000000098", shipping: 0, tax: 0,
        items: [{id: "0198a001-0000-7000-8000-000000000097", title: "Synthetic coins", quantity: 1, unit_price: 1000}]},
    },
  },
  paidy_authorization: {paidy_payment_id: "pay_synthetic_payment"},
  client_event: {type: "inline_result", detail: {result: "success", charge_id: "0198a001-0000-7000-8000-000000000099"}},
  univapay_action: {
    type: "univapay_inline", amount: 1000,
    update: {metadata: {"oripa-payment-id": "0198a001-0000-7000-8000-000000000098", "oripa-member": "0198a001-0000-7000-8000-000000000097"}, email: "synthetic@example.test",
      phoneNumber: {countryCode: "+81", localNumber: "09000000000"}},
    success_url: "https://example.test/points/purchase/thanks?pid=0198a001-0000-7000-8000-000000000098",
  },
} as const satisfies {
  methods: {data: readonly PublicComponents["schemas"]["PaymentMethodOption"][]};
  form_post: PublicComponents["schemas"]["PaymentFormPostAction"];
  paidy_checkout: PublicComponents["schemas"]["PaymentPaidyCheckoutAction"];
  paidy_authorization: PublicComponents["schemas"]["PaymentPaidyAuthorizationRequest"];
  client_event: PublicComponents["schemas"]["PaymentClientEvent"];
  univapay_action: PublicComponents["schemas"]["PaymentUnivapayInlineAction"];
});
