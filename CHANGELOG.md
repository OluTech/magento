# Changelog

## [[1.7.2]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- **Billing address on tokenised payments** — the Magento order billing address is now included in every CC Sale –
  Tokenised and CC AuthOnly – Tokenised request sent to Fortis, including for tokens imported from other gateways that
  may lack billing data.
- **Billing address fallbacks** — when resolving billing address data the plugin prefers the order address, then the
  quote billing address, then the shipping address, and finally the customer’s saved default billing address. Missing
  address data is non-blocking so tokenised transactions still process successfully.
- **3D Secure documentation** — documented the Elements iframe 3D Secure authentication behaviour in the module README,
  including automatic enablement, in-iframe challenges, and checkout error handling.

### Fixed

- **Missing billing address on tokenised transactions** — resolved cases where tokenised Sale and AuthOnly requests
  omitted billing address details because the flow assumed the token already carried complete address data.
- **Post-checkout order recovery** — hardened redirect and success controllers so a missing or expired checkout session
  can still recover the order (including ticket-intention flows that now pass the order identifier), preventing orders
  completing without Fortis transaction data.
- **Null-safe payment data access** — redirect payment processing no longer assumes payment additional information is
  always present, reducing failures when payment data is incomplete.
- **Level 3 commodity_code field truncation** — `commodity_code` in Level 3 line items is now truncated to 12 characters
  to prevent Visa Level 3 validation errors (412 status codes) when product attributes exceed gateway limits.

### Improved

- **Saved payment method error handling** — tokenised ACH and card failures now restore the quote, surface a clear
  customer-facing message, and redirect back to the cart instead of returning raw exceptions.
- **Expired checkout session recovery** — when the last real order is missing from the checkout session the customer is
  redirected to the cart with a clear session-expired message rather than failing mid-redirect.

## [[1.7.1]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Fixed

- Truncated Fortis Level 3 line-item fields before API submission to meet gateway validation limits:
    - `level3_data.line_items[*].description` is now capped at 26 characters.
    - `level3_data.line_items[*].product_code` is now capped at 12 characters.

## [[1.7.0]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- **Multi-currency pricing support** — products and checkout can now display and accept payments in multiple currencies.
  This enables storefronts to present localized prices and process transactions in a customer's selected currency where
  configured. Includes admin configuration for primary and secondary currencies, each mapped to a distinct Product
  Transaction ID.
- **Extended currency support** — added ARS, AUD, BRL, CAD, CLP, COP, PYG, INR, MXN, ILS, NZD, PEN, PHP, GBP, SGD, KRW,
  and JPY to the list of accepted payment currencies (in addition to the existing USD, EUR, and ZAR).
- **Configuration validation for multi-currency setup** — payment configuration now validates primary and secondary
  Product Transaction ID and currency pairings against the Fortis API when settings are saved, helping merchants catch
  invalid multicurrency setup before checkout is affected.
- **Transaction verification** — introduced a `TransactionVerifier` service that validates transaction integrity before
  finalising orders in the Authorise, Success, and ACH webhook controllers. Mismatched or unverifiable transactions are
  rejected and logged as critical security events.
- Currency validation at checkout — unsupported currencies are now rejected early in the surcharge calculation,
  tokenized payment, ticket transaction, and redirect payment flows, returning a clear error message to the customer.
- Support for Adobe Commerce / Magento 2.4.8 and PHP 8.4 compatibility.
- Platform code-quality updates: added return-type declarations and typed properties throughout the codebase to improve
  compatibility with platform tooling (linting, typing, and static analysis).

### Fixed

- Fixed billing phone validation error that occurred when the phone field was left empty during payment processing.
- Resolved tokenized payment failures that were triggered by discounted orders.
- Fixed failed payment error responses for tokenised and ticket-transaction flows — these now return a structured JSON
  error with an HTTP 403 status code instead of silently redirecting.
- Improved cart and quote recovery after failed or declined payments so checkout state is restored more reliably,
  including refreshed cart/checkout sections after redirect-based payment flows.
