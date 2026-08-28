# Saudi compliance and payment research notes

## SAMA — Rules for Dealing with E-Commerce Payment Service and Support Providers
Source: https://rulebook.sama.gov.sa/en/rules-dealing-e-commerce-payment-service-and-support-providers
Observed page status: In-Force; document number 46004436; dated 24/7/2024.
Key points: SAMA states that linkage and technical support for e-commerce payment services are supporting services that do not require a SAMA license at this time, but companies must obtain necessary technical permits from Saudi Payments and limit their activity to linkage/technical support. The text excludes directly contracting/registering merchants and performing KYC/AML/CFT/fraud verification, and excludes final settlement/depositing funds into the merchant bank account. Banks and payment service providers may contract with technical providers subject to outsourcing and MADA operational/technical requirements.

## ZATCA — Digital Invoice System
Source: https://zatca.gov.sa/en/E-Invoicing/Pages/default.aspx
The official page was opened but returned no extracted text in the browser session; do not infer specific implementation details from the blank extraction. Search results identified official ZATCA pages for What is E-invoicing, Laws and Regulations, and E-invoice specifications; these require follow-up retrieval before implementation claims.

## Moyasar official developer documentation
Source: https://docs.moyasar.com/
Observed: Moyasar documentation says its sandbox simulates payment-network responses for the full payment cycle; API keys are obtained from its dashboard; web payments support mada, Visa, Mastercard, UnionPay, and Apple Pay; it lists web, mobile SDKs, Payments API, Invoices API, and testing cards.

## Moyasar Webhook Reference
Source: https://docs.moyasar.com/api/other/webhooks/webhook-reference
Observed: Payment events include payment_paid, payment_faild (spelled this way in the source), payment_refunded, payment_voided, payment_authorized, payment_captured, and payment_verified. Webhook recipients should return a 2xx quickly; the source documents up to five retries after the initial delivery. Webhook object has a unique id, type, created_at, secret_token, live flag, and data payload. These facts support an idempotent event table and secret validation in the project, but exact signature semantics require checking the provider's webhook setup/authentication page before implementation.

## Implementation caution
No production provider credentials, merchant account, API key, secret, or webhook secret are available in the project context. Do not enable live payment or OTP delivery and do not fabricate credentials. Saudi compliance, merchant onboarding, licensing, VAT/e-invoicing applicability, and contracts remain external business/legal steps even after code integration.

## HyperPay official integration guide
Source: https://www.hyperpay.com/integration-guide/
Observed: HyperPay presents a unified API and documents Copy and Pay (browser widget sending sensitive payment data directly to the payment platform), Server-to-Server integration, HyperSplits transfers, HyperBill invoicing, and mobile SDKs. The page itself did not expose a complete Saudi method matrix; exact mada/Apple Pay/webhook/refund details require the linked technical docs and merchant enablement.

## PayTabs official technical portal
Source: https://docs.paytabs.com/
The portal loaded without extractable page text in the browser session. Search results located official PayTabs pages for Apple Pay test profiles and refund transactions. Do not claim full capabilities without retrieving the relevant official endpoint pages.

## Provider comparison status
Moyasar has the clearest publicly accessible official documentation for this project at this stage: sandbox, API-first payments, web form, mobile SDKs, mada, Visa, Mastercard, Apple Pay, webhooks, and refunds are explicitly visible in its official docs. HyperPay is a viable second candidate with unified API and hosted/widget/server-to-server paths, but capability and merchant-region enablement must be confirmed with its onboarding team. PayTabs remains a candidate pending direct verification of the required Saudi methods and webhook/authentication details.

## Local project baseline (read-only audit)
Audited local database through the existing bootstrap connection without exposing credentials or PII. Current counts: currencies 2, exchange_rates 1, bookings 11, payments 8, refunds 0, banks 1, accounts 1, account_transactions 4, agent_wallet_transactions 3, trip_display_settings 1.

Current currencies: YER is the only default currency; TST is an active non-default test currency. SAR is not currently present, so adding SAR must be an additive, reviewed migration and must not silently convert historical YER/TST amounts.

Current payment columns: booking_id, currency_id, amount, payment_method, payment_channel, bank_id, status, reference_number, receipt_image_path, received_by_user_id, created_at. Existing payment methods/statuses are insufficient for external gateway order/transaction IDs, idempotency, webhook events, provider fees, and partial refunds.

Current booking columns already include currency_id, exchange_rate_used, separate booking/payment status, held_until, company cost, platform commission, and agent commission. BookingService already locks trip/seat rows and expires pending holds, but payment flow currently supports only cash/bank transfer/wallet-style internal channels; no external provider abstraction, server-to-server verification, webhook endpoint, or real refund execution was found in the inspected API routes.

No refunds have been recorded in the local database. Existing refunds table is minimal and lacks provider refund identifiers/status/amount aggregation safeguards.

