<?php

namespace Webjump\BraspagPagador\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\Sales\Model\OrderFactory;
use Webjump\BraspagPagador\Gateway\Transaction\CreditCard\Config\ConfigInterface;
use Webjump\BraspagPagador\Gateway\Transaction\Base\Config\InstallmentsConfigInterface;
use Webjump\BraspagPagador\Helper\Validator;
use Magento\Directory\Model\RegionFactory;
use Webjump\Braspag\Pagador\Transaction\Api\CreditCard\Send\RequestInterface;
use Webjump\SubscriptionBraspag\Model\RequestFactory;
use Webjump\BraspagPagador\Model\CardTokenRepository;


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
     * @var RegionFactory
     **/
    protected $regionFactory;

    /**
     * @var CardTokenRepository
     **/
    protected $cardTokenRepository;

    public function __construct(
        RequestFactory $requestFactory,
        OrderFactory $orderFactory,
        ConfigInterface $config,
        Validator $validator,
        InstallmentsConfigInterface $installmentsConfig,
        RegionFactory $regionFactory,
        CardTokenRepository $cardTokenRepository
    )
    {
        $this->setRequestFactory($requestFactory);
        $this->setOrderFactory($orderFactory);
        $this->setConfig($config);
        $this->setValidator($validator);
        $this->setInstallmentsConfig($installmentsConfig);
        $this->setRegionFactory($regionFactory);
        $this->setCardTokenRepository($cardTokenRepository);
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
        $orderNumber = $input->getArgument(self::INPUT_KEY_INCREMENT_ID);

        $output->writeln($orderNumber);

        $orderInfo = $this->orderFactory->create()->loadByIncrementId($orderNumber);

        if(!$orderInfo->getId()) {
            $output->writeln('Order ' . $orderNumber . ' not exists');
        }

        $output->writeln($orderInfo->getCustomerName());

        $requestBraspag = $this->braspagRequest($orderInfo);

        $output->writeln($requestBraspag->getPaymentCreditCardHolder());






//        $generator = ServiceLocator::getPackGenerator();
//        $mode = $input->getOption(self::INPUT_KEY_MODE);
//        if ($mode !== self::MODE_MERGE && $mode !== self::MODE_REPLACE) {
//            throw new \InvalidArgumentException("Possible values for 'mode' option are 'replace' and 'merge'");
//        }
//        $locale = $input->getArgument(self::INPUT_KEY_LOCALE);
//        $generator->generate(
//            $input->getArgument(self::INPUT_KEY_SOURCE),
//            $locale,
//            $input->getOption(self::INPUT_KEY_MODE),
//            $input->getOption(self::INPUT_KEY_ALLOW_DUPLICATES)
//        );
//        $output->writeln("<info>Successfully saved $locale language package.</info>");
//        return \Magento\Framework\Console\Cli::RETURN_SUCCESS;
    }


    /**
     * @param $orderInfo \Magento\Sales\Model\Order
     * @return \Webjump\SubscriptionBraspag\Model\Request
     */
    private function braspagRequest($orderInfo)
    {

        $request = $this->getRequestFactory()->create();

        $paymentData = $orderInfo->getPayment();
        
        $customerCardTokenRepository = $this->getCardTokenRepository()->getTokenByCustomerId($orderInfo->getCustomerId());


        
        $request->setMerchantId($this->getConfig()->getMerchantId());
        $request->setMerchantKey($this->getConfig()->getMerchantKey());
        $request->setIsTestEnvironment((boolean) $this->getConfig()->getIsTestEnvironment());
        $request->setMerchantOrderId('CHANGE-CREDITCARD');

        $request->setCustomerName($orderInfo->getCustomerName());
        $request->setCustomerIdentity($orderInfo->getCustomerTaxvat());
        $request->setCustomerIdentityType($this->extractIdentityType($orderInfo));
        $request->setCustomerEmail($orderInfo->getCustomerEmail());
        $request->setCustomerAddressPhone($orderInfo->getBillingAddress()->getTelephone());

        $request->setCustomerAddressStreet($this->getAddressAttribute($orderInfo->getBillingAddress(), $this->getConfig()->getCustomerStreetAttribute()));
        $request->setCustomerAddressNumber($this->getAddressAttribute($orderInfo->getBillingAddress(), $this->getConfig()->getCustomerNumberAttribute()));
        $request->setCustomerAddressComplement($this->getAddressAttribute($orderInfo->getBillingAddress(), $this->getConfig()->getCustomerComplementAttribute()));
        $request->setCustomerAddressZipCode(preg_replace('/[^0-9]/','', $orderInfo->getBillingAddress()->getPostcode()));
        $request->setCustomerAddressDistrict($this->getValidator()->sanitizeDistrict($this->getAddressAttribute($orderInfo->getBillingAddress(), $this->getConfig()->getCustomerDistrictAttribute())));
        $request->setCustomerAddressCity($orderInfo->getBillingAddress()->getCity());
        $request->setCustomerAddressState($orderInfo->getBillingAddress()->getRegionCode());
        $request->setCustomerAddressCountry('BRA');

        $request->setCustomerDeliveryAddressStreet($this->getAddressAttribute($orderInfo->getShippingAddress(), $this->getConfig()->getCustomerStreetAttribute()));
        $request->setCustomerDeliveryAddressNumber($this->getAddressAttribute($orderInfo->getShippingAddress(), $this->getConfig()->getCustomerNumberAttribute()));
        $request->setCustomerDeliveryAddressComplement($this->getAddressAttribute($orderInfo->getShippingAddress(), $this->getConfig()->getCustomerComplementAttribute()));
        $request->setCustomerDeliveryAddressZipCode(preg_replace('/[^0-9]/','', $orderInfo->getShippingAddress()->getPostcode()));
        $request->setCustomerDeliveryAddressDistrict($this->getValidator()->sanitizeDistrict($this->getAddressAttribute($orderInfo->getShippingAddress(), $this->getConfig()->getCustomerDistrictAttribute())));
        $request->setCustomerDeliveryAddressCity($orderInfo->getShippingAddress()->getCity());
        $request->setCustomerDeliveryAddressState($orderInfo->getShippingAddress()->getRegionCode());
        $request->setCustomerDeliveryAddressCountry('BRA');

        $request->setPaymentAmount(0);
        $request->setPaymentCurrency('BRL');
        $request->setPaymentCountry('BRA');
        $request->setPaymentProvider($this->extractProvider($paymentData));
        $request->setPaymentServiceTaxAmount(0);
        $request->setPaymentInstallments(1);
        $request->setPaymentInterest($this->getInstallmentsConfig()->isInterestByIssuer() ? 'ByIssuer' : 'ByMerchant');
        $request->setPaymentCapture((bool) $this->getConfig()->isAuthorizeAndCapture());
        $request->setPaymentAuthenticate((bool) $this->getConfig()->isAuthenticate3DsVbv());
        $request->setReturnUrl($this->getConfig()->getReturnUrl());
        $request->setPaymentSoftDescriptor($this->getConfig()->getSoftDescriptor());
        $request->setPaymentCreditCardCardNumber($paymentData->getCcNumber());
        $request->setPaymentCreditCardHolder($paymentData->getCcOwner());
        $request->setPaymentCreditCardExpirationDate(str_pad($paymentData->getCcExpMonth(), 2, '0', STR_PAD_LEFT) . '/' . $paymentData->getCcExpYear());
        $request->setPaymentCreditCardSecurityCode($paymentData->getCcCid());
        $request->setPaymentCreditCardSaveCard(true);
        $request->setPaymentCreditCardBrand($this->extractPaymentCreditCardBrand($paymentData));

        return $request;
        

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
        $identity = (string) preg_replace('/[^0-9]/','', $subscription->getCustomerTaxvat());
        return (strlen($identity) > 11) ? 'CNPJ' : 'CPF';
    }

    private function getAddressAttribute($address, $attribute)
    {
        if (preg_match('/^street_/', $attribute)) {
            $line = (int) str_replace('street_', '', $attribute);
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
     * @return mixed
     */
    private function getRegionFactory()
    {
        return $this->regionFactory;
    }

    /**
     * @param mixed $regionFactory
     *
     * @return self
     */
    private function setRegionFactory($regionFactory)
    {
        $this->regionFactory = $regionFactory;

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


}
