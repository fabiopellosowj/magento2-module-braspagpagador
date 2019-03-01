<?php

namespace Webjump\BraspagPagador\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Sales\Model\OrderFactory;
use Webjump\Braspag\Pagador\Transaction\Resource\CreditCard\Send\Request;
use Webjump\BraspagPagador\Gateway\Transaction\CreditCard\Config\ConfigInterface;
use Webjump\BraspagPagador\Gateway\Transaction\Base\Config\InstallmentsConfigInterface;
use Webjump\BraspagPagador\Helper\Validator;
use Webjump\SubscriptionBraspag\Model\RequestFactory;
use Webjump\BraspagPagador\Model\CardTokenRepository;
use Webjump\Braspag\Factories\SalesCommandFactory;
use Webjump\Braspag\Factories\ClientHttpFactory;


class AuthAndCaptureOrder extends Command
{

    /**#@+
     * Keys and shortcuts for input arguments and options
     */
    const INPUT_KEY_INCREMENT_ID = 'order_number';

    /**
     * @var RequestFactory
     **/
    protected $requestFactory;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * @var ConfigInterface
     **/
    protected $config;

    /**
     * @var Validator
     **/
    protected $validator;

    /**
     * @var InstallmentsConfigInterface
     **/
    protected $installmentsConfig;

    /**
     * @var CardTokenRepository
     **/
    protected $cardTokenRepository;

    /**
     * @var SalesCommandFactory
     **/
    protected $braspagSalesCommand;

    /**
     * @var \Magento\Sales\Model\Order
     */
    protected $order;

    protected $handler;

    protected $_curl;

    public function __construct(
        RequestFactory $requestFactory,
        OrderFactory $orderFactory,
        ConfigInterface $config,
        Validator $validator,
        InstallmentsConfigInterface $installmentsConfig,
        CardTokenRepository $cardTokenRepository,
        SalesCommandFactory $braspagSalesCommand,
        \Magento\Framework\HTTP\Client\Curl $curl
    )
    {
        $this->setRequestFactory($requestFactory);
        $this->setOrderFactory($orderFactory);
        $this->setConfig($config);
        $this->setValidator($validator);
        $this->setInstallmentsConfig($installmentsConfig);
        $this->setCardTokenRepository($cardTokenRepository);
        $this->setBraspagSalesCommand($braspagSalesCommand);
        $this->_curl = $curl;
        parent::__construct();
    }

