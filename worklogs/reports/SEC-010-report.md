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
- Policy SHA-256: `eefcceed23230cc3a580fad4c1dae66b192f94b99f4c3ac3d2835cdd1e2920f8`
- Local Audit: Composer 0、Root pnpm 0、Legacy pnpm 0
- Local Gate: Frozen Install、Security Unit 10、Policy Unit 125、Security Gate、Policy Gate、`git diff --check` PASS
- Required Checks: Final Headで実行
