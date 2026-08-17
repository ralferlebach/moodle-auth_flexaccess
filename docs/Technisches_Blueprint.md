# auth_flexaccess — Technisches_Blueprint

**Verantwortung:** zielabhängiges Login-Routing, Accounttypen/-zustände, temporäre Nutzer, Schnellregistrierung, Aktivierung/Conversion, Token- und Mail-Lifecycle.


## 1. Komponenten

```text
                         Moodle request / wantsurl
                                   |
                                   v
                         auth_flexaccess
                  +----------------+----------------+
                  |                                 |
          target_resolver                    account_service
                  |                                 |
                  v                                 v
        enrol_flexaccess policy             account metadata
                  |                          token service
                  v                          mail queue/task
           course enrolment                        |
                  |                                 |
                  +---------------+-----------------+
                                  v
                         original course/activity

                mod_flexaccess
                          |
                          +----> auth_flexaccess activation_service

                      tool_flexaccess
                 +---------+---------+
                 |                   |
                 v                   v
          auth_flexaccess API   enrol_flexaccess API
          account operations    policy diagnostics
          mail operations       enrol diagnostics
```

`tool_flexaccess` is deliberately outside the runtime login path. A failure or absence of the admin tool must never prevent login, enrolment or self-activation.

## 2. Plugin API boundaries

### auth_flexaccess
Public facade planned as `auth_flexaccess\api`:

- `classify_user(int $userid): string`
- `get_account(int $userid): ?account`
- `search_accounts(account_filter $filter, pagination $page): account_page`
- `create_temporary_user(target_context $target, account_policy $policy): int`
- `begin_quick_registration(registration_data $data, target_context $target): int`
- `request_activation(int $userid, ...): void`
- `activate_with_token(string $token, ...): void`
- `convert_temporary_user(int $userid, conversion_data $data): void`
- `suspend_managed_user(int $userid, string $reason): void`
- `queue_account_deletion(int $userid, ...): void`
- `get_mail_queue_status(queue_filter $filter, pagination $page): queue_page`
- `retry_mail_job(int $jobid): void`
- `get_mail_rate_status(): mail_rate_status`

### enrol_flexaccess
Public facade planned as `enrol_flexaccess\api`:

- `get_effective_policy(int $courseid, ?int $userid = null): policy`
- `explain_effective_policy(int $courseid, ?int $userid = null): policy_trace`
- `is_target_enabled(int $courseid): bool`
- `enrol_for_access(int $courseid, int $userid, access_context $context): void`
- `apply_expiry(int $enrolid, int $userid): void`

### tool_flexaccess
No ownership of account/enrolment state. It is an admin application layer consuming the public auth/enrol facades. No other FlexAccess plugin depends on it.

### mod_flexaccess
No cross-plugin domain ownership. It consumes the auth facade and, where needed, enrol policy/read services.

## 3. Data ownership

### auth_flexaccess_account
One row per FlexAccess-managed user: `userid`, `accounttype`, `accountstate`, reference number, source course/module, creation/expiry/activation timestamps.

### auth_flexaccess_token
Hashed single-use tokens: purpose, hash, expiry, used timestamp.

### auth_flexaccess_mailqueue
Semantic mail jobs: recipient, mail kind, non-secret payload, retry state, due/sent timestamps.

### enrol_flexaccess_instance
Extended per-course enrolment configuration keyed by core `enrol.id`.

### enrol_flexaccess_policy
Course-category policy overrides.

### enrol_flexaccess_restriction
Role/Cohort allow/deny restrictions for policy scopes.

### flexaccess
Standard activity instance table; no second user-account store.

### tool_flexaccess
No domain table in the MVP. Auditability relies on Moodle events/logs emitted by the owning services.

## 4. Deep-link sequence

```text
GET /mod/quiz/view.php?id=4711
 -> require_login()
 -> Moodle login routing
 -> auth_flexaccess sees internal wantsurl
 -> target_resolver: cmid 4711 -> course 123
 -> enrol_flexaccess::get_effective_policy(123)
 -> /auth/flexaccess/access.php
 -> choose method
 -> create/session/enrol as required
 -> redirect validated wantsurl
```

