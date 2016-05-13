<?php
namespace Scandesigns\DeliveryCompany\Model\Carrier;

use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Item;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Shipping\Model\Rate\ResultFactory;
use Psr\Log\LoggerInterface;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;

abstract class AbstractDeliveryCompany extends AbstractCarrier implements CarrierInterface
{

    /**
     * @var ResultFactory
     */
    private $_rateResultFactory;

    /**
     * @var MethodFactory
     */
    private $_rateMethodFactory;

    /**
     * @var array
     */
    private $debugs = [];

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        array $data = []
    ) {
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function isTrackingAvailable()
    {
        return false;
    }

    /**
     * Get allowed shipping methods
     *
     * @return array
     */
    public function getAllowedMethods()
    {
        /** @var AbstractDeliveryCompany $caller */
        $caller = get_called_class();
        $this->_logger->warning($caller->_code);
        return [
            $caller->_code => __($this->getConfigData('name'))
        ];
    }

    /**
     * Collect and get rates
     *
     * @param RateRequest $request
     * @return \Magento\Framework\DataObject|bool|null
     */
    public function collectRates(RateRequest $request)
    {

        // Module is not active
        if (! $this->getConfigFlag('active')) {
            $this->debugs[] = 'Not active';
            return $this->notInUse();
        }

        // Country check
        if ($this->getConfigFlag('sallowspecific')) {
            $countries = $this->getConfigData('specificcountry');
            if (! is_array($countries)) {
                $countries = [$countries];
            }

            if (! in_array($request->getDestCountryId(), $countries)) {
                $this->debugs[] = sprintf('Allowed countries %s - Chosen: %s', explode(', ', $countries), $request->getDestCountryId());
                return $this->notInUse();
            }
        }

        // Zipcode check
        if ($this->getConfigFlag('use_specificzipcode')) {
            $zipcodes = $this->getConfigData('specificzipcode');
            if (strpos($zipcodes, ',') !== false) {
                $zipcodes = array_map('trim', explode(',', $zipcodes));
            } else {
                $zipcodes = [trim($zipcodes)];
            }

            if (! in_array($request->getDestPostcode(), $zipcodes)) {
                $this->debugs[] = sprintf('Allowed zipcodes %s - Chosen: %s', explode(', ', $zipcodes), $request->getDestPostcode());
                return $this->notInUse();
            }

        }

        // cubicmeters check and length check
        $totalWeight = 0;
        $totalCubmicmeters = 0;
        foreach ($request->getAllItems() as $item) {
            /* @var $item Item */
            $qty = $item->getTotalQty();

            if ($this->getConfigFlag('use_maxweight')) {
                $totalWeight += ($item->getWeight() * $qty);
            }

            // Area check
            if ($this->getConfigFlag('use_area')) {
                $max_area = $this->getConfigData('area');
                $width = $this->getItemAttribute('width_area', $item);
                $height = $this->getItemAttribute('height_area', $item);
                $area = ($width * 2) + ($height * 2);
                if ( $area > $max_area ) {
                    $this->debugs[] = sprintf('%s product area is %s, max is %s', $item->getSku(), $area, $max_area);
                    return $this->notInUse();
                }
            }

            // Length check
            if ($this->getConfigFlag('use_length')) {
                $length = $this->getItemAttribute($this->getConfigData('length_length'), $item);
                if ($length > $this->getConfigData('length')) {
                    $this->debugs[] = sprintf('%s product is %s long, max is %s', $item->getSku(), $length, $this->getConfigData('length'));
                    return $this->notInUse();
                }
            }

            // Cubicmeters check
            if ($this->getConfigFlag('use_maxcubicmeters')) {
                $width = $this->getItemAttribute($this->getConfigData('width_maxcubicmeters'), $item);
                $height = $this->getItemAttribute($this->getConfigData('height_maxcubicmeters'), $item);
                $depth = $this->getItemAttribute($this->getConfigData('depth_maxcubicmeters'), $item);
                $totalCubmicmeters += ($height * $width * $depth) * $qty;
            }
        }

        if ($this->getConfigFlag('use_maxweight')) {
            if ($totalWeight > $this->getConfigData('maxweight')) {
                $this->debugs[] = sprintf('Total weight is %s max is %s', $totalWeight, $this->getConfigData('maxweight'));
                return $this->notInUse();
            }
        }

        if ($this->getConfigFlag('use_maxcubicmeters')) {
            if ($totalCubmicmeters > $this->getConfigData('maxcubicmeters')) {
                $this->debugs[] = sprintf('Total cubmicmeters is %s max is %s', $totalCubmicmeters, $this->getConfigData('maxcubicmeters'));
                return $this->notInUse();
            }
        }

        /** @var \Magento\Shipping\Model\Rate\Result $result */
        $result = $this->_rateResultFactory->create();

        /** @var \Magento\Quote\Model\Quote\Address\RateResult\Method $method */
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($this->getConfigData('title'));
        $amount = $this->getConfigData('price');
        $method->setPrice($amount);
        $method->setCost($amount);

        $result->append($method);
        return $result;

    }

    /**
     * @param string $code
     * @param Item $item
     * @return string|null
     */
    private function getItemAttribute($code, Item $item)
    {
        if ($value = $item->getCustomAttribute($code)) {
            return $value->getValue();
        }

        $this->debugs[] = sprintf('Product "%s" - could not find custom attribute "%s"', $item->getSku(), $code);
        return null;
    }

    private function notInUse()
    {
        if ($this->getConfigFlag('debug')) {
            foreach($this->debugs as $debug) {
                $this->_logger->debug($debug);
            }
        }

        return false;
    }

}