Implementation boundary: do not enable live Saudi payment or alter the default currency until the merchant's business, tax, provider account, and credentials are supplied and verified. Preserve all historical currency and financial records.

## Official source review — 28 Aug 2026

### ZATCA
ZATCA's official e-invoicing introduction page defines e-invoicing as structured electronic issuance/exchange/processing of invoices, credit notes, and debit notes. It distinguishes tax invoices (usually B2B) and simplified tax invoices (usually B2C). It states that Phase One generation became mandatory from 4 Dec 2021 for taxpayers subject to VAT (excluding non-resident taxpayers), and Phase Two integration began in waves from 1 Jan 2023. Source: https://zatca.gov.sa/en/E-Invoicing/Introduction/Pages/What-is-e-invoicing.aspx (reviewed 28 Aug 2026).

The official laws page links the E-Invoicing Regulations and the 19 May 2023 controls, requirements, technical specifications, and procedural rules. Source: https://zatca.gov.sa/en/E-Invoicing/Introduction/LawsAndRegulations/Pages/default.aspx (reviewed 28 Aug 2026).

The official specifications page links the Electronic Invoice Data Dictionary and the Electronic Invoice XML Implementation Standard dated 19 May 2023. Source: https://zatca.gov.sa/en/E-Invoicing/SystemsDevelopers/Pages/E-Invoice-specifications.aspx (reviewed 28 Aug 2026).

Technical implication: Rihla may store immutable invoice/tax snapshots and generate a customer-facing invoice, but it must not claim ZATCA compliance or enable integration merely from adding fields. Taxpayer eligibility, phase/wave, legal entity data, certificate/onboarding, and an accountant/tax adviser review remain external prerequisites.

### Moyasar
Official Moyasar invoice documentation supports a hosted payment page: the server creates an invoice with amount in the smallest currency unit, ISO currency, description, callback_url, success_url, back_url, and expiry; the response includes a hosted checkout URL and invoice status. Source: https://docs.moyasar.com/api/invoices/01-create-invoice (reviewed 28 Aug 2026).

Official Moyasar idempotency documentation states that payment creation accepts a UUID v4 in given_id and returns the same payment identity on safe retries; reusing it for a different payment is rejected. Source: https://docs.moyasar.com/api/idempotency (reviewed 28 Aug 2026).

Official payment creation documentation lists card, Apple Pay, Samsung Pay, and STC Pay sources, requires callback_url for card/token payments, and documents 3DS/tokenization options. Source: https://docs.moyasar.com/api/payments/01-create-payment (reviewed 28 Aug 2026).

Official refund documentation supports full or partial refunds by POST /payments/:id/refund with an optional amount; the response exposes status, captured, refunded, fee, and provider metadata. Source: https://docs.moyasar.com/api/payments/05-refund-payment (reviewed 28 Aug 2026).

Design decision: use a provider-neutral adapter and implement Moyasar as the first optional adapter, disabled until server-side merchant credentials and onboarding are supplied. Hosted invoice is preferred for the initial safe card flow because Rihla should not receive PAN/CVV; provider-side method availability (mada/Apple Pay/etc.) remains subject to the merchant account/device/domain configuration.

## Implementation checkpoint — 28 Aug 2026
- Added additive migration `database/saudi_payment_foundation_migration.sql` and appended its definitions to the reference `database/schema.sql`. It adds SAR without making it default, tax snapshot fields, gateway settings, payment attempts, webhook events, refunds metadata, tax settings, invoices, and invoice lines. Re-running the migration locally completed without duplicating SAR or default rows.
- Added server-side `SecretVault` (AES-256-GCM) and a provider contract plus Moyasar hosted-invoice adapter. No card/PAN/CVV data is accepted or stored. Provider activation remains disabled until real merchant credentials, webhook secret, encryption key, and onboarding are supplied.
- Added `PaymentService`, API routes for hosted checkout/status/return/webhook/refund, idempotency checks, provider/environment tracking, and an RTL admin payments/settings surface. Manual YER payment rows remain unchanged and refund controls are disabled without a provider payment ID.
- Added configurable VAT/tax settings with no assumed rate, immutable booking tax snapshot fields, internal invoice snapshot issuance after verified paid state, and admin invoice listing. ZATCA integration remains intentionally disabled.
- Local tests passed: PHP lint, JavaScript syntax check, health/payment-options smoke test, webhook valid/invalid secret unit test, 15% VAT transactional calculation and invoice issuance with rollback, and migration idempotency. Baseline remained bookings=11, payments=8, refunds=0; no permanent test booking, payment, invoice, or refund was created.
- Remaining external blockers: merchant/provider choice and contract, sandbox/live keys, webhook secret, server encryption key, allowed payment methods (including mada/Apple Pay), public HTTPS callback domain, and Saudi tax/legal review. Technical implementation is not a Saudi PSP license, ZATCA approval, or tax/legal advice.
