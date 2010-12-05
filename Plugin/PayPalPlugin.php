<?php

namespace Bundle\PayPalPaymentBundle\Plugin;

use Bundle\PayPalPaymentBundle\Gateway\Response;

use Bundle\PayPalPaymentBundle\Gateway\ErrorResponse;

use Bundle\PaymentBundle\Plugin\QueryablePluginInterface;
use Bundle\PaymentBundle\BrowserKit\Request;
use Bundle\PaymentBundle\Plugin\GatewayPlugin;
use Bundle\PayPalPaymentBundle\Authentication\AuthenticationStrategyInterface;
use Bundle\PayPalPaymentBundle\Plugin\Exception\InvalidPayerException;
use Bundle\PaymentBundle\Entity\FinancialTransaction;
use Bundle\PaymentBundle\Plugin\Exception\FinancialException;
use Bundle\PaymentBundle\Plugin\Exception\InternalErrorException;
use Bundle\PaymentBundle\Plugin\Exception\CommunicationException;
use Bundle\PaymentBundle\Model\FinancialTransactionInterface;

/**
 * Implements the NVP API but does not perform any actual transactions
 * 
 * @see https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/howto_api_reference
 * @author Johannes M. Schmitt <schmittjoh@gmail.com>
 */
abstract class PayPalPlugin extends GatewayPlugin implements QueryablePluginInterface
{
    const API_VERSION = '65.1';
    
    protected $authenticationStrategy;
    protected $currentTransaction;
    
    public function __construct(AuthenticationStrategyInterface $authenticationStrategy, $isDebug)
    {
        parent::__construct($isDebug);
        
        $this->authenticationStrategy = $authenticationStrategy;
    }
    
    public function requestAddressVerify($email, $street, $postalCode)
    {
        return $this->request(array(
            'METHOD' => 'AddressVerify',
            'EMAIL'  => $email,
            'STREET' => $street,
            'ZIP'    => $postalCode,
        ));
    }
    
    public function requestBillOutstandingAmount($profileId, $amt = null, $note = null)
    {
        $parameters = array(
            'METHOD' => 'BillOutstandingAmount',
            'PROFILEID' => $profileId,
        );
        
        if (null !== $amt) {
            $parameters['AMT'] = $amt;
        }
        if (null !== $note) {
            $parameters['NOTE'] = $note;
        }
        
        return $this->request($parameters);
    }
    
    public function requestCreateRecurringPaymentsProfile($token)
    {
        return $this->request(array(
            'METHOD' => 'CreateRecurringPaymentsProfile',
            'TOKEN' => $token,
        ));
    }
    
    public function requestDoAuthorization($transactionId, $amt, $transactionEntity = null, $currencyCode = null)
    {
        $parameters = array(
            'METHOD' => 'DoAuthorization',
            'TRANSACTIONID' => $transactionId,
            'AMT' => $amt,
        );
        
        if (null !== $transactionEntity) {
            $parameters['TRANSACTIONENTITY'] = $transactionEntity;
        }
        if (null !== $currencyCode) {
            $parameters['CURRENCYCODE'] = $currencyCode;
        }
        
        return $this->request($parameters);
    }
    
    public function requestDoCapture($authorizationId, $amount, $completeType, $currencyCode = null, $invNum = null, $note = null, $softDescriptor = null)
    {
        $parameters = array(
            'METHOD' => 'DoCapture',
            'AUTHORIZATIONID' => $authorizationId,
            'AMT' => $amount,
            'COMPLETETYPE' => $completeType,
        );
        
        if (null !== $currencyCode) {
            $parameters['CURRENCYCODE'] = $currencyCode;
        }
        if (null !== $invNum) {
            $parameters['INVNUM'] = $invNum;
        }
        if (null !== $note) {
            $parameters['NOTE'] = $note;
        }
        if (null !== $softDescriptor) {
            $parameters['SOFTDESCRIPTOR'] = $softDescriptor;
        }
        
        return $this->request($parameters);
    }
    
    public function requestDoDirectPayment($ipAddress, $paymentAction = null, $returnFmfDetails = null)
    {
        $parameters = array(
            'METHOD' => 'DoDirectPayment',
            'IPADDRESS' => $ipAddress,
        );
        
        if (null !== $paymentAction) {
            $parameters['PAYMENTACTION'] = $paymentAction;
        }
        if (null !== $returnFmfDetails) {
            $parameters['RETURNFMFDETAILS'] = $returnFmfDetails;
        }
        
        return $this->request($parameters);
    }
    
    public function requestDoExpressCheckoutPayment($token, $amount, $paymentAction, $payerId, array $optionalParameters = array())
    {
        return $this->request(array_merge($optionalParameters, array(
            'METHOD' => 'DoExpressCheckoutPayment',
            'TOKEN'  => $token,
            'PAYMENTREQUEST_0_AMT' => $amount,
            'PAYMENTREQUEST_0_PAYMENTACTION' => $paymentAction,
            'PAYERID' => $payerId,
        )));
    }
    
    /**
     * Initiates an ExpressCheckout payment process
     * 
     * Optional parameters can be found here:
     * https://cms.paypal.com/us/cgi-bin/?cmd=_render-content&content_ID=developer/e_howto_api_nvp_r_SetExpressCheckout
     * 
     * @param float $amount
     * @param string $returnUrl
     * @param string $cancelUrl
     * @param array $optionalParameters
     * @return Response
     */
    public function requestSetExpressCheckout($amount, $returnUrl, $cancelUrl, array $optionalParameters = array())
    {
        $parameters = array_merge($optionalParameters, array(
            'METHOD' => 'SetExpressCheckout',
            'PAYMENTREQUEST_0_AMT' => $amount,
            'RETURNURL' => $returnUrl,
            'CANCELURL' => $cancelUrl,
        ));
        
        return $this->request($parameters);
    }
    
    public function requestGetExpressCheckoutDetails($token)
    {
        return $this->request(array(
            'METHOD' => 'GetExpressCheckoutDetails',
            'TOKEN'  => $token,
        ));
    }
    
    public function requestGetTransactionDetails($transactionId)
    {
        return $this->request(array(
            'METHOD' => 'GetTransactionDetails',
            'TRANSACTIONID' => $transactionId,
        ));
    }
    
    public function request(array $parameters)
    {
        // include some default parameters
        $parameters['VERSION'] = self::API_VERSION;
        
        // setup request, and authenticate it
        $request = new Request(
            'https://api-3t'.($this->isDebug()?'.sandbox':'').'.paypal.com/nvp',
            'POST',
            $parameters
        );
        $this->authenticationStrategy->authenticate($request);
        
        $response = parent::request($request);
        $parameters = array();
        parse_str($response->getContent(), $parameters);
        
        $paypalResponse = new Response($parameters);
        if (false === $paypalResponse->isSuccess()) {
            $ex = new FinancialException('PayPal-Response was not successful: '.$paypalResponse);
            $ex->setFinancialTransaction($this->currentTransaction);
            $this->currentTransaction->setResponseCode($paypalResponse->body->get('ACK'));
            $this->currentTransaction->setReasonCode($paypalResponse->body->get('L_ERRORCODE0'));
            
            throw $ex;
        }
        
        return $paypalResponse;
    }
}