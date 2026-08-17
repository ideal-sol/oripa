# OPS-007 V2 Preview Public Asset Persistence Report

## Result

- Issue `#283`, Risk `R4`, Base `f3cfff8c3f707cdc49fcf8101788f7e3ba2ac36f`.
- Root cause: `FILESYSTEM_DISK=local` used the API writable layer at `/var/www/backend/storage/app/private`; container recreation removed bytes while DB metadata remained.
- Fix: `v2_api_assets` named volume mounts only that Laravel filesystem root. The active Preview volume is `mig061a-v2-preview_v2_api_assets`, root:root, directories `0700`, files `0600`.
- Migration: 29 existing objects were copied to root-only evidence, checksum-checked, and copied into the volume. DB references, data, and migrations are unchanged; no Production host build ran.
- Rollback: recreate API with the prior Compose definition; retain the root-only snapshot and named volume. No volume was pruned or deleted.

## Verification

- API-only `--no-build --no-deps --force-recreate` ran twice against the unchanged image; API is healthy after both recreations.
- 25 public images return HTTP 200 with matching DB checksum and MIME. Four non-image fixture rows remain expected 404.
- The existing upload controller returned `201` without a DB reference; its image endpoint SHA-256 matched before and after the second recreation.
- `test.luxe-pack.biz` top and Public Banner API return 200. API health and uploaded asset endpoint return 200; verification observed no HTTP 500/502/504.

## Remaining Seven

No canonical bytes exist for the following public metadata rows. No byte was guessed or generated, and no DB reference was deleted.

| Asset ID | Content reference | Public exposure | Re-upload required |
| --- | --- | --- | --- |
| `019fd0a5-1490-734c-8da2-4950b7426d82` | Draft banner `banner-019fd0a515a0730f9df269ae64f3b3ab` | Not published | Original JPEG, checksum verified. |
| `019ff49d-3708-7269-b98e-e684d019f687` | No Content version relation | Not currently Content-exposed | Original PNG if made current. |
| `019ffdea-298e-72b0-9d8f-bbf9a7864106` | No Content version relation | Not currently Content-exposed | Original PNG if made current. |
| `019ffeb0-4094-7127-88fc-f6b31da22a50` | Historical published banner version | Not current pointer | Original PNG before use. |
| `019ffeb0-9124-7128-b5ed-c2d2fa3b8677` | Historical published banner version | Not current pointer | Original PNG before use. |
| `019ffeb0-cf7b-70e8-81c0-fa7d458c6d4a` | Published banner `banner-019ffeb0d08e707b871178e8be2d477f` | Current public | Original JPEG, checksum verified. |
| `01a00340-0c79-7197-af75-36b03dffb301` | Published banner `banner-01a003400d9770dbb651a58df41077f9` | Current public | Original PNG, checksum verified. |

`luxe-pack.biz` remains V1. The V2 retry is **NOT READY** until the two currently exposed originals are recovered and a separate browser asset acceptance task passes.
