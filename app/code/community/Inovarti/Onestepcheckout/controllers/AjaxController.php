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

  public function indexAction() {
    echo "CheckoutPlus RESTFul API";
  }

  public function cepAction() {
    if ($this->getRequest()->isGet() && $this->getRequest()->getParams()) {
      $params = $this->getRequest()->getParams();
      if (array_key_exists('consulta', $params)) {
        $value = (int) $params['consulta'];
        $this->getResponse()->setBody(Zend_Json::encode($this->API_CEP_Consulta($value)));
      }
      else {
        // Exibe Documentação
      }
    }
    else {
      // Exibe Documentação
    }
  }

  public function clienteAction() {
    if ($this->getRequest()->isGet() && $this->getRequest()->getParams()) {
      $params = $this->getRequest()->getParams();
      if (array_key_exists('consulta', $params)) {
        switch($params['consulta']) {
          case 'cpf':
            $value = (int) $params['numero'];
            $this->getResponse()->setBody(Zend_Json::encode($this->API_Cliente_Consulta_Cpf($value)));
            break;
          case 'email':
            $value = (string) $params['endereco'];
            $this->getResponse()->setBody(Zend_Json::encode($this->API_Cliente_Consulta_Email($value)));
            break;
          default: echo 'Nenhuma informação selecionada.';
        }
      }
      else {
        // Exibe Documentação
      }
    }
    else {
      // Exibe Documentação
    }
  }

  protected function API_Cliente_Consulta_Cpf($cpf) {
    $response['status'] = 'empty';
    if (!empty($cpf)) {
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
    return $response;
  }

  protected function API_Cliente_Consulta_Email($email) {
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
    return $response;
  }

  protected function API_Cep_Consulta($cep) {
    $response['status'] = 'empty';
    if(!empty($cep)) {
      $soapURI = "https://apps.correios.com.br/SigepMasterJPA/AtendeClienteService/AtendeCliente?wsdl";
      if ( class_exists("SOAPClient") ) {
        if ( $this->ping($soapURI) ) {
          try {
            $soapArgs = array(
              'cep' => $cep,
              'encoding' => 'UTF-8',
              'exceptions' => 0
            );
            $clientSoap = new SoapClient(
              $soapURI, array(
                'soap_version' => SOAP_1_1,
                'encoding' => 'utf-8',
                'trace' => true,
                'exceptions' => true,
                'cache_wsdl' => WSDL_CACHE_BOTH,
                'connection_timeout' => 5
              )
            );
            $resultSoap = $clientSoap->consultaCep($soapArgs);
            if (is_soap_fault($resultSoap)) {
              $response['status'] = 'invalid';
              $response['message'] = 'Soap Fault';
            }
            else {
              $response['status'] = 'exists';
              $response['data'] = array(
                'uf'              => $resultSoap->return->uf,
                'cidade'          => $resultSoap->return->cidade,
                'bairro'          => $resultSoap->return->bairro,
                'tipo_logradouro' => '',
                'logradouro'      => $resultSoap->return->end,
              );
            }
          } catch (SoapFault $e) {
            $response['status'] = 'invalid';
            $response['message'] = 'Soap Fault '.$e;
          } catch (Exception $e) {
            $response['status'] = 'invalid';
            $response['message'] = 'Exception '.$e;
          }
        }
        else {
          $response['status'] = 'invalid';
          $response['message'] = 'Webservice SOAP dos Correios bloqueado ou indisponível.';
          Mage::log('Webservice SOAP dos Correios bloqueado ou indisponível.', null, 'onestepcheckout.log');
        }
      }
      else {
        $response['status'] = 'invalid';
        $response['message'] = 'Módulo SOAPClient desabilitado no PHP.';
        Mage::log('Módulo SOAPClient desabilitado no PHP.', null, 'onestepcheckout.log');
      }
    }
    return $response;
  }

  private function _isEmailRegistered($email) {
     $model = Mage::getModel('customer/customer');
     $model->setWebsiteId(Mage::app()->getStore()->getWebsiteId())->loadByEmail($email);
     if ($model->getId() == NULL) {
         return false;
     }
     return true;
  }

   protected function ping ($host, $timeout = 1) {
       /* ICMP ping packet with a pre-calculated checksum */
       $package = "\x08\x00\x7d\x4b\x00\x00\x00\x00PingHost";
       $socket = socket_create(AF_INET, SOCK_RAW, 1);
       socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $timeout, 'usec' => 0));
       socket_connect($socket, $host, null);
       $ts = microtime(true);
       socket_send($socket, $package, strLen($package), 0);
       if (socket_read($socket, 255)) {
           $result = true;
       } else {
           $result = false;
       }
       socket_close($socket);
       return $result;
   }
}
