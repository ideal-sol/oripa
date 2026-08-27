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
at `scripts/ops/preview_fincode_nginx.py` discovers the existing Preview
`/api/v2/` upstream, rejects a Production vhost, rejects broad webhook locations,
and adds an exact-path POST-only proxy with the same forwarded headers. It does
not alter `/api/v2/`, `/admin/api/`, the Storefront fallback, TLS, or another
vhost.

Before applying, fix the current Task head and acquire both the Shared Preview
Deployment Lock and `/run/lock/oripa-v2-preview-deployment.lock`. Preserve a
root-only backup outside the Repository.

```bash
python3 scripts/ops/preview_fincode_nginx.py apply \
  --input /etc/nginx/conf.d/test.luxe-pack.biz.conf \
  --backup /var/lib/oripa-v2-evidence/<TASK_ID>/test-vhost.before.conf
nginx -t
systemctl reload nginx
python3 scripts/ops/preview_fincode_nginx.py verify \
  --input /etc/nginx/conf.d/test.luxe-pack.biz.conf
```

If `nginx -t` fails, restore before any reload:

```bash
python3 scripts/ops/preview_fincode_nginx.py restore \
  --input /etc/nginx/conf.d/test.luxe-pack.biz.conf \
  --backup /var/lib/oripa-v2-evidence/<TASK_ID>/test-vhost.before.conf
nginx -t
```

## Acceptance

- `POST https://test.luxe-pack.biz/webhooks/v2/fincode` reaches the API and
  returns the existing authentication failure while the signature is absent.
- Both `/api/v2/payment-returns/fincode/{normal,failure}` paths reach the API and
  normalize malformed or unknown `pid` values without Payment or Coin mutation.
- Existing Public API session and Admin login routes remain reachable.
- API and Admin remain healthy with restart count zero and no new 500, 502, or
  504 response.

This procedure does not configure fincode credentials, register a Provider
Webhook, enable Payment, or communicate with fincode.
