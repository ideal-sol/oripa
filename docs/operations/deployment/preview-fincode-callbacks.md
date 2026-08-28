# Shared Preview fincode Callbacks

## Boundary

This runbook applies only to the Shared V2 Preview at `test.luxe-pack.biz`.
Production vhosts, Production environment files, Storefront source, Admin source,
PostgreSQL, Redis, and Docker networks are outside this procedure.

The API receives two explicit non-secret origins:

- `FINCODE_PLATFORM_ORIGIN` is the HTTPS origin used for fincode POST returns.
- `FINCODE_STOREFRONT_ORIGIN` is the HTTPS origin used after the Platform issues
  the canonical HTTP 303 response.

Neither origin may equal `V2_ADMIN_ORIGIN`. The V2 Compose fallback is
`V2_PUBLIC_ORIGIN`, never `V2_ADMIN_ORIGIN`. Shared Preview explicitly sets both
fincode origins to `https://test.luxe-pack.biz` so a later change to a generic
Application or Admin origin cannot redirect Payment callbacks to Admin.

## Webhook Route

Only the exact path `POST /webhooks/v2/fincode` is exposed. The canonical patcher
at `scripts/ops/preview_fincode_nginx.py` fixes the exact `/api/v2`, `/api/v2/`
prefix, and exact fincode webhook proxies to the Host Nginx stable loopback
upstream `http://127.0.0.1:8611`. It rejects a Production vhost, broad webhook
locations, changed URI semantics, changed forwarded headers, and RFC1918
container-specific upstreams during verification. It does not alter
`/admin/api/`, the Storefront fallback, TLS, Docker networks, or another vhost.

Before applying, fix the current Task head and acquire both the Shared Preview
Deployment Lock and `/run/lock/oripa-v2-preview-deployment.lock`. Preserve a
root-only backup outside the Repository.

```bash
python3 scripts/ops/preview_fincode_nginx.py activate \
  --input /etc/nginx/conf.d/test.luxe-pack.biz.conf \
  --backup /var/lib/oripa-v2-evidence/<TASK_ID>/test-vhost.before.conf
python3 scripts/ops/preview_fincode_nginx.py verify \
  --input /etc/nginx/conf.d/test.luxe-pack.biz.conf
```

`activate` creates the mode `0600` byte-preserving backup before its atomic
replacement, runs `/usr/sbin/nginx -t`, and invokes exactly one
`systemctl reload nginx` only after the config test passes. A failed config test
restores the backup and never invokes reload. Retain the backup for this explicit
rollback command:

```bash
python3 scripts/ops/preview_fincode_nginx.py restore \
  --input /etc/nginx/conf.d/test.luxe-pack.biz.conf \
  --backup /var/lib/oripa-v2-evidence/<TASK_ID>/test-vhost.before.conf
nginx -t
```

## Acceptance

- Direct `http://127.0.0.1:8611/api/health` and the three required API routes
  return their canonical non-5xx responses.
- Storefront same-origin `/api/v2/auth/session`, `/api/v2/gachas?limit=1`, and
  `/api/v2/point-products` are non-5xx; the catalog endpoints return HTTP 200.
- `POST https://test.luxe-pack.biz/webhooks/v2/fincode` reaches the API and
  returns the existing authentication failure while the signature is absent.
- Both `/api/v2/payment-returns/fincode/{normal,failure}` paths reach the API and
  normalize malformed or unknown `pid` values without Payment or Coin mutation.
- Existing Public API session and Admin login routes remain reachable.
- Storefront `/` and `/points` return HTTP 200 without an unexpected redirect.
- API, Storefront, Admin, PostgreSQL, Redis, and Nginx remain healthy or active
  with restart count zero and no new 500, 502, or 504 response.

This procedure does not configure fincode credentials, register a Provider
Webhook, enable Payment, or communicate with fincode.
