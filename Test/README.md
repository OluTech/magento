# Fortis Magento 2 Payment Extension - Unit Test Suite

## Overview

This test suite provides comprehensive unit test coverage for the Fortis payment extension's core business logic, including:

- **3-way surcharge fallback** (TransactionVerifier)
- **ACH-vs-CC product ID selection** (Config)
- **Multi-currency branch logic** (Config)
- **Surcharge application and refunds** (MagentoOrderService)
- **Payment cart calculations** (Cart)
- **Payment data assignment** (PaymentDataAssignObserver)

## Test Coverage (Tier 1) - 100% Passing (64/64 tests)

### Service Layer - 21/21 tests passing
- **TransactionVerifierTest** - 10/10 tests
  - 3-way surcharge fallback (API response → nested object → client-supplied)
  - Transaction verification (amount, description matching)
  - APM (PayPal) transaction handling
  - API fetch failure scenarios
  - Multiple error detection

- **MagentoOrderServiceTest** - 11/11 tests 
  - Surcharge application (with/without invoice)
  - Cents-to-currency conversion and rounding
  - Refund data updates (partial/full)
  - ACH refund surcharge handling (regression test for bug #15577)
  - Edge cases (zero, negative, invalid amounts)

### Model Layer - 29/29 tests passing
- **ConfigTest** - 20/20 tests 
  - ACH-vs-CC product ID selection
  - Multi-currency product ID routing (USD → INR)
  - Currency fallback logic
  - Method activation checks
  - Currency support validation
  - ACH exclusion from multi-currency (regression protection)

- **CartTest** - 9/9 tests 
  - Amount validation and calculation overrides
  - Negative item detection
  - Discount validation (discount < subtotal)
  - Precision matching (sprintf %.4F logic)
  - Item aggregation workarounds
  - Tax compensation handling
  - Parent item handling

### Observer Layer - 14/14 tests passing
- **PaymentDataAssignObserverTest** - 14/14 tests
  - Vault method data assignment
  - Surcharge data handling
  - Payment type assignment (CC, ACH)
  - Combined data field assignment
  - Edge cases (null, empty, invalid data)

## Running the Tests

### Prerequisites

**IMPORTANT**: Full test suite (64 tests) must be run from the installed Magento location, not from the workspace repository. The tests require Magento's autoloader to access framework classes.

**CI/CD Note**: GitLab CI runs only the pure unit tests (TransactionVerifier - 10 tests) that don't depend on Magento framework. Full test suite should be run locally in Magento environment.

```bash
# Navigate to installed module in Magento
cd /path/to/magento/app/code/Fortispay/Fortis

# Install test dependencies (if not already installed)
composer install
```

For example:
```bash
cd /Users/developer/Sites/magento.local/app/code/Fortispay/Fortis
```

### Run All Tests

```bash
# From the installed module directory
vendor/bin/phpunit

# With detailed output
vendor/bin/phpunit --testdox
```

### Run Specific Test Suite

```bash
# Service tests only
vendor/bin/phpunit Test/Unit/Service/

# Model tests only
vendor/bin/phpunit Test/Unit/Model/

# Observer tests only
vendor/bin/phpunit Test/Unit/Observer/

# Specific test class
vendor/bin/phpunit Test/Unit/Service/TransactionVerifierTest.php

# Filter by test name
vendor/bin/phpunit --filter testVerifyTransactionWithSurchargeTier1RootLevel
```

### Run with Coverage Report

```bash
vendor/bin/phpunit --coverage-html coverage/
```

View coverage report: `coverage/index.html`

### CI/CD vs Local Testing

**GitLab CI (Automated):**
- Runs 10 pure unit tests (TransactionVerifier only)
- Tests don't require Magento framework classes
- Fast, isolated, no dependencies
- Runs on every commit

**Local Testing (Full Suite):**
- Runs all 64 tests
- Requires Magento installation (needs framework classes for mocking)
- Must run from: `/path/to/magento/app/code/Fortispay/Fortis`
- Run before merge to verify full coverage

**Why the difference?**  
Tests like Config, Cart, and Observer mock Magento framework interfaces (`ScopeConfigInterface`, `Order`, etc.) which require Magento's autoloader. TransactionVerifier tests only mock local classes (`FortisApi`, `Config`) and run in isolation.

### CI/CD Integration

Tests are automatically run in GitLab CI pipeline:

```yaml
# .gitlab-ci.yml (CI runs TransactionVerifier only)
unit_tests:
  stage: test
  script:
    - cd Fortispay/Fortis
    - composer install --no-interaction
    - vendor/bin/phpunit --testdox --filter TransactionVerifier  # 10 tests
```

**Before merging:** Always run the full test suite locally:
```bash
cd /path/to/magento/app/code/Fortispay/Fortis
vendor/bin/phpunit --testdox  # All 64 tests
```

## Test Structure

```
Fortispay/Fortis/Test/Unit/
├── bootstrap.php
├── Service/
│   ├── TransactionVerifierTest.php
│   └── MagentoOrderServiceTest.php
├── Model/
│   ├── ConfigTest.php
│   └── CartTest.php
└── Observer/
    └── PaymentDataAssignObserverTest.php
```

## UAT Test Case Coverage

The tests map directly to the UAT scenarios from `UAT 1_7_2 08102026.xlsx`:

### Payment Then Order - Surcharge Off
- Guest-Approved CC Sale (Order 1773)
- Guest-Declined CC Sale (Order 2505)
- Account-Approved CC Sale (Order 1774)
- Account CC Token Sale (Order 1775)

### Payment Then Order - Surcharge On
- Guest-Approved CC Sale with Surcharge (Order 1782)
- Surcharge calculation and application
- Surcharge refund handling

### Multi-Currency (INR)
- Primary currency (USD) product ID selection
- Secondary currency (INR) product ID selection (Order 1843)
- Fallback to primary when secondary not configured

### ACH Transactions
- ACH Debit - Guest (Order 1819)
- ACH Debit - Account (Order 1820)
- ACH refund product ID selection (regression for bug #15577)

### Refunds
- Full refund with surcharge
- Partial refund with surcharge (Order 1738)
- Credit memo adjustments

## Regression Tests

### Bug #15509 - Deleted Token 404 Redirect
Protected by: `PaymentDataAssignObserverTest::testExecuteAssignsTokenizedPaymentData()`

### Bug #15510 - Visa L3 Commodity Code Truncation
Protected by: Future integration test (noted in MFTF backlog)

### Bug #15577 - ACH Refund Using CC Product ID
Protected by:
- `ConfigTest::testCcProductIdWhenAchIsActive()`
- `ConfigTest::testAchDoesNotUseCurrencySelection()`
- `MagentoOrderServiceTest::testUpdateOrderRefundDataAchSurchargeScenario()`

### Intentional Behavior - ACH Excludes Multi-Currency
Protected by:
- `ConfigTest::testAchDoesNotUseCurrencySelection()`
- Documents that ACH should NOT use `getProductIdForCurrency()`

## Adding New Tests

1. Create test class in appropriate directory:
   - `Test/Unit/Service/` for service classes
   - `Test/Unit/Model/` for model classes
   - `Test/Unit/Observer/` for observer classes

2. Extend `PHPUnit\Framework\TestCase`

3. Follow naming convention: `{ClassName}Test.php`

4. Use descriptive test method names: `testMethodName{Scenario}()`

5. Mock dependencies using PHPUnit's `createMock()` and `createConfiguredMock()`

## Continuous Integration

The test suite is designed to run in CI/CD pipelines. Recommended `.gitlab-ci.yml` configuration:

```yaml
unit_test:
  stage: test
  script:
    - cd Fortispay/Fortis
    - composer install
    - vendor/bin/phpunit
  coverage: '/^\s*Lines:\s*\d+\.\d+\%/'
```

## Known Limitations

### Out of Scope (Tier 2+)
The following are explicitly out of scope for this Tier 1 unit test suite:

- **Controller execute() methods** (Success.php, Authorise.php, AchHook.php)
  - Require MFTF/integration tests due to high dependency count (19-27 dependencies)
  
- **FortisMethodService** 
  - Filesystem I/O in constructor (checkApplePayFile)
  - Complex API integration logic
  - Recommended for integration testing

- **Webhook observers** (DeleteSavedVault, SetupWebhook)
  - External API calls
  - Require integration test environment

## Test Maintenance

### When Modifying Production Code

1. **Run tests first**: `vendor/bin/phpunit`
2. **Update tests** if business logic changes
3. **Add new tests** for new features
4. **Ensure all tests pass** before committing

### When Adding New Features

1. **Write tests first** (TDD approach recommended)
2. **Test edge cases** (null, empty, invalid input)
3. **Test error paths** (exceptions, validation failures)
4. **Document UAT mapping** in test docblocks

## References

- PHPUnit Documentation: https://phpunit.de/documentation.html
- Magento Testing Guide: https://developer.adobe.com/commerce/testing/guide/
- UAT Test Cases: `UAT 1_7_2 08102026.xlsx`
- Jira Story: SCRM-24096