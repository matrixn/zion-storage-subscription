# Legal publication and operating decisions — 2026-10-19

Operator details are in config/operator.php. The twelve plain-text documents are under legal/{ro,en,fr,de}/{terms,privacy,cookies}.txt and rendered escaped on the existing legal routes. Language links preserve the selected document. The provided identity is PFA RĂDUŢĂ ALEX-RADU, CUI 49490924, F38/36/2024; contact and outgoing sender use contact@zion3d.ro. Configure the SMTP account to authorize that sender. SMTP authentication/provider API login addresses are not rewritten.

The owner confirmed Romania-hosted personal server, RAID, own email hosting, Cloudflare proxy, metadata maps, no facial recognition, no CAPTCHA/Analytics, adults 18+ and a thirty-day post-paid-service export period. RAID is described as redundancy, never as an independent backup. The map provider and Cloudflare features must remain consistent with the deployed Immich/Cloudflare configuration; record actual map domains and any changes. Adding analytics/CAPTCHA or independent backups requires policy review before enabling.

## Publication and operations

Run php bin/console.php migrate. LEGAL_READY remains an explicit deployment setting: enable it only after the owner reviews all twelve documents, SMTP receipt delivery works, the actual provider/transfer arrangements are verified and the procedures below are staffed. Readiness now checks that all twelve documents exist. It is not legal certification. Obtain legal/accounting review for international B2C/B2B supply and professional processing agreements.

The operator is declared not VAT registered. An unsaved tax setting now defaults to 0, but existing saved rates, open Stripe quotes and active subscriptions are not automatically modified by document publication. Before LIVE checkout, set the correct rate under Admin → TVA produse and reconcile any old taxable subscription separately with the accountant. A Romanian non-registration declaration does not by itself settle every overseas digital-service tax obligation. The application remains a fixed-rate billing system, not an international tax engine.

The thirty-day export/deletion rule is an operator procedure, not an enabled destructive worker. Billing only limits uploads/access; no automatic deletion of LIVE files has been added. Track the actual end of paid entitlement, notify the client, ensure export/support access and initiate verified deletion after thirty days. Do not apply a new destructive retention rule retroactively without appropriate notice/contract basis. Active legitimate free-plan files are separate. Fiscal/withdrawal evidence retention is separate from media deletion. If a legal hold is needed, leave the withdrawal unresolved and document the hold; only resolved withdrawal records are cleaned after three years by maintenance. Configure maintenance/worker schedules as documented elsewhere.

## Consumer withdrawal

The footer and terms link to contact?request=withdrawal. This is not Stripe's cancel-renewal button. The customer supplies name, contract identification and confirmation email, then explicitly confirms withdrawal; no account login or reason is required. CSRF, contact rate limits, optional CAPTCHA and honeypot guards apply. A downloadable text receipt includes the statement, reference and timestamp (Europe/Bucharest). Confirmation/operator emails are queued atomically with the encrypted request record; run mail:work promptly and monitor failures.

Admin → Management → Retrageri consumatori lists declarations and lets the operator record resolution. The form/resolve action never automatically cancels, charges or refunds a Stripe subscription. Staff must identify the contract, assess mandatory deadlines, process the appropriate Stripe actions and confirm the outcome. No universal non-refund clause is used: the free trial does not remove withdrawal or conformity remedies. The current signup does not collect an express request for early performance or blanket waiver of withdrawal; do not deduct a proportional charge without satisfying the legal prerequisites.

New signup acceptance includes an adult declaration and stores terms/privacy version identifiers. The privacy checkbox acknowledges reading; it is not blanket GDPR consent. A separate Article 28 processing agreement must be established before professional uploads on behalf of other controllers; the privacy notice is not a DPA.

## Sources checked

- GDPR, especially Articles 6, 12–22, 28 and Chapter V: https://eur-lex.europa.eu/legal-content/RO/TXT/?uri=CELEX:32016R0679
- OUG 34/2014 consolidated, including Article 11^1 online withdrawal applicable from 19 June 2026: https://legislatie.just.ro/Public/DetaliiDocument/158913
- OUG 141/2021 on digital content/services: https://legislatie.just.ro/Public/FormaPrintabila/00000G0B1RD4AHD7FVE3SGMUQRWMXOG5
- Law 506/2004 Article 4 cookie rules: https://legislatie.just.ro/Public/DetaliiDocumentAfis/214211
- Accounting Law 82/1991 Article 25, five-year accounting retention: https://legislatie.just.ro/Public/FormaPrintabila/00000G1EY27G23BYWA0184TN789FISAV
- Cloudflare cookies: https://developers.cloudflare.com/fundamentals/reference/policies-compliances/cloudflare-cookies/
- Stripe cookies: https://stripe.com/legal/cookies-policy

These sources and translations support drafting; commercial/tax status and deployment facts remain the operator's responsibility. No real refund, subscription change, customer deletion or external email was performed in development.