The scaffold does not activate the redirect hook yet; production code must add it only after loop prevention, target validation and Behat coverage exist.

## 5. State transitions

```text
anonymous request
   | temporary access
   v
accounttype: temporary user
state: ephemeral
   | self/quick activation started
   v
accounttype: temporary user
state: provisional
   | verified + password set
   v
accounttype: authenticated user
state: active
```

Expiry is orthogonal. Course enrolments retain their own `timestart/timeend/status` lifecycle.

## 6. Admin-tool operation sequence

```text
administrator
 -> /admin/tool/flexaccess/accounts.php
 -> tool/flexaccess:viewaccounts
 -> auth_flexaccess::search_accounts(...)
 -> select one account
 -> POST conversion/suspension action + sesskey
 -> dedicated tool capability check
 -> auth_flexaccess service mutation
 -> owning plugin emits Moodle event
 -> redirect to detail page with result
```

The tool must never write `auth_flexaccess_*`, `enrol_flexaccess_*`, `user` or `user_enrolments` tables directly.

## 7. Mail rate algorithm

The global setting values are `10`, `50`, `100`, `500`, `0` (`0 = unlimited`). A task acquires a Moodle lock, counts successful FlexAccess queue rows with `timesent >= now - 3600`, computes remaining capacity, and processes only that many jobs. The tool may display this state but does not implement the algorithm.

## 8. Participant visibility compatibility gate

The product requirement remains system default + course override. Implementation requires a public, Core-compatible mechanism on Moodle 4.5, 5.1 and 5.2; no Core patch or unsupported DB interception is accepted.

## 9. Migration from the old alternate login form

The legacy script directly opens a MySQL connection, locks user/session tables, selects an unused `guest.*` account, uses a shared fixed password and posts credentials through hidden form inputs. FlexAccess removes all of these patterns. The admin tool can later assist with inventory/retirement of old pooled accounts, but migration logic remains an explicit, separately tested operation.

## 10. Implementation phases

### Phase 1 — safe foundations
Schema, capabilities, public facades/DTOs, policy resolver, target resolver, no-op login provider, events, privacy, tests.

### Phase 2 — temporary access + operations
Individual temporary-user creation, role restrictions, automatic enrolment, expiry/cleanup, `tool_flexaccess` account search/detail and admin conversion.

### Phase 3 — quick registration + mail operations
Forms, duplicate handling, queue, activation/delete tokens, mail throttle, password setup, admin queue/rate dashboard and retry actions.

### Phase 4 — self activation activity
Course activity, profile-field allowlist, same-user conversion, completion integration.

### Phase 5 — compatibility/completeness
Participant-list visibility, signed deep links/QR, magic link, category policy UI, policy diagnostics, course backup/restore/reset, accessibility and load/security testing.

## 11. Explicit non-goals

- no `local` or `block` plugin,
- no FlexAccess-specific `availability` plugin,
- no Core patch,
- no shared guest-account pool,
- no password transmission by mail,
- no automatic user merge when an entered E-Mail already belongs to another Moodle user,
- no duplicated account/policy persistence in `tool_flexaccess`.


## Zusatzanforderung: Zugangsschlüssel-Challenge

`auth_flexaccess` orchestriert vor temporärer Gast-Anmeldung und Schnellregistrierung optional eine kursbezogene Zugangsschlüssel-Challenge. Die Policy und Verifikation gehören `enrol_flexaccess`; `auth_flexaccess` darf weder System- noch Kurs-Hash lesen oder persistieren. Nach erfolgreicher Prüfung wird ausschließlich eine kurzlebige, kursgebundene Freigabe in der serverseitigen Session gespeichert. Normaler Login, normaler Moodle-Gastzugang und die spätere Selbstaktivierung bleiben unbeeinflusst.
