# magento-2-simple-protect
template for using the magento 2 - simple protect framework

**Notes / Warnings**
> this implementation is currently in alpha stage.So handle it with care!
> To use this implementation you have to get the magento-2-simple-protect framework.

## Installation
### manuell
1. create directory:
> 'app/code/Payone/SimpleProtect'
or
> 'vendor/payone-gmbh/magento-2-simpleProtect'

2. copy the source, that you have a structure like this:
```
app
+code  
 +Payone
  +SimpleProtect
   +etc
   +Model
   +Observer
    composer.json
    registration.php
```

3. install and activate the plugin
```
php bin/magento module:enable Payone_SimpleProtectDemo
php bin/magento setup:upgrade
php bin/magento cache:clean
```

4. update / change the code for your needs

## entry points

### pre payment-selection

method `handlePrePaymentSelection`

Implementing this method gives the following possibilities:
1. Filtering out payment methods based on your own rule set
2. Throwing a LocalizedException to send the user back to shipping method selection
```
@param  Quote $oQuote
@param  MethodInterface[] $aPaymentMethods
@return MethodInterface[]
```
**Example**
```
public function handlePrePaymentSelection(Quote $oQuote, $aPaymentMethods)
{
    if ($this->isOnlySafePaymentApplicable($oQuote, true) === true) {
        return $this->getSafePaymentMethods($aPaymentMethods);
    }
    return $aPaymentMethods;
}
```

### post payment-selection

method `handlePostPaymentSelection`

Implementing this method gives the following possibilities:
1. Throwing a LocalizedException will stop the order creation and throw the user back to payment selection with the given thrown message
2. Throwing a FilterMethodListException with an array of safe payment methods will stop the order creation and
   throw the user back to payment selection with the given thrown message and remove all other payment methods except for the given ones
3. Finishing the method - so throwing no Exception will finish the order creation

```
@param  Quote $oQuote
@return void
@throws LocalizedException
@throws FilterMethodListException
```

**Example**
```
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
```

### change / enter billing address

method `handleEnterOrChangeBillingAddress`

Implementing this method gives the following possibilities:
1. Returning true will just continue the process without changing anything
2. Returning a (changed) address object instance of AddressInterface will show an address correction prompt to the customer
3. Throwing a LocalizedException will show the given exception message to the customer
```
@param AddressInterface $oAddressData
@param bool $blIsVirtual
@param double $dTotal
@return AddressInterface|bool
@throws LocalizedException
```

**Example**
```    
public function handleEnterOrChangeBillingAddress(AddressInterface $oAddressData, $blIsVirtual, $dTotal)
{
    if ($oAddressData->getFirstname() == "Alexa") {
        return false;
    }
    return true;
}
```

### change / enter shipping address

method `handleEnterOrChangeShippingAddress`

Implementing this method gives the following possibilities:
1. Returning true will just continue the process without changing anything
2. Returning a (changed) address object instance of AddressInterface will show an address correction prompt to the customer
3. Throwing a LocalizedException will show the given exception message to the customer

```
@param AddressInterface $oAddressData
@param bool $blIsVirtual
@param double $dTotal
@return AddressInterface|bool
@throws LocalizedException
```

**Example**
```
public function handleEnterOrChangeShippingAddress(AddressInterface $oAddressData, $blIsVirtual, $dTotal)
{
    if ($oAddressData->getCompany() == 'PAYONE') {
        return false;
    }
    return true;
}
```
