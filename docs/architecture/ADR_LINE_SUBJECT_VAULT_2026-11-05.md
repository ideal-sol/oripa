# LINE subject encryption and friendship observation

Status: Human-approved scope; implementation and verification in progress.
Change: LINE-LINK-FRIEND-20261105. Risk R3; Strict Change; Runtime Activation deferred.

Human decisions in the 2026-11-05 Platform implementation prompt override the
earlier LINE v1.1 and LPlus O1 reference wherever they differ.

The only new persistence exception is an encrypted LINE subject in the existing
identity, friendship and pending-follow rows. HMAC remains identity authority.
The vault validates current/previous HMAC candidates without rewriting history;
NULL may be filled once and existing ciphertext must decrypt to the same value.
Reads are centralized and require a current, non-revoked identity and active
account. Neither ciphertext, availability flags nor raw subjects are API fields.
Other provider subjects, tokens and message bodies are outside this exception.

The existing account holds only the latest observation time and friend flag.
This also remembers a negative API result before a friendship exists. Newer
observations win; equal-time unfollow wins. Existing settings/account locks,
friendship constraints, reward eligibility and point operations remain authority.
The API observation transaction precedes a separate atomic reward transaction;
financial failure must preserve authentication and friend state and permit retry
through existing LINE reauthentication. No reward endpoint or worker is added.

Browser return is restricted to session-bound LINE link/reauthentication and
the exact `/mypage/line` path. JSON callback compatibility remains. Safe notices
are held server-side for 120 seconds under the rotated session hash and consumed
under a cache lock by the existing authenticated friend-state read. No protocol
values travel in the return URL. Real provider browser acceptance remains Human.

Retain additive schema on rollback; no operational migration or activation is
authorized. LPlus O2–O7, broadcasts and deferred decision 14 are excluded.
