# QR receipt verification implementation notes

## What changed

- Original sale receipts and credit-sale receipts receive a random, unguessable verification token.
- Successful credit repayments receive separate payment receipts; the existing payment and credit status calculation remains in `PaymentService` / `CreditService`.
- The receipt snapshot is hashed and HMAC-signed with the Laravel `APP_KEY`.
- Receipt audit events form a per-business HMAC hash chain. PostgreSQL installs triggers that reject updates, deletes, and truncation of audit events.
- Existing receipts are backfilled with verification tokens, snapshot signatures, and initial audit events during the migrations.
- Public verification is available at `GET /api/receipts/verify/{token}`. Browser requests receive a minimal HTML page; JSON clients receive a minimal JSON summary.
- Print HTML and PDF output embed a locally generated SVG QR code. No external QR image service is used.

## Dependency and lockfile

This isolated workspace has no Composer executable, `vendor/` directory, or network access, so `composer.lock` could not be regenerated and the test suite could not be executed here. Before running the app, run this in the backend project on a machine with Composer/network access:

```bash
composer update bacon/bacon-qr-code --with-dependencies
```

Commit the resulting `composer.lock` change with the implementation.

## Deployment checklist

1. Back up the production database before applying migrations.
2. Confirm `APP_KEY` is configured and will remain stable. Changing it invalidates existing receipt signatures and audit HMACs.
3. Update dependencies and ensure `composer.lock` is current.
4. Run migrations in staging first: `php artisan migrate`.
5. Run the receipt and credit feature tests, then the full test suite.
6. Verify a normal sale receipt, a credit-sale receipt, and multiple credit repayment receipts in HTML print and PDF output.
7. Scan the QR codes from a phone and confirm that modified snapshots fail verification.
8. Restrict the production database role so it cannot disable triggers or alter the audit table. The trigger is defense in depth; a database superuser can bypass application-level protections.

## Test commands

```bash
php artisan test --filter=Receipt
php artisan test --filter=Credit
php artisan test
```
