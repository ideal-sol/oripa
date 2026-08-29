# Luxe Pack Storefront API Upstream

## Boundary

The `luxe-pack.biz` Storefront uses Host Nginx for same-origin Public API v2
transport. The canonical API upstream is `http://127.0.0.1:8611`, which is the
existing loopback-only API publication. Docker-assigned or RFC1918 container
addresses are not stable Host Nginx upstreams.

This runbook manages only the exact `/api/v2` and prefix `/api/v2/` locations
inside `/etc/nginx/conf.d/luxe-pack.biz.conf`. It does not manage the
Storefront root proxy, TLS material, the `test.luxe-pack.biz` fincode webhook,
Admin or support vhosts, Cloudflare, Docker networks, or application images.

## Preconditions

- Use the exact squash-merged `main` version of
  `scripts/ops/preview_fincode_nginx.py`.
- Confirm local, origin, and GitHub `main` equal the approved squash SHA.
- Hold the Platform Integration and deployment locks required by the active
  Task.
- Confirm the active vhost SHA-256 still equals the Task's Stage 0 digest.
- Confirm root free space and API, Storefront, Nginx, Admin, PostgreSQL, and
  Redis health before mutation.
- Confirm the API publication remains `127.0.0.1:8611:8000`, the private Docker
  network remains internal, and Admin, PostgreSQL, and Redis have no host port.
- Record the rollback path and require it not to exist before activation.

Verify both managed vhosts before changing either one:

```bash
python3 /var/www/oripa/scripts/ops/preview_fincode_nginx.py verify \
  --server-name test.luxe-pack.biz \
  --input /etc/nginx/conf.d/test.luxe-pack.biz.conf
python3 /var/www/oripa/scripts/ops/preview_fincode_nginx.py verify \
  --server-name luxe-pack.biz \
  --input /etc/nginx/conf.d/luxe-pack.biz.conf
```

The first command must remain canonical. Before the initial live-domain repair,
the second command is expected to fail closed rather than accept a
container-specific upstream.

## Guarded Activation

For OPS-024, retain the byte-preserving rollback copy at
`/var/lib/oripa-v2-evidence/OPS-024/luxe-pack-vhost.before.conf`:

```bash
python3 /var/www/oripa/scripts/ops/preview_fincode_nginx.py activate \
  --server-name luxe-pack.biz \
  --input /etc/nginx/conf.d/luxe-pack.biz.conf \
  --backup /var/lib/oripa-v2-evidence/OPS-024/luxe-pack-vhost.before.conf
```

The helper creates a mode `0600` byte-identical backup before atomic
replacement and runs `/usr/sbin/nginx -t`.
The helper invokes one Nginx reload only after the config test passes.
Invalid input does not change the active file or create a backup. A config-test
failure restores the original bytes and does not reload.
Do not separately edit the generated vhost or reload Nginx outside this guarded
operation.

After activation, verify both server profiles again and confirm the test vhost
digest is unchanged. The live vhost must contain the stable loopback upstream
for exactly the two managed API locations and no container-specific API
upstream.

## Acceptance

- Direct loopback health, auth session, gachas, and point-products return HTTP
  200.
- The three corresponding `https://test.luxe-pack.biz/api/v2/*` requests remain
  HTTP 200.
- `https://luxe-pack.biz/api/v2/auth/session` is non-5xx, and live gachas and
  point-products return HTTP 200 JSON without redirect.
- `https://luxe-pack.biz/` and `https://luxe-pack.biz/points` return HTTP 200
  without an unexpected redirect.
- A fresh Nginx log window contains zero HTTP 500, 502, and 504 responses, and
  the live API requests reach the API application log.
- Host, X-Real-IP, X-Forwarded-For, X-Forwarded-Proto, request URI, method,
  request body, body-size, Cookie, CSRF, Origin/Referer, and Sec-Fetch-Site
  boundaries remain unchanged.
- Nginx and Storefront remain active; API, Admin, PostgreSQL, and Redis remain
  healthy; all restart counts remain zero.

Acceptance never uses Browser return handling, Provider state, Payment state,
Coin state, Mail, or real-user response bodies as authority.

## Rollback

If post-reload acceptance fails, restore only the approved live vhost from the
retained backup, test the restored configuration, and reload Nginx only if that
test passes:

```bash
python3 /var/www/oripa/scripts/ops/preview_fincode_nginx.py restore \
  --server-name luxe-pack.biz \
  --input /etc/nginx/conf.d/luxe-pack.biz.conf \
  --backup /var/lib/oripa-v2-evidence/OPS-024/luxe-pack-vhost.before.conf
/usr/sbin/nginx -t
/usr/bin/systemctl reload nginx
```

Re-run the health, endpoint, log, header, bind, private-network, and restart
checks after rollback. Keep the rollback file as root-only evidence.