    /**
     * Command for authorize and capture by order number (increment_id)
     */
    protected function configure()
    {
        $this->setName('braspag:maintenance:authorizeandcapture')
            ->setDescription('Command to authorize and capture by order number (increment_id)');
        $this->setDefinition([
            new InputArgument(
                self::INPUT_KEY_INCREMENT_ID,
                InputArgument::REQUIRED,
                'Order Increment ID. Example: 100009999'
            )
        ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {

//        $orders = [
////            1000010317,
//            1000009930,
//            1000009861,
//            1000009858,
//            1000009855,
//            1000009840,
//            1000009837,
//            1000009831,
//            1000009825,
//            1000009819,
//            1000009816,
//            1000009795,
//            1000009780,
//            1000009696,
//            1000009684,
//            1000009678,
//            1000009675,
//            1000009663,
//            1000009624,
//            1000009609,
//            1000009576,
//            1000009570,
//            1000009567,
//            1000009555,
//            1000009549,
//            1000009531,
//            1000009474,
//            1000009465,
//            1000009453,
//            1000009420,
//            1000009408,
//            1000009399,
//            1000009348,
//            1000009315,
//            1000009282,
//            1000009255,
//            1000009195,
//            1000009189,
//            1000009039,
//            1000008955,
//            1000008688,
//            1000008655,
//            1000008391,
//            1000007017
//        ];


        $orders[] = $input->getArgument(self::INPUT_KEY_INCREMENT_ID);

        foreach ($orders as $orderNumber) {

            $orderInfo = $this->getOrderFactory()->create()->loadByIncrementId($orderNumber);

            if (!$orderInfo->getId()) {
                $output->writeln('Order ' . $orderNumber . ' not exists');
            }

            $output->writeln('ORDER: ' . $orderNumber);
            $output->writeln('CLIENTE: ' . $orderInfo->getCustomerName());
            $output->writeln('REQUEST:');

            $paramsRequest = $this->buildBraspagRequest($orderInfo);

            $output->writeln($paramsRequest);
            $output->writeln('XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX');

        }

    }

    /**
     * @return array
     * @throws \Magento\Framework\Exception\CouldNotSaveException
     */
    private function buildBraspagRequest($orderInfo)
    {

        $request = $this->getRequestFactory()->create();

        $paymentData = $orderInfo->getPayment();

        $customerCardToken = $this->getCardTokenRepository()->getTokenByCustomerId($orderInfo->getCustomerId());

        if ($paymentData->getMethod() != 'braspag_pagador_creditcard') {
            throw new \Magento\Framework\Exception\CouldNotSaveException(__('Order ' . $orderInfo->getIncrementId() . ' . Payment method invalid. Allow: Braspag Credit Card - ‌braspag_pagador_creditcard'));
        }

        if (!$customerCardToken) {
            throw new \Magento\Framework\Exception\CouldNotSaveException(__('Order ' . $orderInfo->getIncrementId() . ' . Customer card token not exists. This funcionallity is only for customer with credit card token saved.'));
        }

        $params = [
            'merchantOrderId' => $orderInfo->getIncrementId(),
            'customer' => [
                'name' => $orderInfo->getCustomerName(),
                'identity' => $orderInfo->getCustomerIdentity(),
                'identityType' => $orderInfo->getCustomerIdentityType(),
                'email' => $orderInfo->getCustomerEmail(),
                'birthDate' => $orderInfo->getCustomerBirthDate(),
                'phone' => $orderInfo->getCustomerAddressPhone(),
                'address' => [
                    'street' => $this->getAddressAttribute($orderInfo->getBillingAddress(), $this->getConfig()->getCustomerStreetAttribute()),
                    'number' => $this->getAddressAttribute($orderInfo->getBillingAddress(), $this->getConfig()->getCustomerNumberAttribute()),
                    'complement' => $this->getAddressAttribute($orderInfo->getBillingAddress(), $this->getConfig()->getCustomerComplementAttribute()),
                    'zipCode' => preg_replace('/[^0-9]/', '', $orderInfo->getBillingAddress()->getPostcode()),
                    'district' => $this->getValidator()->sanitizeDistrict($this->getAddressAttribute($orderInfo->getBillingAddress(), $this->getConfig()->getCustomerDistrictAttribute())),
                    'city' => $orderInfo->getBillingAddress()->getCity(),
                    'state' => $orderInfo->getBillingAddress()->getRegionCode(),
                    'country' => 'BRA',
                ],
                'deliveryAddress' => [
                    'street' => $this->getAddressAttribute($orderInfo->getShippingAddress(), $this->getConfig()->getCustomerStreetAttribute()),
                    'number' => $this->getAddressAttribute($orderInfo->getShippingAddress(), $this->getConfig()->getCustomerNumberAttribute()),
                    'complement' => $this->getAddressAttribute($orderInfo->getShippingAddress(), $this->getConfig()->getCustomerComplementAttribute()),
                    'zipCode' => preg_replace('/[^0-9]/', '', $orderInfo->getShippingAddress()->getPostcode()),
                    'district' => $this->getValidator()->sanitizeDistrict($this->getAddressAttribute($orderInfo->getShippingAddress(), $this->getConfig()->getCustomerDistrictAttribute())),
                    'city' => $orderInfo->getShippingAddress()->getCity(),
                    'state' => $orderInfo->getShippingAddress()->getRegionCode(),
                    'country' => 'BRA',
                ]
            ],
            'payment' => [
                'type' => 'CreditCard',
                'amount' => $this->getOrderAmount($orderInfo),
                'currency' => 'BRL',
                'country' => 'BRA',
                'provider' => $this->extractProvider($paymentData),
                'serviceTaxAmount' => 0,
                'installments' => $this->getInstallmentsConfig()->getInstallmentsNumber(),
                'interest' => $this->getInstallmentsConfig()->isInterestByIssuer() ? 'ByIssuer' : 'ByMerchant',
                'capture' => 1,
                'authenticate' => (bool)$this->getConfig()->isAuthenticate3DsVbv(),
                'returnUrl' => $this->getConfig()->getReturnUrl(),
                'softDescriptor' => __('Braspag authorize and capture old orders that was not paid.'),
                'creditcard' => [
                    'cardToken' => $customerCardToken,
                    'brand' => $this->extractPaymentCreditCardBrand($paymentData),
                ],
                'extraDataCollection' => $orderInfo->getPaymentExtraDataCollection()
            ]
        ];

        return json_encode($params, JSON_PRETTY_PRINT);

    }

    /**
     * @return OrderFactory
     */
    private function getOrderFactory()
    {
        return $this->orderFactory;
    }

    /**
     * @param OrderFactory $orderFactory
     */
    private function setOrderFactory($orderFactory)
    {
        $this->orderFactory = $orderFactory;
    }

    private function extractIdentityType($subscription)
    {
        $identity = (string)preg_replace('/[^0-9]/', '', $subscription->getCustomerTaxvat());
        return (strlen($identity) > 11) ? 'CNPJ' : 'CPF';
    }

    private function getAddressAttribute($address, $attribute)
    {
        if (preg_match('/^street_/', $attribute)) {
            $line = (int)str_replace('street_', '', $attribute);
            return $address->getStreetLine($line);
        }

        $address->getData($attribute);
    }

    private function extractProvider($paymentData)
    {
        list($provider) = array_pad(explode('-', $paymentData->getCcType(), 2), 2, null);

        return $provider;
    }

    /**
     * @@SuppressWarnings("unused")
     */
    private function extractPaymentCreditCardBrand($paymentData)
    {
        list($provider, $brand) = array_pad(explode('-', $paymentData->getCcType(), 2), 2, null);

        return ($brand) ? $brand : 'Visa';
    }

    /**
     * @return mixed
     */
    private function getRequestFactory()
    {
        return $this->requestFactory;
    }

    /**
     * @param mixed $requestFactory
     *
     * @return self
     */
    private function setRequestFactory($requestFactory)
    {
        $this->requestFactory = $requestFactory;

        return $this;
    }

    /**
     * @return mixed
     */
    private function getConfig()
    {
        return $this->config;
    }

    /**
     * @param mixed $config
     *
     * @return self
     */
    private function setConfig($config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @return mixed
     */
    private function getValidator()
    {
        return $this->validator;
    }

    /**
     * @param mixed $validator
     *
     * @return self
     */
    private function setValidator($validator)
    {
        $this->validator = $validator;

        return $this;
    }

    /**
     * @return mixed
     */
    private function getInstallmentsConfig()
    {
        return $this->installmentsConfig;
    }

    /**
     * @param mixed $installmentsConfig
     *
     * @return self
     */
    private function setInstallmentsConfig($installmentsConfig)
    {
        $this->installmentsConfig = $installmentsConfig;

        return $this;
    }

    /**
     * @return CardTokenRepository
     */
    private function getCardTokenRepository()
    {
        return $this->cardTokenRepository;
    }

    /**
     * @param CardTokenRepository $cardTokenRepository
     */
    private function setCardTokenRepository($cardTokenRepository)
    {
        $this->cardTokenRepository = $cardTokenRepository;
    }

    /**
     * @return SalesCommandFactory
     */
    private function getBraspagSalesCommand()
    {
        return $this->braspagSalesCommand;
    }

    /**
     * @param SalesCommandFactory $braspagSalesCommand
     */
    private function setBraspagSalesCommand($braspagSalesCommand)
    {
        $this->braspagSalesCommand = $braspagSalesCommand;
    }

    private function getOrderAmount($orderInfo)
    {
        $grandTotal = $orderInfo->getGrandTotal() * 100;
        return str_replace('.', '', $grandTotal);
    }


}
