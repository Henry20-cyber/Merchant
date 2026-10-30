# Account production remediation — remaining decisions and launch gates

This document accompanies the source-level remediation package. It deliberately records items that cannot be safely completed from source code alone.

## Required deployment configuration

- Set `CORS_ALLOWED_ORIGINS` to the exact HTTPS browser origin(s) used by the production frontend. Do not use wildcards with credentialed cookies.
- Set `SANCTUM_STATEFUL_DOMAINS` to the frontend hostname(s), including non-default ports if applicable.
- Set `SESSION_DOMAIN` only after confirming the frontend/API domain relationship. For sibling subdomains, use the shared parent domain. For different registrable domains, use `SESSION_SAME_SITE=none` and `SESSION_SECURE_COOKIE=true`.
- `SESSION_SECURE_COOKIE` defaults to true when `APP_ENV=production`, but an explicit deployment value should be set and verified.
- Configure `PAYSTACK_SECRET_KEY` through the production secret manager. Never place it in source control.

## Paystack recurring billing is not ready to enable yet

New plan rows have no provider-issued `paystack_plan_code` until the real Paystack plans are configured. The seeder no longer overwrites an already-configured plan code on existing rows. The repaired checkout service refuses paid checkout when the recurring plan code is missing. This prevents a customer from paying for a plan that the application cannot establish as a recurring subscription.

Before enabling paid subscription checkout, create/confirm the actual plans in the correct Paystack account and populate each corresponding `subscription_plans.paystack_plan_code` with the real provider-issued value. Do not seed placeholder values. Verify amount, currency, billing interval, and webhook behaviour in Paystack test mode, then production mode.

Plan upgrades/downgrades for an existing Paystack recurring subscription are intentionally blocked. A correct implementation needs a coordinated provider-side cancellation/update workflow, proration/effective-date policy, and failure recovery. The current code does not safely implement that workflow. Existing pending payments for a different plan from a subscription that already has a provider subscription code require support reconciliation; they are no longer silently treated as a successful plan change.

## Branch authorization policy is not inferable from the supplied source

The current data model does not associate individual business memberships with branch assignments. The existing behaviour allows an active member to select any branch belonging to their business. The source does not establish whether that is the intended product rule.

Before implementing branch-specific employee restrictions, the product owner must decide whether: (a) every active business member may access all branches, or (b) members must be assigned to one or more branches, with explicit exceptions for Owner/Manager roles. No assignment schema or access restriction was invented in this patch.

If policy (b) is selected, a backward-compatible proposal is a `branch_user` pivot with `business_id`, `branch_id`, `user_id`, `status`, timestamps, foreign keys, and a unique `(branch_id, user_id)` constraint. Roll it out additively: create the pivot table first; backfill existing active members to every current branch to preserve today's access; deploy branch-scope checks in audit/log-only mode; then explicitly assign employees and enable enforcement behind a feature flag. Owner/Manager exceptions must be explicitly confirmed. This requires a reviewed migration and product approval; none was created or run here.

## Existing recurring subscriptions and null billing dates

Subscriptions with missing `current_period_end` or missing grace-period end timestamps now fail closed for access. The lifecycle processor transitions invalid active/trial records to `past_due` and invalid grace-period records to `restricted`. Review existing production rows before deployment so that legacy records with missing timestamps are identified and corrected from authoritative billing records where appropriate.

## Database and deployment

No database migration was added or executed. No production database was accessed. No deployment, Git push, merge, or remote repository mutation was performed. Apply this package to a staging copy first, back up the database, run the full test suite, validate Paystack test transactions and cookie-based login, and only then schedule production deployment.

## API rate limiting

The API now applies explicit named limits: login is limited to 5 attempts per minute per normalized identifier and 20 attempts per minute per IP; owner and employee registration are limited to 5 attempts per hour per IP and per email (employee registration also has a per-IP/merchant-ID cap); authenticated API traffic is limited to 180 requests per minute per user and 1,200 requests per minute per source IP; public reference endpoints are limited to 60 requests per minute per IP; password reset and public receipt verification retain their dedicated limits; and the signed Paystack webhook endpoint allows up to 300 requests per minute per source IP to accommodate provider retries.

Rate limits depend on the configured Laravel cache store. For multiple application instances, use a shared atomic cache backend such as Redis; do not use per-instance file/array cache. Configure trusted proxies correctly so `Request::ip()` resolves the actual client IP behind the production load balancer/CDN. Do not blindly trust arbitrary forwarded headers. Validate limits against real POS traffic and Paystack webhook retry patterns in staging before launch.

**Additional paid-billing launch gate:** initial recurring subscription creation still calls Paystack from inside the local subscription transaction. Provider HTTP calls now have bounded connection/response timeouts, and transaction verification was moved outside the row-locking transaction. However, a provider subscription could still be created remotely immediately before a later local database failure. Keep paid recurring checkout disabled until a durable reconciliation/compensation workflow (preferably an outbox/saga with provider subscription identifiers and retryable state) is implemented and tested.

## Database integrity item requiring staging/production verification

The historical `create_credits_table` migration chains `unique()` after `constrained()` on the `sale_id` foreign-key definition. That chain does not clearly establish a unique index on the `credits.sale_id` column. Before launch, inspect PostgreSQL indexes/constraints and check for duplicate `sale_id` values. If uniqueness is absent, add a reviewed forward-only migration after confirming existing data is clean. The historical migration was not edited and no migration was created or executed in this remediation.
