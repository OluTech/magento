<?php
/**
 * Unit tests for PaymentDataAssignObserver
 * Tests assignment of additional payment data (vault, surcharge, payment type)
 */

namespace Fortispay\Fortis\Test\Unit\Observer;

use Fortispay\Fortis\Observer\PaymentDataAssignObserver;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Payment\Model\InfoInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use PHPUnit\Framework\TestCase;

class PaymentDataAssignObserverTest extends TestCase
{
    /**
     * @var PaymentDataAssignObserver
     */
    private PaymentDataAssignObserver $observer;

    /**
     * @var InfoInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    private $paymentInfoMock;

    /**
     * @var DataObject|\PHPUnit\Framework\MockObject\MockObject
     */
    private $dataObjectMock;

    protected function setUp(): void
    {
        $this->observer = new PaymentDataAssignObserver();
        $this->paymentInfoMock = $this->createMock(InfoInterface::class);
        $this->dataObjectMock = new DataObject();
    }

    /**
     * Create an observer event with mock data
     */
    private function createObserverEvent(array $additionalData): Observer
    {
        $this->dataObjectMock->setData(
            PaymentInterface::KEY_ADDITIONAL_DATA,
            $additionalData
        );

        // Use real Event object since it extends DataObject and has getDataByKey()
        // Keys must match AbstractDataAssignObserver constants: 'data' and 'payment_model'
        $event = new Event([
            'data' => $this->dataObjectMock,
            'payment_model' => $this->paymentInfoMock
        ]);

        $observerMock = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEvent'])
            ->getMock();
            
        $observerMock->method('getEvent')->willReturn($event);

        return $observerMock;
    }