- Fixed intermittent checkout session validation issues during surcharge calculation and Fortis API requests that could
  invalidate the customer session mid-checkout.
- Corrected Level 3 transaction payload formatting for Fortis API requests, including monetary amounts, shipping origin
  ZIP handling, and default line-item values.
- Corrected `Transaction::TYPE_ORDER` / `TYPE_CAPTURE` references to use `TransactionInterface` constants for
  compatibility with recent Magento versions.

## [[1.6.2]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Fixed

- Resolved security vulnerabilities identified in scans.
- Fixed AuthComplete error during invoice generation when discount codes are applied.
- Resolved errors in tokenized payment processing when applying discount codes.

## [[1.6.1]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Fixed

- Fixed a cart page error that occurred when using Payment then Order Intention Flow with an empty product ID field.

## [[1.6.0]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- Added ACH payment authorization prompt for customers to provide explicit consent, protecting merchants from future
  charge disputes.

### Fixed

- Resolved compatibility issues with Magento 2.4.6-p6
- Removed the requirement for users to provide phone numbers during payment processing.
- Limited street address input to 32 characters to prevent payment submission errors.

## [[1.5.0]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- Introduced ticket intention payment flow.

## [[1.4.1]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- Support for Adobe Commerce 2.4.8 and Magento Open Source 2.4.8.
- Support for PHP 8.4.

## [[1.4.0]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- Introduced surcharges.
- Load Commerce.js source script according to the Test Mode setting.

### Fixed

- Fixed floating-point arithmetic errors on specific amounts.

## [[1.3.1]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Fixed

- Resolved a console error caused by invalid credentials.
- Added clear indicators for invalid configurations to guide users effectively.
- Refactored the transaction void endpoint to use a single, consistent function for improved reliability.
- Remove the ability for Pending Payment orders to Capture Online.
- Set initial new order status to "Pending Payment".
- Set initial ACH order status to "On Hold".

## [[1.3.0]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Changed

- Refactored deprecated `AbstractMethod` and `ArrayInterface` classes.
- Updated 'object'->save () methods to remove deprecated usage.
- Replaced inheritance with composition for improved code design.
- Upgraded `curl_init` to Magento's HTTP classes for better integration.
- Enhanced general code quality standards and adhered to modern best practices.
- Added a full MFTF test suite for improved testing coverage.

## [[1.2.1]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Fixed

- Issues with content security policy.
- Fixed back to cart (cancel) button order not found error.
- Fixed error with a credit memo on a captured order.

## [[1.2.0]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- “Continue Shopping” / Cancel order capability.
- Refactored inline payment script.
- Compatible with Adobe Commerce (cloud) : 2.4.7.
- Compatible with Adobe Commerce (on-prem) : 2.4.7.
- Compatible with Magento Open Source : 2.4.7.

### Fixed

- Resolved issue when cancelling auth-only orders.
- Fixed PHP 8.2 logical errors.

## [[1.1.0]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- Customisable place order button text.
- Concealable token payment options dropdown on order creation.

### Fixed

- Compatibility with virtual products.

## [[1.0.3]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- Support for Google Pay and Apple Pay.

### Fixed

- Corrected an issue with a misplaced component during checkout for a specific theme.
- Resolved a bug that affected partial refunds on orders with a complete status.

## [[1.0.2]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- Enhanced Stored Payment Methods.

### Fixed

- Minor bug fixes.

## [[1.0.1]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- ACH as a payment method.
- Webhook for ACH payment status update.
- Credit Card Level 3 data support.
- Payment iframe position and layout options.
- Compatible with Adobe Commerce (cloud) : 2.4.
- Compatible with Adobe Commerce (on-prem) : 2.4.
- Compatible with Magento Open Source : 2.4.

### Fixed

- Minor bug fixes.

## [[1.0.0]](https://commercemarketplace.adobe.com/fortispay-magento-2-payment-gateway.html#product.info.details.release_notes)

### Added

- Initial release.
- Compatible with Magento Open Source : 2.4.

