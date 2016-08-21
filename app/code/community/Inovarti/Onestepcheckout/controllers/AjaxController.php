<?php

/**
 *
 * @category   Inovarti
 * @package    Inovarti_Onestepcheckout
 * @author     Suporte <suporte@inovarti.com.br>
 */
 
class Inovarti_Onestepcheckout_AjaxController extends Mage_Checkout_Controller_Action
{

  /**
  * @return Inovarti_Onestepcheckout_AjaxController|Mage_Core_Controller_Front_Action
  */

  public function preDispatch() {
     parent::preDispatch();
     $this->_preDispatchValidateCustomer();
     $checkoutSessionQuote = Mage::getSingleton('checkout/session')->getQuote();
     if ($checkoutSessionQuote->getIsMultiShipping()) {
         $checkoutSessionQuote->setIsMultiShipping(false);
         $checkoutSessionQuote->removeAllAddresses();
     }
     return $this;
  }

  /**
  * @return Mage_Checkout_Model_Type_Onepage
  */
  public function getOnepage() {
     return Mage::getSingleton('checkout/type_onepage');
  }
  /**
  * @return Inovarti_Onestepcheckout_Model_Updater
  */
  public function getUpdater() {
     return Mage::getSingleton('onestepcheckout/updater');
  }
  /**
  * Check can page show for unregistered users
  *
  * @return boolean
  */
  protected function _canShowForUnregisteredUsers() {
     //TODO: show login block only for unregistered
     return Mage::getSingleton('customer/session')->isLoggedIn() || Mage::helper('checkout')->isAllowedGuestCheckout($this->getOnepage()->getQuote()) || !Mage::helper('checkout')->isCustomerMustBeLogged();
  }

  protected function _ajaxRedirectResponse() {
     $this->getResponse()
             ->setHeader('HTTP/1.1', '403 Session Expired')
             ->setHeader('Login-Required', 'true')
             ->sendResponse();
     return $this;
  }

  protected function _expireAjax() {
     if (!$this->getOnepage()->getQuote()->hasItems() || $this->getOnepage()->getQuote()->getHasError() || $this->getOnepage()->getQuote()->getIsMultiShipping()) {
         $this->_ajaxRedirectResponse();
         return true;
     }
     if (Mage::getSingleton('checkout/session')->getCartWasUpdated(true)) {
         $this->_ajaxRedirectResponse();
         return true;
     }
     return false;
  }

