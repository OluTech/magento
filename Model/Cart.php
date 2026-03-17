<?php

namespace Fortispay\Fortis\Model;

use Magento\Payment\Model\Cart\SalesModel\SalesModelInterface;

class Cart extends \Magento\Payment\Model\Cart
{
    /**
     * @var bool
     */
    private bool $areAmountsValid = false;

    /**
     * Get shipping, tax, subtotal and discount amounts all together
     *
     * @return array
     */
    public function getAmounts(): array
    {
        $this->_collectItemsAndAmounts();

        if (!$this->areAmountsValid) {
            $subtotal = $this->getSubtotal() + $this->getTax();

            if (empty($this->_transferFlags[self::AMOUNT_SHIPPING])) {
                $subtotal += $this->getShipping();
            }

            if (empty($this->_transferFlags[self::AMOUNT_DISCOUNT])) {
                $subtotal -= $this->getDiscount();
            }

            return [self::AMOUNT_SUBTOTAL => $subtotal];
        }

        return $this->_amounts;
    }

    /**
     * Check whether any item has negative amount
     *
     * @return bool
     */
    public function hasNegativeItemAmount(): bool
    {
        return !empty(
            array_filter(
                $this->_customItems,
                fn($item) => $item->getAmount() < 0
            )
        );
    }

    /**
     * Calculate subtotal from custom items
     *
     * @return void
     */
    protected function _calculateCustomItemsSubtotal(): void
    {
        parent::_calculateCustomItemsSubtotal();
        $this->applyDiscountTaxCompensationWorkaround($this->_salesModel);

        $this->validateAmounts();
    }

    /**
     * Validate
     *
     * @return void
     */
    private function validateAmounts(): void
    {
        $areItemsValid         = false;
        $this->areAmountsValid = false;

        $referenceAmount = $this->_salesModel->getDataUsingMethod('base_grand_total');

        $itemsSubtotal = 0;
        foreach ($this->getAllItems() as $i) {
            $itemsSubtotal = $itemsSubtotal + $i->getQty() * $i->getAmount();
        }

        $sum = $itemsSubtotal + $this->getTax();

        if (empty($this->_transferFlags[self::AMOUNT_SHIPPING])) {
            $sum += $this->getShipping();
        }

        if (empty($this->_transferFlags[self::AMOUNT_DISCOUNT])) {
            $sum -= $this->getDiscount();
            //
            $this->areAmountsValid = round($this->getDiscount(), 4) < round($itemsSubtotal, 4);
        } else {
            $this->areAmountsValid = $itemsSubtotal > 0.00001;
        }

        /**
         * Numbers are intentionally converted to strings by reason of possible comparison error
         */
        // match sum of all the items and totals to the reference amount
        if (sprintf('%.4F', $sum) == sprintf('%.4F', $referenceAmount)) {
            $areItemsValid = true;
        }

        $areItemsValid = $areItemsValid && $this->areAmountsValid;

        if (!$areItemsValid) {
            $this->_salesModelItems = [];
            $this->_customItems     = [];
        }
    }

    /**
     * Import items from sales model with workarounds
     *
     * @return void
     */
    protected function _importItemsFromSalesModel(): void
    {
        $this->_salesModelItems = [];

        foreach ($this->_salesModel->getAllItems() as $item) {
            if ($item->getParentItem()) {
                continue;
            }

            $amount = $item->getPrice();
            $qty    = $item->getQty();

            $subAggregatedLabel = '';

            // Workaround in case item subtotal precision is not compatible
            if ($amount - round($amount, 2)) {
                $amount             = $amount * $qty;
                $subAggregatedLabel = ' x' . $qty;
                $qty                = 1;
            }

            // Aggregate item price if item qty * price does not match row total
            $itemBaseRowTotal = $item->getOriginalItem()->getBaseRowTotal();
            if ($amount * $qty != $itemBaseRowTotal) {
                $amount             = (double)$itemBaseRowTotal;
                $subAggregatedLabel = ' x' . $qty;
                $qty                = 1;
            }

            $this->_salesModelItems[] = $this->_createItemFromData(
                $item->getName() . $subAggregatedLabel,
                $qty,
                $amount
            );
        }

        $this->addSubtotal($this->_salesModel->getBaseSubtotal());
        $this->addTax($this->_salesModel->getBaseTaxAmount());
        $this->addShipping($this->_salesModel->getBaseShippingAmount());
        $this->addDiscount(abs($this->_salesModel->getBaseDiscountAmount()));
    }

    /**
     * Add "hidden" discount and shipping tax
     *
     * Tax settings for getting "discount tax":
     * - Catalog Prices = Including Tax
     * - Apply Customer Tax = After Discount
     * - Apply Discount on Prices = Including Tax
     *
     * Test case for getting "hidden shipping tax":
     * - Make sure shipping is taxable (set shipping tax class)
     * - Catalog Prices = Including Tax
     * - Shipping Prices = Including Tax
     * - Apply Customer Tax = After Discount
     * - Create a cart price rule with % discount applied to the Shipping Amount
     * - Run shopping cart and estimate shipping
     *
     * @param SalesModelInterface $salesEntity
     *
     * @return void
     */
    private function applyDiscountTaxCompensationWorkaround(
        SalesModelInterface $salesEntity
    ): void {
        $dataContainer = $salesEntity->getTaxContainer();
        $this->addTax((double)$dataContainer->getBaseDiscountTaxCompensationAmount());
        $this->addTax((double)$dataContainer->getBaseShippingDiscountTaxCompensationAmnt());
    }
}
