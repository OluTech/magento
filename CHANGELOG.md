# Changelog

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
- Updated 'object'->save() methods to remove deprecated usage.
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

