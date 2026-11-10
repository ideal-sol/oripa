# OLD Test Paidy preparation

Source approval is not Build, installation, ENV, Nginx, Webhook or Runtime GO.
Keep GTM OFF, UnivaPay TEST and Paidy Worker OFF. No operational migrations.

## Nginx preparation (not applied)

Use `paidy-old-test-locations.conf.example` inside the existing Test HTTPS
server. Render the exact token from the same root-owned mode0600 private input
used for `V2_PAIDY_WEBHOOK_PATH_TOKEN`; do not pass it as a command argument,
print rendered configuration, run `nginx -T`, or put it in public evidence.
Keep the existing UnivaPay/fincode locations and server-wide Basic auth intact.
Only the exact Paidy location has `auth_basic off`; all other Paidy paths fail
closed. Preserve request body, URI, method and forwarded headers.

Add this rule to the existing `$uri` -> `$luxe_pack_test_safe_uri` map:

```nginx
~^/webhooks/v2/paidy/ /webhooks/v2/paidy/REDACTED;
```

For HTTP port80, replace the existing server-level redirect with a `location /`
containing the identical `return 301 https://$host$request_uri;`. Add the Paidy
prefix location from the template (404, access/error logs suppressed). This
prevents a tokenized HTTP URL from being redirected or sent upstream. Other
HTTP URLs retain the existing redirect. Register only the HTTPS URL with Paidy.

Keep `return 503` in the exact HTTPS location until API logging protection,
private ENV, candidate health and separately approved Runtime activation pass.
Human's Basic preparation alone must not expose the old URI-logging API.
Removing this one staging directive is a separately approved opening operation.
Do not acknowledge a request with a synthetic200 or send a real provider POST
during preparation. Preserve provider retries on503.

## Validation and rollback

Before installation: validate the rendered configuration in an isolated Nginx
prefix with synthetic token/Basic credentials, including exact path, wrong path,
non-POST, URL encoding, upstream failure, HTTP rejection and log non-disclosure.
Capture diagnostics privately and check them for the synthetic token before
reporting only status; Nginx config errors can include the secret directive.
The committed template is tested with staging503 and with that directive removed.

On authorized installation, acquire existing deployment/F2-B locks, compare the
observed vhost digest, retain its root-only byte-exact rollback, run `nginx -t`
with private captured output, and reload only on success. Recover the previous
configuration on failure. Never remove Basic globally. Never change UnivaPay.
No Nginx installation or reload is authorized by either Source PR.

The PHP router masks only diagnostic URI bytes. The log manager decorates the
existing handler formatter, including emergency fallback, after exceptions and
nested context have been formatted. Request bytes and provider authentication
are not transformed. `tests/paidy_serve_probe.php` uses a disposable application,
synthetic token, loopback ephemeral port and no operational DB/provider. It
exercises real artisan serve output and Laravel exception/context logging; the
verified streams can also be captured by an isolated Docker json-file logger.
Never run the probe inside an active application container.

Acceptance separates Checkout availability, actual Webhook200 plus durable
receipt, and asynchronous reconciliation/Capture/grant. The last requires a
separate Human Worker ON decision. Do not equate any one with payment success.

Security remediation fixes only six Symfony8.1 packages at8.1.11. Re-audit the
new Lock; neither the historical alpha51 metadata exception nor PHP8.4.27
verification proves the Composer posture. Keep the official alpha51 artifact
immutable. Build only the approved repaired API Source, and Storefront PR229
with GTM disabled, after separate Build GO. Preserve Ledger106 and old Runtime
images/releases for Canonical Recovery; LINE665/107 and Production are excluded.
