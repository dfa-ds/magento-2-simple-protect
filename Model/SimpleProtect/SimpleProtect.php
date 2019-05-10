<?php

/**
 * PAYONE Magento 2 Connector is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * PAYONE Magento 2 Connector is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with PAYONE Magento 2 Connector. If not, see <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 *
 * @category  Payone
 * @package   Payone_Magento2_SimpleProtect
 * @author    FATCHIP GmbH <support@fatchip.de>
 * @copyright 2003 - 2019 Payone GmbH
 * @license   <http://www.gnu.org/licenses/> GNU Lesser General Public License
 * @link      http://www.payone.de
 */

namespace Payone\SimpleProtect\Model\SimpleProtect;

use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Model\Quote;
use Payone\Core\Model\Source\Mode;
use Payone\Core\Model\SimpleProtect\SimpleProtectInterface;
use Payone\Core\Model\PayoneConfig;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Quote\Model\Quote\Address;
use Payone\Core\Model\Source\AddressCheckType;
use Payone\Core\Model\Source\CreditratingCheckType;
use Magento\Framework\Exception\LocalizedException;
use Payone\Core\Model\Exception\FilterMethodListException;
use Payone\Core\Model\Api\Response\AddresscheckResponse;
use Payone\Core\Model\Api\Response\ConsumerscoreResponse;
use Magento\Quote\Api\Data\AddressInterface;

class SimpleProtect implements SimpleProtectInterface
{
    /**
     * Whitelist of safe payment methods
     *
     * @var array
     */
    protected $safePaymentMethods = [
        PayoneConfig::METHOD_ADVANCE_PAYMENT,
        PayoneConfig::METHOD_CREDITCARD,
        PayoneConfig::METHOD_PAYPAL
    ];

    /**
     * PAYONE Protect model providing access to consumerscore and addresscheck requests
     *
     * @var \Payone\Core\Model\SimpleProtect\ProtectFunnel
     */
    protected $protectFunnel;

    /**
     * Database connection resource
     *
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $databaseResource;

    /**
     * Checkout session object
     *
     * @var \Magento\Checkout\Model\Session\Proxy
     */
    protected $checkoutSession;

