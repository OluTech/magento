<?php

namespace Fortispay\Fortis\Service;

use Exception;
use Magento\Sales\Api\OrderItemRepositoryInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\ItemFactory;
use Magento\Payment\Model\InfoInterface;

class MagentoOrderService
{
    /**
     * @var ItemFactory
     */
    private ItemFactory $orderItemFactory;

    /**
     * @var OrderRepositoryInterface
     */
    private OrderRepositoryInterface $orderRepository;

    /**
     * @var OrderItemRepositoryInterface
     */
    private OrderItemRepositoryInterface $orderItemRepository;

    /**
     * MagentoOrderService constructor.
     *
     * @param ItemFactory $orderItemFactory
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderItemRepositoryInterface $orderItemRepository
     */
    public function __construct(
        ItemFactory $orderItemFactory,
        OrderRepositoryInterface $orderRepository,
        OrderItemRepositoryInterface $orderItemRepository
    ) {
        $this->orderItemFactory    = $orderItemFactory;
        $this->orderRepository     = $orderRepository;
        $this->orderItemRepository = $orderItemRepository;
    }

    /**
     * Apply surcharge as a virtual order item
     *
     * @param Order $order
     * @param float $surchargeAmount
     * @param Invoice|null $invoice
     *
     * @return void
     * @throws Exception
     */
    public function applySurcharge(Order $order, float $surchargeAmount, ?Invoice $invoice = null): void
    {
        if (!$surchargeAmount || $surchargeAmount <= 0) {
            return;
        }

        // Convert from cents to currency units
        $surchargeAmount = $surchargeAmount / 100;
        $order->setData('custom_surcharge', $surchargeAmount);

        $this->updateOrderTotals($order, $surchargeAmount);

        if ($invoice && $surchargeAmount > 0) {
            $invoice->setSubtotal($invoice->getSubtotal() + $surchargeAmount);
            $invoice->setBaseSubtotal($invoice->getBaseSubtotal() + $surchargeAmount);
            $invoice->setGrandTotal($invoice->getGrandTotal() + $surchargeAmount);
            $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $surchargeAmount);
        }

        $order->addCommentToStatusHistory(__('Surcharge of $%1 added.', $surchargeAmount));

        $this->orderRepository->save($order);
    }

    /**
     * Update order totals with surcharge
     *
     * @param Order $order
     * @param float $surchargeAmount
     *
     * @return void
     */
    private function updateOrderTotals(Order $order, float $surchargeAmount): void
    {
        $order->setSubtotal($order->getSubtotal() + $surchargeAmount)
              ->setBaseSubtotal($order->getBaseSubtotal() + $surchargeAmount)
              ->setGrandTotal($order->getGrandTotal() + $surchargeAmount)
              ->setBaseGrandTotal($order->getBaseGrandTotal() + $surchargeAmount);
    }

    /**
     * Update order refund data to reflect the adjusted amount
     *
     * @param InfoInterface $payment
     * @param float $adjustedAmount
     * @param float $originalAmount
     *
     * @return void
     */
    public function updateOrderRefundData(InfoInterface $payment, float $adjustedAmount, float $originalAmount): void
    {
        $order = $payment->getOrder();

        $creditMemo = $payment->getCreditmemo();

        if ($creditMemo) {
            $adjustment = $adjustedAmount - $originalAmount;
            $creditMemo->setGrandTotal($adjustedAmount);
            $creditMemo->setBaseGrandTotal($adjustedAmount);
            $creditMemo->setAdjustmentPositive($adjustment);

            $order->setTotalRefunded($order->getTotalRefunded() + $adjustment);
            $order->setBaseTotalRefunded($order->getBaseTotalRefunded() + $adjustment);

            $this->orderRepository->save($order);
        }
    }
}
