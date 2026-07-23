# OpenAPI

## Responsibility

Public／Admin／Webhook APIのOpenAPI 3.1.1 Contract正本を管理する。3つの
Surfaceは独立したEntry PointとBundleを持ち、共通Primitiveだけを共有する。

## Ownership

OwnerはPlatform Codex。[`AGENTS.md`](AGENTS.md)とRoot
[`AGENTS.md`](../AGENTS.md)に従う。

## Planned Components

- `public/openapi.yaml`: `/api/v2`用Contract
- `admin/openapi.yaml`: `/admin/api/v2`用Contract
- `webhook/openapi.yaml`: `/webhooks/v2`用Contract
- `components/common.yaml`: 正本で確定済みの共通Primitive
- `bundled/*.openapi.json`: Redoclyで決定的に生成するReview用Bundle
- `redocly.yaml`: 3 Contract共通Lint設定

## Allowed Scope

Contract-first手順で承認されたAPI Contractだけを配置する。業務Endpoint追加時は
OpenAPI、Contract Test、Client生成、Laravel、E2Eの順序を維持する。

## Forbidden Scope

Secret、Internal Field、実PII、未承認Endpoint、V1 CodeをCopyしない。Public
ContractへAdmin／Webhook型、Provider内部情報、景品別確率を公開しない。

## Status

現時点は共通Primitiveと空の`paths`を持つContract Skeletonであり、Production
利用不可。業務Endpoint、Laravel Route、Generated Clientは未実装である。

## Validation

```text
pnpm openapi:test
pnpm openapi:check
```

`openapi:check`は3 ContractをLintし、再生成BundleがCommit済みBundleと一致する
こと、前のContractからBreaking Changeがないことを確認する。Bundle更新が必要な
承認済みContract変更では`pnpm openapi:bundle`を実行し、生成差分をReviewする。