    /**
     * Constructor
     *
     * @param \Payone\Core\Model\SimpleProtect\ProtectFunnel $protectFunnel
     * @param \Magento\Framework\App\ResourceConnection      $resource
     * @param \Magento\Checkout\Model\Session\Proxy           $checkoutSession
     */
    public function __construct(
        \Payone\Core\Model\SimpleProtect\ProtectFunnel $protectFunnel,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Checkout\Model\Session\Proxy $checkoutSession
    ) {
        $this->protectFunnel = $protectFunnel;
        $this->databaseResource = $resource;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Returns operation mode used for the addresscheck and consumerscore
     * Can be MODE_TEST or MODE_LIVE
     *
     * @return string
     */
    public function getOperationMode()
    {
        return Mode::MODE_TEST;
    }

    /**
     * Get count of customers orders
     *
     * @param CustomerInterface $oCustomer
     * @return int
     */
    protected function getCustomersOrderCount(CustomerInterface $oCustomer)
    {
        $db = $this->databaseResource->getConnection();
        $oSelect = $db->select()
            ->from($this->databaseResource->getTableName('sales_order'), ['COUNT(entity_id)'])
            ->where("customer_id = :customerId");
        $iCount = $db->fetchOne($oSelect, ['customerId' => $oCustomer->getId()]);
        if ($iCount === null) {
            return 0;
        }
        return $iCount;
    }

    /**
     * Check if the customer has ordered before
     *
     * @param CustomerInterface $oCustomer
     * @return bool
     */
    protected function isRecurringCustomer(CustomerInterface $oCustomer)
    {
        if ($this->getCustomersOrderCount($oCustomer) == 0) {
            return false;
        }
        return true;
    }

    /**
     * Possibility to whiteliste customers with custom functionality
     *
     * @param  CustomerInterface $oCustomer
     * @return bool
     */
    protected function isCustomerWhitelisted(CustomerInterface $oCustomer)
    {
        return true; // implement this for yourself or remove completely
    }

    /**
     * Generate hash of given address for comparison
     *
     * @param  Address $oAddress
     * @return string
     */
    protected function getAddressHash(Address $oAddress) {
        $sAddress  = $oAddress->getFirstname();
        $sAddress .= $oAddress->getLastname();
        $sAddress .= $oAddress->getCity();
        $sAddress .= $oAddress->getPostcode();
        $sAddress .= $oAddress->getCountry();
        $sAddress .= $oAddress->getStreetFull();

        return md5($sAddress);
    }

    /**
     * Compare given addresses, return true if they are the same
     *
     * @param  Address $oBilling
     * @param  Address $oShipping
     * @return bool
     */
    protected function isBillingAndShippingAddressTheSame(Address $oBilling, Address $oShipping)
    {
        if ($this->getAddressHash($oBilling) != $this->getAddressHash($oShipping)) {
            return false;
        }
        return true;
    }

    /**
     * Filter out all payment methods except for the safe payment methods
     *
     * @param  MethodInterface[] $aPaymentMethods
     * @return MethodInterface[]
     */
    protected function getSafePaymentMethods($aPaymentMethods)
    {
        $aReturn = [];
        foreach ($aPaymentMethods as $oPaymentMethod) {
            if (in_array($oPaymentMethod->getCode(), $this->safePaymentMethods) === true) {
                $aReturn[] = $oPaymentMethod;
            }
        }
        return $aReturn;
    }

    /**
     * Examples of all the options for addresscheck usage
     *
     * @param  Quote $oQuote
     * @return AddresscheckResponse|bool
     */
    protected function executeAddresscheck(Quote $oQuote)
    {
        $oAddress = $oQuote->getBillingAddress();
        #$oAddress = $oQuote->getShippingAddress();

        #$sAddresscheckType = AddressCheckType::NONE;
        $sAddresscheckType = AddressCheckType::BASIC;
        #$sAddresscheckType = AddressCheckType::PERSON;
        #$sAddresscheckType = AddressCheckType::BONIVERSUM_BASIC;
        #$sAddresscheckType = AddressCheckType::BONIVERSUM_PERSON;

        return $this->protectFunnel->executeAddresscheck($oAddress, $this->getOperationMode(), $sAddresscheckType);
    }

    /**
     * Examples of all the options for consumerscore usage
     *
     * @param  Quote $oQuote
     * @return ConsumerscoreResponse|bool
     */
    protected function executeConsumerscore(Quote $oQuote)
    {
        $oAddress = $oQuote->getBillingAddress();
        #$oAddress = $oQuote->getShippingAddress();

        #$sConsumerscoreType = CreditratingCheckType::INFOSCORE_HARD;
        $sConsumerscoreType = CreditratingCheckType::INFOSCORE_ALL;
        #$sConsumerscoreType = CreditratingCheckType::INFOSCORE_ALL_BONI;
        #$sConsumerscoreType = CreditratingCheckType::BONIVERSUM_VERITA;

        $sAddresscheckType = AddressCheckType::NONE;
        #$sAddresscheckType = AddressCheckType::BASIC;
        #$sAddresscheckType = AddressCheckType::PERSON;
        #$sAddresscheckType = AddressCheckType::BONIVERSUM_BASIC;
        #$sAddresscheckType = AddressCheckType::BONIVERSUM_PERSON;

        return $this->protectFunnel->executeConsumerscore($oAddress, $this->getOperationMode(), $sConsumerscoreType, $sAddresscheckType);
    }

    /**
     * Check rules for recurring registered customers
     *
     * @param  Quote $oQuote
     * @return bool
     */
    protected function isOnlySafePaymentApplicableForRecurringCustomer(Quote $oQuote)
    {
        if ($oQuote->getBaseGrandTotal() > 400 || $this->isCustomerWhitelisted($oQuote->getCustomer()) === false) {
            return true;
        }
        return false;
    }

    /**
     * Check rules for first time registered customer
     *
     * @param  Quote $oQuote
     * @param  bool  $blIsPrePaymentSelection
     * @return bool
     */
    protected function isOnlySafePaymentApplicableForInitialOrder(Quote $oQuote, $blIsPrePaymentSelection)
    {
        if ($blIsPrePaymentSelection === false && $this->isBillingAndShippingAddressTheSame($oQuote->getBillingAddress(), $oQuote->getShippingAddress()) === false) {
            return true;
        }

        if ($oQuote->getBaseGrandTotal() > 120) {
            return true;
        }

        if ($blIsPrePaymentSelection === false) {
            $oResponse = $this->executeConsumerscore($oQuote);
            if ($oResponse instanceof ConsumerscoreResponse && ($oResponse->getStatus() != 'VALID' || $oResponse->getScore() != 'G')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if only safe payment methods are applicable
     *
     * @param  Quote $oQuote
     * @param  bool  $blIsPrePaymentSelection
     * @return bool
     */
    protected function isOnlySafePaymentApplicable(Quote $oQuote, $blIsPrePaymentSelection)
    {
        if ($this->checkoutSession->getPayoneSimpleProtectOnlySafePaymentsAllowed() === true) {
            return true;
        }

        if ($oQuote->getCustomerId() === null) { // if guest checkout
            return true;
        }

        if ($this->isRecurringCustomer($oQuote->getCustomer()) === true) {
            return $this->isOnlySafePaymentApplicableForRecurringCustomer($oQuote);
        }
        return $this->isOnlySafePaymentApplicableForInitialOrder($oQuote, $blIsPrePaymentSelection);
    }

    /************************* MAIN SIMPLEPROTECT HOOKS *************************/

    /**
     * This method can be implemented for individual custom behaviour
     *
     * Extending this method gives the following possibilities:
     * 1. Filtering out payment methods based on your own rule set
     * 2. Throwing a LocalizedException to send the user back to shipping method selection
     *
     * @param  Quote             $oQuote
     * @param  MethodInterface[] $aPaymentMethods
     * @return MethodInterface[]
     */
    public function handlePrePaymentSelection(Quote $oQuote, $aPaymentMethods)
    {
        if ($this->isOnlySafePaymentApplicable($oQuote, true) === true) {
            return $this->getSafePaymentMethods($aPaymentMethods);
        }
        return $aPaymentMethods;
    }

    /**
     * This method can be implemented for individual custom behaviour
     *
     * Extending this method gives the following possibilities:
     * 1. Throwing a LocalizedException will stop the order creation and throw the user back to payment selection with the given thrown message
     * 2. Throwing a FilterMethodListException with an array of safe payment methods will stop the order creation and
     *    throw the user back to payment selection with the given thrown message and remove all other payment methods except for the given ones
     * 3. Finishing the method - so throwing no Exception will finish the order creation
     *
     * @param  Quote $oQuote
     * @return void
     * @throws LocalizedException
     * @throws FilterMethodListException
     */
    public function handlePostPaymentSelection(Quote $oQuote)
    {
        if ($this->isOnlySafePaymentApplicable($oQuote, false) === true) {
            $sMethodCode = $oQuote->getPayment()->getMethodInstance()->getCode();
            if (!in_array($sMethodCode, $this->safePaymentMethods)) {
                $this->checkoutSession->setPayoneSimpleProtectOnlySafePaymentsAllowed(true);
                throw new FilterMethodListException(__('Please select another payment method.'), $this->safePaymentMethods);
            }
        }
    }

    /**
     * This method can be implemented for individual custom behaviour
     *
     * Extending this method gives the following possibilities:
     * 1. Returning true will just continue the process without changing anything
     * 2. Returning a (changed) address object instance of AddressInterface will show an address correction prompt to the customer
     * 3. Throwing a LocalizedException will show the given exception message to the customer
     *
     * @param AddressInterface $oAddressData
     * @param bool             $blIsVirtual
     * @param double           $dTotal
     * @return AddressInterface|bool
     * @throws LocalizedException
     */
    public function handleEnterOrChangeBillingAddress(AddressInterface $oAddressData, $blIsVirtual, $dTotal)
    {
        return true;
    }

    /**
     * This method can be implemented for individual custom behaviour
     *
     * Extending this method gives the following possibilities:
     * 1. Returning true will just continue the process without changing anything
     * 2. Returning a (changed) address object instance of AddressInterface will show an address correction prompt to the customer
     * 3. Throwing a LocalizedException will show the given exception message to the customer
     *
     * @param AddressInterface $oAddressData
     * @param bool             $blIsVirtual
     * @param double           $dTotal
     * @return AddressInterface|bool
     * @throws LocalizedException
     */
    public function handleEnterOrChangeShippingAddress(AddressInterface $oAddressData, $blIsVirtual, $dTotal)
    {
        return true;
    }

    /**
     * This method can be implemented for individual custom behaviour
     *
     * Extend this method to return true to enable addresscheck frontend ajax calls for billing address
     *
     * @return bool
     */
    public function isAddresscheckBillingEnabled()
    {
        return false;
    }

    /**
     * This method can be implemented for individual custom behaviour
     *
     * Extend this method to return true to enable addresscheck frontend ajax calls for shipping address
     *
     * @return bool
     */
    public function isAddresscheckShippingEnabled()
    {
        return false;
    }

    /**
     * This method can be implemented for individual custom behaviour
     *
     * Extend this method to return false to disable address correction confirmation
     *
     * @return bool
     */
    public function isAddresscheckCorrectionConfirmationNeeded()
    {
        return true;
    }
}