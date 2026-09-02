# SMS FourS Worker Activation

The Platform SMS delivery worker is implemented but is not activated by the
source Change that introduced it. Runtime activation is a separate merge-first
operation and must not send a real SMS until an approved QA phone is recorded.

## Required Runtime Inputs

- `V2_APP_NAME` supplies the exact application name rendered into the SMS body.
- `V2_SMS_FOURS_CP_USERID` and `V2_SMS_FOURS_CP_PASSWORD` come from the approved
  secret store and must never be printed, committed, or placed in reports.
- `V2_SMS_FOURS_ENDPOINT` remains an HTTPS endpoint and defaults to the SMS
  FourS `sms_send` endpoint.
- `V2_SMS_FOURS_USER_AGENT` must be non-empty.
- `V2_SMS_FOURS_TIMEOUT_SECONDS` must be between 1 and 30 seconds.

The `sms-fours` Compose profile is disabled by default. Activation requires the
target environment readback, Migration `000071`, worker configuration, network
egress verification, an approved QA phone, and a separately authorized smoke
test. Provider timeout or another ambiguous request boundary is terminal for
that Challenge and is never blindly retried.

Production migration, Production secret injection, and Production activation
remain outside this runbook's autonomous authority.
