<?php
class MOIP_Transparente_Model_Method_Cc extends Mage_Payment_Model_Method_Abstract
{
    const METHOD_CODE = 'moip_cc';
    protected $_code = self::METHOD_CODE;
    protected $_formBlockType = 'transparente/form_cc';
    protected $_infoBlockType = 'transparente/info_cc';
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid = true;
    protected $_canUseInternal = false;
    protected $_canUseCheckout = true;
    protected $_canUseForMultishipping = false;
    protected $_canSaveCc = true;
    protected $_allowCurrencyCode = array('BRL');
    protected $_canFetchTransactionInfo = true;
    public function getPayment()
    {
        return $this->getQuote()->getPayment();
    }
    public function getSession()
    {
        return Mage::getSingleton('transparente/session');
    }
    public function getCheckout()
    {
        return Mage::getSingleton('checkout/session');
    }
    public function getQuote()
    {
        return $this->getCheckout()->getQuote();
    }
    public function assignData($data)
    {
        if (!($data instanceof Varien_Object)) {
            $data = new Varien_Object($data);
        }
        $info           = $this->getInfoInstance();
        $dataNascimento = $data->getMoipCcOwnerExpDay() . "/" . $data->getMoipCcOwnerExpMonth() . "/" . $data->getMoipCcOwnerExpYear();
        if ($data->getMoipCcPaymentInCofre() == 0)
            $use_cofre = 1;
        else
            $use_cofre = 0;
        $additionaldata = array(
            'taxdocument_moip' => preg_replace("/[^0-9]/", "", $data->getMoipCcTaxdocument()),
            'installmentcount_moip' => $data->getMoipCcInstallmentCount(),
            'installmentcountcofre_moip' => $data->getMoipCcCountCofre(),
            //'phonenumber_moip' => $data->getMoipCcPhoneNumber(),
            // 'phoneddd_moip' => preg_replace("/[^0-9]/", "", $data->getMoipCcPhoneCode()),
            // 'birthdate_moip' => $dataNascimento,
            'hash_moip' => $data->getMoipCcEncrypted(),
            'credit_card_cofre_nb' => (string) $data->getMoipCcCofreNb(),
            'save_card' => $data->getMoipCcSaveCard(),
            'use_cofre' => (int) $use_cofre,
            'credit_card_ccv' => $data->getMoipCcCofreId(),
            'fullname_moip' => $data->getMoipCcOwner()
        );
        $info->setCcType($data->getMoipCcType())->setCcLast4(substr($data->getMoipCcNumber(), -4))->setCcNumber($data->getMoipCcNumber())->setCcExpMonth($data->getMoipCcExpMonth())->setCcExpYear($data->getMoipCcExpYear())->setCcCid($data->getMoipCcCid())->setCcOwner($data->getMoipCcOwner())->setAdditionalData(serialize($additionaldata))->save()->setAdditionalInformation(serialize($additionaldata))->save();
        $this->getMethodJuros();
        return $this;
    }
    public function prepareSave()
    {
        $info = $this->getInfoInstance();
        if ($this->_canSaveCc) {
            $info->setCcNumberEnc($info->encrypt($info->getCcNumber()));
        }
        $info->setCcCidEnc($info->encrypt($info->getCcCid()));
        $info->setCcNumber(null)->setCcCid(null);
        return $this;
    }
    public function prepare()
    {
        $info           = $this->getInfoInstance();
        $additionaldata = unserialize($info->getAdditionalData());
        $session        = Mage::getSingleton('checkout/session');
        $session->setMoipData($additionaldata);
    }
    public function validate()
    {
        parent::validate();
        $info           = $this->getInfoInstance();
        $currency_code  = Mage::app()->getStore()->getCurrentCurrencyCode();
        $errorMsg       = false;
        $additionaldata = unserialize($info->getAdditionalData());
        if ($additionaldata['use_cofre'] == 0) {
            $ccNumber = $info->getCcNumber();
            $ccNumber = preg_replace('/[\-\s]+/', '', $ccNumber);
            $info->setCcNumber($ccNumber);
            $ccType = '';
            if ($this->validateCcNum($ccNumber)) {
                $ccTypeRegExpList = array(
                    'VI' => '/^4[0-9]{12}([0-9]{3})?$/',
                    'MC' => '/^5[1-5][0-9]{14}$/',
                    'AE' => '/^3[47][0-9]{13}$/',
                    'DC' => '/^3(?:0[0-5]|[68][0-9])[0-9]{11}$/',
                    'EO' => '/^([0-9])$/',
                    'HI' => '/^([0-9])$/',
                    'HP' => '/^([0-9])$/'
                );
                $specifiedCCType  = $info->getCcType();
                if ($specifiedCCType == 'VI' || $specifiedCCType == "MC" || $specifiedCCType == "AE" || $specifiedCCType == "DC") {
                        $errorMsg = "";
                } else {
                    if ($specifiedCCType)
                        $errorMsg = "";
                    else
                        $errorMsg = Mage::helper('transparente')->__('Ops, n??o consigo processar o seu cart??o confira o n??mero por favor.');
                }
            } else {
                $errorMsg = Mage::helper('transparente')->__('O n??mero do cart??o est?? inv??lido');
            }
            
            if ($errorMsg === false) {
                 if (!$info->getCcOwner()) {
                    $errorMsg = Mage::helper('transparente')->__('O nome do portador do cart??o n??o est?? correto');
                }
            }
            if ($errorMsg === false) {
                if (!isset($additionaldata['hash_moip'])) {
                    $errorMsg = Mage::helper('transparente')->__('Seu cart??o n??o pode ser processado via Moip, por favor escolha outro meio de pagamento.');
                }
            }
            if ($errorMsg === false) {
                if (!$this->getValidaCPF($additionaldata['taxdocument_moip'])) {
                    $errorMsg = Mage::helper('transparente')->__('Informe um CPF v??lido, caso seu cart??o seja empresarial ?? necess??rio informar o seu CPF, para isto desmarque a op????o "Sou o titular do cart??o".');
                }
            }
            // if ($errorMsg === false) {
            //     $phone = $additionaldata['phonenumber_moip'];
            // }
            if ($errorMsg === false) {
                if (!$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
                    $errorMsg = $year . Mage::helper('transparente')->__('Seu Cart??o est?? vencido, verifique se data est?? corretamente preenchida.');
                }
            }
            if ($errorMsg === false) {
                if (!in_array($currency_code, $this->_allowCurrencyCode)) {
                    Mage::throwException(Mage::helper('transparente')->__('O Moip N??o pode Transacionar pedidos feitos em  (' . $currency_code . ') verifique as configura????es de Moeda do seu magento.'));
                }
            }
            if ($errorMsg === false) {
                if (!$additionaldata['installmentcount_moip']) {
                    $errorMsg = Mage::helper('transparente')->__('Selecione o n??mero de parcelas para o pagamento.');
                }
            }
        } elseif ($additionaldata['use_cofre'] == 1) {
            if ($errorMsg === false) {
                if (!$additionaldata['credit_card_ccv']) {
                    $errorMsg = Mage::helper('transparente')->__('Informe o c??digo de seguran??a do cart??o selecionado.');
                }
            }
             if ($errorMsg === false) {
                if (!$additionaldata['installmentcountcofre_moip']) {
                    $errorMsg = Mage::helper('transparente')->__('Selecione o n??mero de parcelas para o pagamento.');
                }
            }
        }
        if ($errorMsg) {
            Mage::throwException($errorMsg);
        }
        return $this;
    }
    public function validateCcNum($ccNumber)
    {
        $cardNumber = strrev($ccNumber);
        $numSum     = 0;
        for ($i = 0; $i < strlen($cardNumber); $i++) {
            $currentNum = substr($cardNumber, $i, 1);
            if ($i % 2 == 1) {
                $currentNum *= 2;
            }
            if ($currentNum > 9) {
                $firstNum   = $currentNum % 10;
                $secondNum  = ($currentNum - $firstNum) / 10;
                $currentNum = $firstNum + $secondNum;
            }
            $numSum += $currentNum;
        }
        return ($numSum % 10 == 0);
    }
    public function getVerificationRegEx()
    {
        $verificationExpList = array(
            'VI' => '/^[0-9]{3}$/',
            'MC' => '/^[0-9]{3}$/',
            'AE' => '/^[0-9]{4}$/',
            'DC' => '/^[0-9]{3}$/',
            'EO' => '/^[0-9]{3}$/',
            'HI' => '/^[0-9]{4}$/'
        );
        return $verificationExpList;
    }
    public function getValidaCPF($cpf = null)
    {
        if (empty($cpf)) {
            return false;
        } else if ($cpf == '00000000000' || $cpf == '11111111111' || $cpf == '22222222222' || $cpf == '33333333333' || $cpf == '44444444444' || $cpf == '55555555555' || $cpf == '66666666666' || $cpf == '77777777777' || $cpf == '88888888888' || $cpf == '99999999999') {
            return false;
        } else {
            for ($t = 9; $t < 11; $t++) {
                for ($d = 0, $c = 0; $c < $t; $c++) {
                    $d += $cpf{$c} * (($t + 1) - $c);
                }
                $d = ((10 * $d) % 11) % 10;
                if ($cpf{$c} != $d) {
                    return false;
                }
            }
            return true;
        }
    }
    protected function _validateExpDate($expYear, $expMonth)
    {
        $date = Mage::app()->getLocale()->date();
        if (!$expYear || !$expMonth || ($date->compareYear($expYear) == 1) || ($date->compareYear($expYear) == 0 && ($date->compareMonth($expMonth) == 1))) {
            return false;
        }
        return true;
    }
    public function getApi()
    {
        $api = Mage::getModel('transparente/api');
        return $api;
    }
    public function getOrderPlaceRedirectUrl()
    {
        ignore_user_abort(true);
        $api                 = $this->getApi();
        $info                = $this->getInfoInstance();
        $quote               = $info->getQuote();
        $additionaldata      = unserialize($info->getAdditionalData());
        $json_order          = $this->getApi()->getDados($quote);
        $IdMoip              = $this->getApi()->getOrderIdMoip($json_order);
        $json_payment        = $this->getApi()->getPaymentJsonCc($info, $quote);
        $payment             = $this->getApi()->generatePayment($json_payment, $IdMoip);
        $additionaldataAfter = array(
            'token_moip' => $json_order,
            'response_moip' => $payment,
            'card_save' => $info->getAdditionalData(),
            'order_moip' => (string) $IdMoip
        );
        $additionaldata      = array_merge($additionaldata, $additionaldataAfter);
        $info->setAdditionalData(serialize($additionaldata))->save();
        $info->setAdditionalInformation(serialize($additionaldata))->save();
       
        $this->prepare();
        if (isset($payment->errors)) {
            foreach ($payment->errors as $key => $value) {
                $erros = (string) $value->description . " " . $erros;
            }
            $session = Mage::getSingleton('checkout/session');
            $session->setMoipError($erros);
                $api->generateLog($json_payment, 'MOIP_ErrorPayment.log');
                $api->generateLog($payment, 'MOIP_ErrorPayment.log');
            return Mage::getUrl('transparente/standard/cancel', array(
                '_secure' => true
            ));
        } else {
            Mage::dispatchEvent('sales_order_place_moip_after',$additionaldataAfter);
            return Mage::getUrl('checkout/onepage/success', array(
                '_secure' => true
            ));
        }
    }
    public function getMethodJuros()
    {
        $quote          = $this->getQuote();
        $info           = $quote->getPayment();
        $additionaldata = unserialize($info->getAdditionalData());
        if ($additionaldata['installmentcount_moip']) {
            $parcela = $additionaldata['installmentcount_moip'];
        } else {
            $parcela = $additionaldata['installmentcountcofre_moip'];
        }
        if (!$quote->getFeeAmount())
            $juros = $this->setJurosMoip($quote, $parcela);
        return $this;
    }
    public function setJurosMoip($quote, $parcela)
    {
        if (!$quote->isVirtual()) {
            $address = $quote->getShippingAddress();
        } else {
            $address = $quote->getBillingAddress();
        }
        $api         = Mage::getSingleton('transparente/api');
        $total_geral = $address->getGrandTotal();
        if ($address->getFeeAmount() == 0) {
            $address->setFeeAmount(0);
            $address->setBaseFeeAmount(0);
            $address->save();
            $juros = $address->getFeeAmount();
            $total = $total_geral - $juros;
            $parcelamento   = $api->getParcelamento();
            if($parcelamento && $parcela){
                $balance        = $parcelamento[$parcela]['total_juros'];
                $address->setFeeAmount($balance);
                $address->setBaseFeeAmount($balance);
                $address->setGrandTotal($address->getGrandTotal() + $address->getFeeAmount());
                $address->setBaseGrandTotal($address->getBaseGrandTotal() + $address->getBaseFeeAmount());
                $address->save();    
            }
            
        }
        return $this;
    }
}