  public function efetuarloginAction() {
     if ($this->_expireAjax()) {
         return;
     }

     $response['status'] = 'empty';
     $customerSession = Mage::getSingleton('customer/session');
     if (!$customerSession->isLoggedIn()) {
         $formData = $this->getRequest()->getPost('login');
         if (!empty($formData['username']) && !empty($formData['password'])) {
             try {
                 $customerSession->login($formData['username'], $formData['password']);
             } catch (Mage_Core_Exception $e) {
               $response['status'] = 'invalid';
               switch ($e->getCode()) {
                   case Mage_Customer_Model_Customer::EXCEPTION_EMAIL_NOT_CONFIRMED:
                       $emailConfirmationLink = Mage::helper('customer')->getEmailConfirmationUrl($loginData['username']);
                       $response['message'] = $this->__('This account is not confirmed. <a href="%s">Click here</a> to resend confirmation email.', $emailConfirmationLink);
                       break;
                   case Mage_Customer_Model_Customer::EXCEPTION_INVALID_EMAIL_OR_PASSWORD:
                       $response['message'] = $e->getMessage();
                       break;
                   default:
                       $response['status'] = 'error';
                       $response['message'] = $e->getMessage();
               }
             } catch (Exception $e) {
                 $response['status'] = 'invalid';
                 $response['message'] = $e->getMessage();
             }
         } else {
             $response['status'] = 'invalid';
             $response['message'] = $this->__('Login and password are required.');
         }
     }
     else {
         $response['status'] = 'exists';
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
  }


  public function recuperarsenhaAction() {
     if ($this->_expireAjax()) {
         return;
     }

     $response['status'] = 'empty';
     $customerSession = Mage::getSingleton('customer/session');
     $email = (string) $this->getRequest()->getPost('email');
     if (!empty($email)) {
         if (Zend_Validate::is($email, 'EmailAddress')) {
             $customer = Mage::getModel('customer/customer')
                     ->setWebsiteId(Mage::app()->getStore()->getWebsiteId())
                     ->loadByEmail($email);
             if ($customer->getId()) {
                 try {
                     Mage::helper('onestepcheckout/customer')->sendForgotPasswordForCustomer($customer);
                 } catch (Exception $exception) {
                     $response['status'] = 'error';
                     $response['message'] = $exception->getMessage();
                 }
             }
         } else {
             $customerSession->setForgottenEmail($email);
             $response['status'] = 'invalid';
             $response['message'] = $this->__('Invalid email address.');
         }
     } else {
         $response['status'] = 'invalid';
         $response['message'] = $this->__('Please enter your email.');
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
  }

  public function salvadadosAction() {
     if ($this->_expireAjax()) {
         return;
     }

     $response['status'] = 'empty';
     if ($this->getRequest()->isPost()) {
         $formData = $this->getRequest()->getPost();
         $checkoutData = Mage::getSingleton('checkout/session')->getData('onestepcheckout_form_values');
         if (!is_array($checkoutData)) {
             $checkoutData = array();
         }
         try {
           Mage::getSingleton('checkout/session')->setData(
                   'onestepcheckout_form_values', array_merge($checkoutData, $formData)
           );
           $response['status'] = 'exists';
         } catch (Exception $e) {
           $response['status'] = 'error';
           $response['message'] = $e->getMessage();
         }
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
  }

  public function salvaenderecoAction() {
     if ($this->_expireAjax()) {
         return;
     }

     $response['status'] = 'empty';
     if ($this->getRequest()->isPost()) {
         $formData = $this->getRequest()->getPost('billing', array());
         $customerAddressId = $this->getRequest()->getPost('billing_address_id', false);

         if (isset($formData['email'])) {
             $formData['email'] = trim($formData['email']);
         }

         $saveBillingResult = Mage::helper('onestepcheckout/address')->saveBilling($formData, $customerAddressId);

         $usingCase = isset($formData['use_for_shipping']) ? (int) $formData['use_for_shipping'] : 0;
         if ($usingCase === 0) {
             $formData = $this->getRequest()->getPost('shipping', array());
             $customerAddressId = $this->getRequest()->getPost('shipping_address_id', false);
             $saveShippingResult = Mage::helper('onestepcheckout/address')->saveShipping($formData, $customerAddressId);
         }

         if (isset($saveShippingResult)) {
             $saveResult = array_merge($saveBillingResult, $saveShippingResult);
         } else {
             $saveResult = $saveBillingResult;
         }

         if (isset($saveResult['error'])) {
             $response['status'] = 'invalid';
             if (is_array($saveResult['message'])) {
                 $response['message'] = implode($saveResult['message'], ',');
             } else {
                 $response['message'] = $saveResult['message'];
             }
         }
         else {
           $this->getOnepage()->getQuote()->collectTotals()->save();
           //$result['blocks'] = $this->getUpdater()->getBlocks();
           $response['status'] = 'exists';
           $response['data'] = array(
             'grand_total' : Mage::helper('onestepcheckout')->getGrandTotal($this->getOnepage()->getQuote())
           );
         }
     } else {
         $response['status'] = 'invalid';
         $response['message'] = $this->__('Please specify billing address information.');
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
  }

  public function salvafreteAction() {
     if ($this->_expireAjax()) {
         return;
     }

     $response['status'] = 'empty';
     if ($this->getRequest()->isPost()) {
         $formData = $this->getRequest()->getPost('shipping_method', '');
         $saveResult = $this->getOnepage()->saveShippingMethod($formData);
         if (isset($saveResult['error'])) {
           $response['status'] = 'error';
           $response['message'] = $saveResult['message'];
         } else {
           Mage::dispatchEvent(
                   'checkout_controller_onepage_save_shipping_method', array(
               'request' => $this->getRequest(),
               'quote' => $this->getOnepage()->getQuote()
                   )
           );
           $this->getOnepage()->getQuote()->collectTotals()->save();
           //$result['blocks'] = $this->getUpdater()->getBlocks();
           $response['status'] = 'exists';
           $response['data'] = array(
             'grand_total' : Mage::helper('onestepcheckout')->getGrandTotal($this->getOnepage()->getQuote())
           );
         }
     } else {
       $response['status'] = 'invalid';
       $response['message'] = $this->__('Please specify shipping method.');
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
  }

  public function salvapagamentoAction() {
     if ($this->_expireAjax()) {
         return;
     }

     $response['status'] = 'empty';
     try {
         if ($this->getRequest()->isPost()) {
             $formData = $this->getRequest()->getPost('payment', array());
             $checkoutSession = Mage::getSingleton('checkout/session');
             $saveResult = $this->getOnepage()->savePayment($formData);
             if (isset($saveResult['error'])) {
               $response['status'] = 'error';
               $response['message'] = $saveResult['message'];
             }
             else {
               $this->getOnepage()->getQuote()->collectTotals()->save();
               //$result['blocks'] = $this->getUpdater()->getBlocks();
               $response['status'] = 'exists';
               $response['data'] = array(
                 'grand_total' : Mage::helper('onestepcheckout')->getGrandTotal($this->getOnepage()->getQuote())
               );
             }
         } else {
           $response['status'] = 'invalid';
           $response['message'] = $this->__('Please specify payment method.');
         }
     } catch (Exception $e) {
         Mage::logException($e);
         $response['status'] = 'error';
         $response['message'] = $this->__('Unable to set Payment Method.');
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
  }

  public function aplicarcupomAction() {
     if ($this->_expireAjax()) {
         return;
     }
     $result = array(
         'success' => true,
         'coupon_applied' => false,
         'messages' => array(),
         'blocks' => array(),
         'grand_total' => ""
     );
     if (!$this->getOnepage()->getQuote()->getItemsCount()) {
         $result['success'] = false;
     } else {
         $couponCode = (string) $this->getRequest()->getParam('coupon_code');
         $oldCouponCode = $this->getOnepage()->getQuote()->getCouponCode();
         if (!strlen($couponCode) && !strlen($oldCouponCode)) {
             $result['success'] = false;
         } else {
             try {
                 $this->getOnepage()->getQuote()->getShippingAddress()->setCollectShippingRates(true);
                 $this->getOnepage()->getQuote()->setCouponCode(strlen($couponCode) ? $couponCode : '')
                         ->collectTotals()
                         ->save();
                 if ($couponCode == $this->getOnepage()->getQuote()->getCouponCode()) {
                     $this->getOnepage()->getQuote()->getShippingAddress()->setCollectShippingRates(true);
                     $this->getOnepage()->getQuote()->setTotalsCollectedFlag(false);
                     $this->getOnepage()->getQuote()->collectTotals()->save();
                     //fix for raf
                     Mage::getSingleton('checkout/session')->getMessages(true);
                     if (strlen($couponCode)) {
                         $result['coupon_applied'] = true;
                         $result['messages'][] = $this->__('Coupon code was applied.');
                     } else {
                         $result['coupon_applied'] = false;
                         $result['messages'][] = $this->__('Coupon code was canceled.');
                     }
                 } else {
                     $result['success'] = false;
                     $result['messages'][] = $this->__('Coupon code is not valid.');
                 }
                 $result['blocks'] = $this->getUpdater()->getBlocks();
                 $result['grand_total'] = Mage::helper('onestepcheckout')->getGrandTotal($this->getOnepage()->getQuote());
             } catch (Mage_Core_Exception $e) {
                 $result['success'] = false;
                 $result['messages'][] = $e->getMessage();
             } catch (Exception $e) {
                 $result['success'] = false;
                 $result['messages'][] = $this->__('Cannot apply the coupon code.');
                 Mage::logException($e);
             }
         }
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
  }


  public function placeOrderAction() {
     if ($this->_expireAjax()) {
         return;
     }
     $result = array(
         'success' => true,
         'messages' => array(),
     );
     try {
         //TODO: re-factoring. Move to helpers
         if ($this->getRequest()->isPost()) {
             $billingData = $this->getRequest()->getPost('billing', array());
             // save checkout method
             if (!$this->getOnepage()->getCustomerSession()->isLoggedIn()) {
                 if (isset($billingData['create_account'])) {
                     $this->getOnepage()->saveCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER);
                 } else {
                     $this->getOnepage()->saveCheckoutMethod(Mage_Checkout_Model_Type_Onepage::METHOD_GUEST);
                 }
             }
             if (!$this->getOnepage()->getQuote()->getCustomerId() &&
                     Mage_Checkout_Model_Type_Onepage::METHOD_REGISTER == $this->getOnepage()->getQuote()->getCheckoutMethod()
             ) {
                 if ($this->_customerEmailExists($billingData['email'], Mage::app()->getWebsite()->getId())) {
                     $result['success'] = false;
                     $result['messages'][] = $this->__('There is already a customer registered using this email address. Please login using this email address or enter a different email address to register your account.');
                 }
             }
             if ($result['success']) {
                 // save billing address
                 $customerAddressId = $this->getRequest()->getPost('billing_address_id', false);
                 if (isset($billingData['email'])) {
                     $billingData['email'] = trim($billingData['email']);
                 }
                 $saveBillingResult = $this->getOnepage()->saveBilling($billingData, $customerAddressId);
                 //save shipping address
                 if (!isset($billingData['use_for_shipping'])) {
                     $shippingData = $this->getRequest()->getPost('shipping', array());
                     $customerAddressId = $this->getRequest()->getPost('shipping_address_id', false);
                     $saveShippingResult = $this->getOnepage()->saveShipping($shippingData, $customerAddressId);
                 }
                 // check errors
                 if (isset($saveShippingResult)) {
                     $saveResult = array_merge($saveBillingResult, $saveShippingResult);
                 } else {
                     $saveResult = $saveBillingResult;
                 }
                 //chamar evento gift
                 Mage::dispatchEvent(
                     'checkout_controller_onepage_save_shipping_method',
                     array(
                          'request' => $this->getRequest(),
                          'quote'   => $this->getOnepage()->getQuote()
                     )
                 );
                 if (isset($saveResult['error'])) {
                     $result['success'] = false;
                     if (!is_array($saveResult['message'])) {
                         $saveResult['message'] = array($saveResult['message']);
                     }
                     $result['messages'] = array_merge($result['messages'], $saveResult['message']);
                 } else {
                     // check agreements
                     $requiredAgreements = Mage::helper('checkout')->getRequiredAgreementIds();
                     $postedAgreements = array_keys($this->getRequest()->getPost('inovarti_osc_agreement', array()));
                     if ($diff = array_diff($requiredAgreements, $postedAgreements)) {
                         $result['success'] = false;
                         $result['messages'][] = $this->__('Please agree to all the terms and conditions before placing the order.');
                     } else {
                         if ($response = $this->getRequest()->getPost('payment', false)) {
                             $this->getOnepage()->getQuote()->getPayment()->importData($response);
                         }
                         //save data for use after order save
                         $response = array(
                             'comments' => $this->getRequest()->getPost('comments', false),
                             'is_subscribed' => $this->getRequest()->getPost('is_subscribed', false),
                             'billing' => $this->getRequest()->getPost('billing', array()),
                             'segments_select' => $this->getRequest()->getPost('segments_select', array())
                         );
                         Mage::getSingleton('checkout/session')->setData('onestepcheckout_order_data', $response);
                         $redirectUrl = $this->getOnepage()->getQuote()->getPayment()->getCheckoutRedirectUrl();
                         if (!$redirectUrl) {
                             $this->getOnepage()->saveOrder();
                             $redirectUrl = $this->getOnepage()->getCheckout()->getRedirectUrl();
                         }
                     }
                 }
             }
         } else {
             $result['success'] = false;
         }
     } catch (Exception $e) {
         Mage::logException($e);
         Mage::helper('checkout')->sendPaymentFailedEmail($this->getOnepage()->getQuote(), $e->getMessage());
         $result['success'] = false;
         $result['messages'][] = $e->getMessage();
     }
     if ($result['success']) {
         $this->getOnepage()->getQuote()->save();
         if (isset($redirectUrl)) {
             $result['redirect'] = $redirectUrl;
         }
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
  }

  public function addProductToWishlistAction() {
     if ($this->_expireAjax()) {
         return;
     }
     $result = array(
         'success' => true,
         'messages' => array()
     );
     $customerSession = Mage::getSingleton('customer/session');
     $wishlistSession = Mage::getSingleton('wishlist/session');
     $response = clone $this->getResponse();
     $wishlistControllerInstance = $this->_getCustomerWishlistController($this->getRequest(), $response);
     if (!is_null($wishlistControllerInstance) && method_exists($wishlistControllerInstance, 'addAction')) {
         $wishlistControllerInstance->addAction();
         $wishlistMessagesCollection = $wishlistSession->getMessages(true);
         $customerMessageCollection = $customerSession->getMessages(true);
         $successMessages = array_merge(
                 $wishlistMessagesCollection->getItemsByType(Mage_Core_Model_Message::SUCCESS), $customerMessageCollection->getItemsByType(Mage_Core_Model_Message::SUCCESS)
         );
         if (count($successMessages) === 0) {
             //if something wrong
             $result['success'] = false;
             $product = Mage::getModel('catalog/product')->load($this->getRequest()->getParam('product', 0));
             if (!is_null($product->getId())) {
                 $referer = $product->getUrlModel()->getUrl($product, array());
                 $result['messages'][] = $this->__(
                         'Product "%1$s" has not been added. Please add it <a href="%2$s">from product page</a>', $product->getName(), $referer
                 );
             }
         } else {
             $result['blocks'] = $this->getUpdater()->getBlocks();
             $product = Mage::getModel('catalog/product')->load($this->getRequest()->getParam('product', 0));
             if (!is_null($product->getId())) {
                 $result['messages'][] = $this->__(
                         'Product "%1$s" was successfully added to wishlist', $product->getName()
                 );
             } else {
                 $result['messages'][] = $this->__('Product was successfully added to wishlist');
             }
         }
     } else {
         $result['success'] = false;
         $result['messages'][] = $this->__("Oops something's wrong");
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
  }

  public function addProductToCompareListAction() {
     if ($this->_expireAjax()) {
         return;
     }
     $result = array(
         'success' => true,
         'messages' => array()
     );
     $catalogSession = Mage::getSingleton('catalog/session');
     $response = clone $this->getResponse();
     $productCompareControllerInstance = $this->_getProductCompareController($this->getRequest(), $response);
     if (!is_null($productCompareControllerInstance) && method_exists($productCompareControllerInstance, 'addAction')) {
         $productCompareControllerInstance->addAction();
         $messageCollection = $catalogSession->getMessages(true);
         $successMessages = $messageCollection->getItemsByType(Mage_Core_Model_Message::SUCCESS);
         if (count($successMessages) === 0) {
             //if something wrong
             $result['success'] = false;
             $product = Mage::getModel('catalog/product')->load($this->getRequest()->getParam('product', 0));
             if (!is_null($product->getId())) {
                 $referer = $product->getUrlModel()->getUrl($product, array());
                 $result['messages'][] = $this->__(
                         'Product "%1$s" has not been added. Please add it <a href="%2$s">from product page</a>', $product->getName(), $referer
                 );
             }
         } else {
             $result['blocks'] = $this->getUpdater()->getBlocks();
             $product = Mage::getModel('catalog/product')->load($this->getRequest()->getParam('product', 0));
             if (!is_null($product->getId())) {
                 $result['messages'][] = $this->__(
                         'Product "%1$s" was successfully added to compare list', $product->getName()
                 );
             } else {
                 $result['messages'][] = $this->__('Product was successfully added to compare list');
             }
         }
     } else {
         $result['success'] = false;
         $result['messages'][] = $this->__("Oops something's wrong");
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
  }
  public function updateBlocksAfterACPAction() {
     if ($this->_expireAjax()) {
         return;
     }
     $result = array(
         'success' => true,
         'messages' => array(),
         'blocks' => $this->getUpdater()->getBlocks(),
         'can_shop' => !$this->getOnepage()->getQuote()->isVirtual(),
         'grand_total' => Mage::helper('onestepcheckout')->getGrandTotal($this->getOnepage()->getQuote())
     );
     switch ($this->getRequest()->getParam('action', 'add')) {
         case 'add':
             $result['messages'][] = $this->__('Product was successfully added to the cart');
             break;
         case 'remove':
             $result['messages'][] = $this->__('Product was successfully remove from the cart');
             break;
         default:
     }
     $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($result));
  }

  protected function _customerEmailExists($email, $websiteId = null) {
     $customer = Mage::getModel('customer/customer');
     if ($websiteId) {
         $customer->setWebsiteId($websiteId);
     }
     $customer->loadByEmail($email);
     if ($customer->getId()) {
         return $customer;
     }
     return false;
  }
  private function _isEmailRegistered($email) {
     $model = Mage::getModel('customer/customer');
     $model->setWebsiteId(Mage::app()->getStore()->getWebsiteId())->loadByEmail($email);
     if ($model->getId() == NULL) {
         return false;
     }
     return true;
  }

  public function verificaemailAction() {
     $email = $this->getRequest()->getPost('email', false);
     $validator = new Zend_Validate_EmailAddress();
     $response = array(
       'status' => 'empty'
     );
     if ( !empty($email) ) {
         if ($validator->isValid($email)) {
             if ($this->_isEmailRegistered($email)) {
                 $response['status'] = 'exists';
             } else {
                 $response['status'] = 'empty';
             }
         }
         else {
           $response['status'] = 'invalid';
         }
     }
     $this->getResponse()->setBody(Zend_Json::encode($response));
  }

  public function verificacpfAction()
  {
     $taxvat = $this->getRequest()->getParam('taxvat');
     $response['status'] = 'empty';
     if (!empty($taxvat)) {
         $taxvat = preg_replace("/[^0-9]/", "", $taxvat);
         $storeId = Mage::app()->getStore()->getId();
         $customer = Mage::getResourceModel('customer/customer_collection')
             ->addAttributeToFilter('taxvat', array('eq' => $taxvat))
             ->addAttributeToFilter('store_id', $storeId)
             ->setPageSize(1)
             ->count();
         if ($customer) {
             $response['status'] = 'exists';
         }
     }
     $this->getResponse()->setBody(Zend_Json::encode($response));
  }

  public function verificacepAction() {
     if ($this->getRequest()->getPost()) {
         $cep = $this->getRequest()->getPost('cep', false);
     } else {
         $cep = $this->getRequest()->getQuery('cep', false);
     }
     $cep = preg_replace('/[^\d]/', '', $cep);
     $response['status'] = 'empty';
     try {
         $soapArgs = array(
             'cep' => $cep,
             'encoding' => 'UTF-8',
             'exceptions' => 0
         );
         $clientSoap = new SoapClient(
           "https://apps.correios.com.br/SigepMasterJPA/AtendeClienteService/AtendeCliente?wsdl", array(
             'soap_version' => SOAP_1_1,
             'encoding' => 'utf-8',
             'trace' => true,
             'exceptions' => true,
             'cache_wsdl' => WSDL_CACHE_BOTH,
             'connection_timeout' => 5
         ));
         $resultSoap = $clientSoap->consultaCep($soapArgs);
         if (is_soap_fault($resultSoap)) {
             $response['status'] = 'invalid';
             $response['message'] = 'Soap Fault';
         }
         else {
             $response['status'] = 'exists';
             $response['data'] = array(
                 'uf'              : $resultSoap->return->uf,
                 'cidade'          : $resultSoap->return->cidade,
                 'bairro'          : $resultSoap->return->bairro,
                 'tipo_logradouro' : '',
                 'logradouro'      : $resultSoap->return->end,
             );
         }
     } catch (SoapFault $e) {
       $response['status'] = 'invalid';
       $response['message'] = 'Soap Fault '.$e;
     } catch (Exception $e) {
       $response['status'] = 'invalid';
       $response['message'] = 'Exception '.$e;
     }
     $this->getResponse()->setBody(Zend_Json::encode($response));
  }
}
