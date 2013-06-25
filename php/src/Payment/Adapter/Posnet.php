<?php
namespace Payment\Adapter;

use \Array2XML;
use \Payment\Request;
use \Payment\Response\PaymentResponse;
use \Payment\Adapter\AdapterInterface;
use \Payment\Adapter\Container\Http;
use \Payment\Exception\UnexpectedResponse;

class Posnet extends Http implements AdapterInterface
{

    private function _buildBaseRequest()
    {
        $config = $this->_config;
        return array(
                    'username' => $config->username,
                    'password' => $config->password,
                    'mid' => $config->client_id,
                    'tid' => $config->terminal_id);
    }

    /**
     *
     * @see \Payment\Adapter\AdapterAbstract::_buildRequest()
     */
    protected function _buildRequest(Request $request, $requestBuilder)
    {
        $rawRequest = call_user_func(array(
                                            $this,
                                            $requestBuilder), $request);
        $xml = Array2XML::createXML('posnetRequest', array_merge($rawRequest, $this->_buildBaseRequest()));
        $data = array(
                    'xmldata' => $xml->saveXml());
        $request->setRawData($xml);
        return http_build_query($data);
    }

    /**
     *
     * @see \Payment\Adapter\AdapterAbstract::_buildPreauthorizationRequest()
     */
    protected function _buildPreauthorizationRequest(Request $request)
    {
        $amount = $this->_formatAmount($request->getAmount());
        $installment = $this->_formatInstallment($request->getInstallment());
        $currency = $this->_formatCurrency($request->getCurrency());
        $expireMonth = $this->_formatExpireDate($request->getExpireMonth(), $request->getExpireYear());
        $requestData = array(
                            'auth' => array(
                                            'ccno' => $request->getCardNumber(),
                                            'expDate' => $expireMonth,
                                            'cvc' => $request->getSecurityCode(),
                                            'amount' => $amount,
                                            'currencyCode' => $currency,
                                            'orderID' => $request->getOrderId(),
                                            'installment' => $installment,
                                            'extraPoint' => "000000",
                                            'multiplePoint' => "000000"));
        return $requestData;
    }

    /**
     *
     * @see \Payment\Adapter\AdapterAbstract::_buildPostAuthorizationRequest()
     */
    protected function _buildPostAuthorizationRequest(Request $request)
    {
        $amount = $this->_formatAmount($request->getAmount());
        $currency = $this->_formatCurrency($request->getCurrency());
        $installment = $this->_formatInstallment($request->getInstallment());
        $requestData = array(
                            'capt' => array(
                                            'hostLogKey' => $request->getTransactionId(),
                                            'authCode' => $request->getAuthCode(),
                                            'amount' => $amount,
                                            'currencyCode' => $currency,
                                            'installment' => $installment,
                                            'extraPoint' => "000000",
                                            'multiplePoint' => "000000"));
        return $requestData;
    }

    /**
     *
     * @see \Payment\Adapter\AdapterAbstract::_buildSaleRequest()
     */
    protected function _buildSaleRequest(Request $request)
    {
        $expireMonth = $this->_formatExpireDate($request->getExpireMonth(), $request->getExpireYear());
        $amount = $this->_formatAmount($request->getAmount());
        $currency = $this->_formatCurrency($request->getCurrency());
        $installment = $this->_formatInstallment($request->getInstallment());
        $requestData = array(
                            'sale' => array(
                                            'ccno' => $request->getCardNumber(),
                                            'expDate' => $expireMonth,
                                            'cvc' => $request->getSecurityCode(),
                                            'amount' => $amount,
                                            'currencyCode' => $currency,
                                            'orderID' => $request->getOrderId(),
                                            'installment' => $installment,
                                            'extraPoint' => "000000",
                                            'multiplePoint' => "000000"));
        return $requestData;
    }

    /**
     *
     * @see \Payment\Adapter\AdapterAbstract::_buildRefundRequest()
     */
    protected function _buildRefundRequest(Request $request)
    {
        $amount = $this->_formatAmount($request->getAmount());
        $currency = $this->_formatCurrency($request->getCurrency());
        $requestData = array(
                            'return' => array(
                                            'hostLogKey' => $request->getTransactionId(),
                                            'amount' => $amount,
                                            'currencyCode' => $currency));
        return $requestData;
    }

    /**
     *
     * @see \Payment\Adapter\AdapterAbstract::_buildCancelRequest()
     */
    protected function _buildCancelRequest(Request $request)
    {
        $requestData = array(
                            'reverse' => array(
                                            'transaction' => "sale",
                                            'hostLogKey' => $request->getTransactionId(),
                                            'authCode' => $request->getAuthCode()));
        return $requestData;
    }

    /**
     *
     * @see \Payment\Adapter\AdapterAbstract::_parseResponse()
     */
    protected function _parseResponse($rawResponse)
    {
        $response = new PaymentResponse();
        try{
            $xml = new \SimpleXmlElement($rawResponse);
        }catch(\Exception $e){
            throw new UnexpectedResponse('Provider is returned unexpected response. Response data:' . $rawResponse);
        }
        $response->setIsSuccess((int)$xml->approved > 0);
        $response->setResponseCode((string)$xml->respCode);
        if (!$response->isSuccess()){
            $errorMessages = array();
            if (property_exists($xml, 'respCode')){
                $errorMessages[] = sprintf('Error: %s', (string)$xml->respCode);
            }
            if (property_exists($xml, 'respText')){
                $errorMessages[] = sprintf('Error Message: %s ', (string)$xml->respText);
            }
            $errorMessage = implode(' ', $errorMessages);
            $response->setResponseMessage($errorMessage);
        }else{
            $response->setResponseMessage('Success');
            /**
             *
             * @todo posnet servisi response içinde order id'yi döndürmüyor. Bu datayı request'ten almamız gerekiyor.
             */
            if (property_exists($xml, 'orderId')){
                $response->setOrderId((string)$xml->orderId);
            }
            $response->setTransactionId((string)$xml->hostlogkey);
            $response->setAuthCode($xml->authCode);
        }
        $response->setRawData($rawResponse);
        return $response;
    }

    protected function _formatExpireDate($month, $year)
    {
        return sprintf('%02s%02s', substr($year, 2, 2), $month); // YYMM
    }

    protected function _formatInstallment($installment)
    {
        return sprintf('%02s', $installment);
    }

    protected function _formatAmount($amount, $reverse = false)
    {
        return (int)$amount * 100;
    }

    protected function _formatCurrency($currency)
    {
        switch($currency){
            case self::CURRENCY_TRY:
                return 'TR';
            case self::CURRENCY_USD:
                return 'US';
            case self::CURRENCY_EUR:
                return 'EU';
            default:
                return 'TR';
        }
    }
}