    /**
     * Test vault method data is correctly assigned
     */
    public function testExecuteAssignsVaultMethodData(): void
    {
        $vaultMethod = 'vault_token_12345';
        $additionalData = [
            PaymentDataAssignObserver::VAULT_NAME_INDEX => $vaultMethod
        ];

        $this->paymentInfoMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                PaymentDataAssignObserver::VAULT_NAME_INDEX,
                $vaultMethod
            );

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test surcharge data is correctly assigned
     */
    public function testExecuteAssignsSurchargeData(): void
    {
        $surchargeData = [
            'surchargeAmount' => 300,
            'surchargeRate' => 3.0,
            'subtotal' => 10000
        ];

        $additionalData = [
            PaymentDataAssignObserver::SURCHARGE_DATA_LITERAL => $surchargeData
        ];

        $this->paymentInfoMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                PaymentDataAssignObserver::SURCHARGE_DATA_LITERAL,
                $surchargeData
            );

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test payment type data is correctly assigned
     * Tests ACH vs CC product ID selection logic
     */
    public function testExecuteAssignsPaymentType(): void
    {
        $paymentType = 'ach';
        $additionalData = [
            PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE => $paymentType
        ];

        $this->paymentInfoMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE,
                $paymentType
            );

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test all additional data fields are assigned together
     * UAT scenario: Complete payment with vault, surcharge, and ACH type
     */
    public function testExecuteAssignsAllDataFields(): void
    {
        $vaultMethod = 'vault_token_12345';
        $surchargeData = ['surchargeAmount' => 150];
        $paymentType = 'ach';

        $additionalData = [
            PaymentDataAssignObserver::VAULT_NAME_INDEX => $vaultMethod,
            PaymentDataAssignObserver::SURCHARGE_DATA_LITERAL => $surchargeData,
            PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE => $paymentType
        ];

        $this->paymentInfoMock->expects($this->exactly(3))
            ->method('setAdditionalInformation')
            ->willReturnCallback(function ($key, $value) use ($vaultMethod, $surchargeData, $paymentType) {
                static $callCount = 0;
                $callCount++;

                if ($callCount === 1) {
                    $this->assertEquals(PaymentDataAssignObserver::VAULT_NAME_INDEX, $key);
                    $this->assertEquals($vaultMethod, $value);
                } elseif ($callCount === 2) {
                    $this->assertEquals(PaymentDataAssignObserver::SURCHARGE_DATA_LITERAL, $key);
                    $this->assertEquals($surchargeData, $value);
                } elseif ($callCount === 3) {
                    $this->assertEquals(PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE, $key);
                    $this->assertEquals($paymentType, $value);
                }
            });

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test execute does nothing when additional data is not an array
     */
    public function testExecuteWithNonArrayAdditionalData(): void
    {
        $this->dataObjectMock->setData(
            PaymentInterface::KEY_ADDITIONAL_DATA,
            'not-an-array'
        );

        $event = new Event([
            'data' => $this->dataObjectMock,
            'payment_model' => $this->paymentInfoMock
        ]);

        $observerMock = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEvent'])
            ->getMock();
            
        $observerMock->method('getEvent')->willReturn($event);

        $this->paymentInfoMock->expects($this->never())
            ->method('setAdditionalInformation');

        $this->observer->execute($observerMock);
    }

    /**
     * Test execute does nothing when additional data is null
     */
    public function testExecuteWithNullAdditionalData(): void
    {
        $this->dataObjectMock->setData(
            PaymentInterface::KEY_ADDITIONAL_DATA,
            null
        );

        $event = new Event([
            'data' => $this->dataObjectMock,
            'payment_model' => $this->paymentInfoMock
        ]);

        $observerMock = $this->getMockBuilder(Observer::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getEvent'])
            ->getMock();
            
        $observerMock->method('getEvent')->willReturn($event);

        $this->paymentInfoMock->expects($this->never())
            ->method('setAdditionalInformation');

        $this->observer->execute($observerMock);
    }

    /**
     * Test execute handles empty additional data array
     */
    public function testExecuteWithEmptyAdditionalData(): void
    {
        $additionalData = [];

        $this->paymentInfoMock->expects($this->never())
            ->method('setAdditionalInformation');

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test execute ignores unknown additional data fields
     */
    public function testExecuteIgnoresUnknownFields(): void
    {
        $additionalData = [
            'unknown_field' => 'some_value',
            'another_unknown' => 'another_value'
        ];

        $this->paymentInfoMock->expects($this->never())
            ->method('setAdditionalInformation');

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test CC payment type assignment
     * UAT test case: Guest-Approved CC Sale
     */
    public function testExecuteAssignsCreditCardPaymentType(): void
    {
        $paymentType = 'cc';
        $additionalData = [
            PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE => $paymentType
        ];

        $this->paymentInfoMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE,
                $paymentType
            );

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test tokenized payment with vault data
     * UAT test case: Account-Store CC Token
     */
    public function testExecuteAssignsTokenizedPaymentData(): void
    {
        $vaultMethod = 'token_31f194dff2ce43fc84bea243';
        $additionalData = [
            PaymentDataAssignObserver::VAULT_NAME_INDEX => $vaultMethod,
            PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE => 'cc'
        ];

        $this->paymentInfoMock->expects($this->exactly(2))
            ->method('setAdditionalInformation');

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test surcharge data structure for CC payment
     * UAT test case: Guest-Approved CC Sale - Surcharge
     */
    public function testExecuteAssignsSurchargeDataForCreditCard(): void
    {
        $surchargeData = [
            'surchargeAmount' => 300,
            'surchargeRate' => 3.0,
            'subtotal' => 10000,
            'total' => 10300
        ];

        $additionalData = [
            PaymentDataAssignObserver::SURCHARGE_DATA_LITERAL => $surchargeData,
            PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE => 'cc'
        ];

        $this->paymentInfoMock->expects($this->exactly(2))
            ->method('setAdditionalInformation');

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test ACH payment data assignment
     * UAT test case: ACH Debit - Guest
     * Regression test: ACH should not trigger multi-currency logic
     */
    public function testExecuteAssignsAchPaymentType(): void
    {
        $paymentType = 'ach';
        $additionalData = [
            PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE => $paymentType
        ];

        $this->paymentInfoMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                PaymentDataAssignObserver::FORTIS_PAYMENT_TYPE,
                $paymentType
            );

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test vault data with special characters
     */
    public function testExecuteAssignsVaultDataWithSpecialCharacters(): void
    {
        $vaultMethod = 'token_abc-123_xyz/456';
        $additionalData = [
            PaymentDataAssignObserver::VAULT_NAME_INDEX => $vaultMethod
        ];

        $this->paymentInfoMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                PaymentDataAssignObserver::VAULT_NAME_INDEX,
                $vaultMethod
            );

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }

    /**
     * Test surcharge data with zero amount (no surcharge)
     * UAT test case: Guest-Approved CC Sale - No surcharge
     */
    public function testExecuteAssignsZeroSurchargeData(): void
    {
        $surchargeData = [
            'surchargeAmount' => 0,
            'surchargeRate' => 0.0,
            'subtotal' => 10000,
            'total' => 10000
        ];

        $additionalData = [
            PaymentDataAssignObserver::SURCHARGE_DATA_LITERAL => $surchargeData
        ];

        $this->paymentInfoMock->expects($this->once())
            ->method('setAdditionalInformation')
            ->with(
                PaymentDataAssignObserver::SURCHARGE_DATA_LITERAL,
                $surchargeData
            );

        $observerEvent = $this->createObserverEvent($additionalData);
        $this->observer->execute($observerEvent);
    }
}
