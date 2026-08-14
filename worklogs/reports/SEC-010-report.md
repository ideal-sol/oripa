# SEC-010 nanoid Advisory対応

- Issue: #261
- Risk: R4
- Base: `main@3b8445f1cf8f858fb46c0afe9e366faaf5e78f5e`
- Advisory: `GHSA-2v37-7h3g-55p8` (`CVE-2026-67213`, High)
- 変更: `nanoid 3.3.17 -> 3.3.18`
- 依存経路: Root `apps/admin > vitest > vite > postcss > nanoid`、Legacy `next > postcss > nanoid`
- Scope: Root override、Root／Legacy Lockfile、中央Pin Assertion、Task記録のみ
- Baseline: 追加・延長・無視なし
- Runtime／Preview: 非変更
- 検証: Root／Legacy Audit、Frozen Install、Security Unit／Gate、Policy Unit／Gate、Required Checks
