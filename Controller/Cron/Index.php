<?php

namespace Fortispay\Fortis\Controller\Cron;

use DateInterval;
use DateTime;
use Fortispay\Fortis\Controller\AbstractFortis;
use Magento\Framework\App\Area;
use Magento\Framework\App\ObjectManager;

/**
 * Responsible for loading page content.
 *
 * This is a basic controller that only loads the corresponding layout file. It may duplicate other such
 * controllers, and thus it is considered tech debt. This code duplication will be resolved in future releases.
 */
class Index extends AbstractFortis
{

    /**
     * Execute
     *
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface|void
     * @throws \Exception
     */
    public function execute()
    {
        $this->state->emulateAreaCode(
            Area::AREA_FRONTEND,
            function () {
                $this->_logger->error('Starting Fortis Cron');
                $this->updateForPendingFortisOrders();
                $this->_logger->error('Fortis Cron Ended');
            }
        );
    }

    /**
     * Update For Pending Fortis Orders
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function updateForPendingFortisOrders()
    {
        $cutoffTime = (new DateTime())->sub(new DateInterval('PT10M'))->format('Y-m-d H:i:s');
        $this->_logger->info('Cutoff: ' . $cutoffTime);
        $ocf = $this->_orderCollectionFactory->create();
        $ocf->addAttributeToSelect('entity_id');
        $ocf->addAttributeToFilter('status', ['eq' => 'pending_payment']);
        $ocf->addAttributeToFilter('created_at', ['lt' => $cutoffTime]);
        $ocf->addAttributeToFilter('updated_at', ['lt' => $cutoffTime]);
        $orderIds = $ocf->getData();

        $this->_logger->info('Orders for cron: ' . json_encode($orderIds));

        foreach ($orderIds as $orderId) {
            $order_id = $orderId['entity_id'];
            $order = $this->orderRepository->get($order_id);
            $transactionSearchResult = $this->transactionSearchResultInterfaceFactory;
            $transaction = $transactionSearchResult->create()->addOrderIdFilter($order_id)->getFirstItem();
            $PaymentTitle = $order->getPayment()->getMethodInstance()->getTitle();
            $transactionData = $transaction->getData();
            if (isset($transactionData['additional_information']['raw_details_info'])) {
                $add_info = $transactionData['additional_information']['raw_details_info'];
                if (isset($add_info['PAYMENT_TITLE'])) {
                    $PaymentTitle = $add_info['PAYMENT_TITLE'];
                }
            }

            $transactionId = $transaction->getData('txn_id');

            if (!empty($transactionId) & $PaymentTitle == "FORTIS_FORTIS") {
                $_fortishelper = ObjectManager::getInstance()->get(\Fortispay\Fortis\Helper\Data::class);
                $result        = $_fortishelper->getQueryResult($transactionId);

                if (isset($result['ns2PaymentType'])) {
                    $result['PAYMENT_TYPE_METHOD'] = $result['ns2PaymentType']['ns2Method'];
                    $result['PAYMENT_TYPE_DETAIL'] = $result['ns2PaymentType']['ns2Detail'];
                }
                unset($result['ns2PaymentType']);

                $result['PAY_REQUEST_ID'] = $transactionId;
                $result['PAYMENT_TITLE']  = "FORTIS";
                $_fortishelper->updatePaymentStatus($order, $result);
            }
        }
    }

    public function getResponse()
    {
        return $this->getResponse();
    }
}
