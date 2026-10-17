# Pricing component and Stripe configuration

The home-page pricing section remains at `/{locale}/#pricing`. There is no additional public pricing route. The four plans, quotas, monthly RON minor units, recommendation flag and feature keys come from `config/plans.php`: Free 10 GB / 0, Personal 120 GB / 999, Plus 250 GB / 1799, Family 500 GB / 2999. Personal remains recommended.

`templates/pricing.php` renders the component, using the existing server-side localization system and `translations/pricing.php`. `public/assets/pricing.css` extends the current dark/lime identity only on home. The existing small script progressively enables a keyboard-accessible range control; the initial illustrative comparison and every price/CTA remain readable without JavaScript. All motion respects `prefers-reduced-motion`. The comparison uses a hypothetical 100 GB shared service with 65 GB media, 20 GB files and 15 GB email, explicitly labelled as illustrative. Zion's comparison capacity is read from the recommended plan. No provider affiliation, statistics, family sharing or end-to-end encryption is implied.

## Required manual Stripe action

The new Plus amount is **17.99 RON/month**, replacing the earlier catalog amount of 19.99 RON. Set `STRIPE_PRICE_250GB` to a Stripe Price with all these properties:

- Currency RON; integer amount **1799**.
- Recurring interval **month**, interval count **1**.
- The correct Plus product, in the same Stripe mode/account as the configured secret key.

Before replacing the current ID, copy the former 1999 Price ID into `STRIPE_LEGACY_PRICE_250GB` (comma-separated if there are multiple historic IDs). Legacy IDs are accepted only when recognizing subscription events and invoice line plans; new Checkout still selects only `STRIPE_PRICE_250GB`. This preserves old customers' paid status and actual fiscal amounts without repricing them.

Do not retain the former 1999 Price ID as the current Price when enabling new Plus purchases. Stripe Checkout uses the configured Price ID, not the HTML amount; a stale ID would charge the old amount. Allow previously opened subscription/upgrade Checkout sessions to expire, or explicitly cancel them through the existing operator workflow before offering a fresh quote: they retain their original amounts and this redesign does not mutate them. Verify the hosted Checkout total before enabling purchases. This release explicitly reports the unresolved configuration rather than modifying prices, credentials, existing subscriptions, payment records or fiscal documents. The existing release still accepts only Stripe TEST MODE keys/events.

At local validation, `STRIPE_SECRET_KEY` and all three paid Price IDs were empty. Actual hosted Stripe totals could therefore not be verified. No live Stripe operation or credential change was performed. Use Stripe's dashboard/API manually to select or create the matching test Price and update private `.env`; a live billing launch remains a separately authorized operation.

Existing customers remain on their existing Stripe subscriptions/Prices, recognized through the legacy configuration. Changing the marketing catalog does not migrate them. The existing conservative upgrade validation may reject a legacy subscription whose current Price no longer matches the new-purchase Price; handle such changes as an explicit operator migration, not a hidden repricing. With matching current Prices, the new Personal → Plus difference is **800 minor units / 8 RON**. The upgrade tests use the new amount. Fiscal names, periods and amounts continue to use the existing selected-plan and confirmed-payment pipeline.

## Capability and validation references

Advertised automatic media backup and Android/iOS support are described in [Immich mobile backup](https://docs.immich.app/features/mobile-backup/). Original unmodified files are described in [Immich backup and restore](https://docs.immich.app/administration/backup-and-restore/). Actual mobile/background behavior still depends on the installed app, selected albums and OS scheduling; the cards do not promise instantaneous background uploads. Family is a commercial plan name, without a shared-account or shared-quota promise.

Run `composer test`, `python3 tests/http_flow.py` and `node tests/pricing-browser.cjs` (Playwright/Chrome required; set PLAYWRIGHT_MODULE/BROWSER_CHANNEL/TEST_URL as needed). The pricing browser suite checks all four locales at 320/360/390/430/768/1024/1440/1920 px, four registration CTA targets, price/capacity formatting, recommended state, feature counts, button alignment, overflow, keyboard interaction, animated bar proportions, no-JavaScript fallback, reduced motion, canonical/hreflang and the unchanged 16-URL sitemap. Ignored visual artifacts are `tests/artifacts/pricing-redesign-*.png`.
