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

    /**
     * @var \PDO
     */
    static private $db;

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
        return [
            $this->getCarrierCode() => __($this->getConfigData('name'))
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
            $this->addLog('Not active');
            return $this->notInUse();
        }

        // Country check
        if ($this->getConfigFlag('sallowspecific')) {
            $countries = $this->getConfigData('specificcountry');
            if (! is_array($countries)) {
                $countries = [$countries];
            }

            if (! in_array($request->getDestCountryId(), $countries)) {
                $this->addLog(sprintf('Allowed countries %s - Chosen: %s', explode(', ', $countries), $request->getDestCountryId()));
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
                $this->addLog(sprintf('Allowed zipcodes %s - Chosen: %s', explode(', ', $zipcodes), $request->getDestPostcode()));
                return $this->notInUse();
            }

        }

        // cubicmeters check and length check
        $totalWeight = 0;
        $totalCubmicmeters = 0;
        foreach ($request->getAllItems() as $item) {
            /* @var $item Item */
            $qty = $item->getTotalQty();

            // Weight check
            if ($this->getConfigFlag('use_maxweight')) {
                $totalWeight += ($item->getWeight() * $qty);
            }

            // Area check
            if ($this->getConfigFlag('use_area')) {
                $max_area = $this->getConfigData('area');
                $width = $this->getItemAttribute('width_area', $item);
                $height = $this->getItemAttribute('height_area', $item);
                if ($width && $height) {
                    $area = ($width * 2) + ($height * 2);
                    if ($area > $max_area) {
                        $this->addLog(sprintf('%s product area is %s, max is %s', $item->getSku(), $area, $max_area));
                        return $this->notInUse();
                    }
                } else {
                    $this->addLog(sprintf('Width and Height not found for product', $item->getSku()));
                }
            }

            // Length check
            if ($this->getConfigFlag('use_length')) {
                $length = $this->getItemAttribute($this->getConfigData('length_length'), $item);
                if ($length) {
                    if ($length > $this->getConfigData('length')) {
                        $this->addLog(sprintf('%s product is %s long, max is %s', $item->getSku(), $length, $this->getConfigData('length')));
                        return $this->notInUse();
                    }
                } else {
                    $this->addLog(sprintf('Length not found for product "%s"', $item->getSku()));
                }
            }

            // Cubicmeters check
            if ($this->getConfigFlag('use_maxcubicmeters')) {
                $width = $this->getItemAttribute($this->getConfigData('width_maxcubicmeters'), $item);
                $height = $this->getItemAttribute($this->getConfigData('height_maxcubicmeters'), $item);
                $depth = $this->getItemAttribute($this->getConfigData('depth_maxcubicmeters'), $item);
                if ($width && $height && $depth) {
                    $totalCubmicmeters += ($height * $width * $depth) * $qty;
                } else {
                    $this->addLog(sprintf('Width, Height and Depth not found for product', $item->getSku()));
                }
            }
        }

        if ($this->getConfigFlag('use_maxweight')) {
            if ($totalWeight > $this->getConfigData('maxweight')) {
                $this->addLog(sprintf('Total weight is %s max is %s', $totalWeight, $this->getConfigData('maxweight')));
                return $this->notInUse();
            }
        }

        if ($this->getConfigFlag('use_maxcubicmeters')) {
            if ($totalCubmicmeters > $this->getConfigData('maxcubicmeters')) {
                $this->addLog(sprintf('Total cubmicmeters is %s max is %s', $totalCubmicmeters, $this->getConfigData('maxcubicmeters')));
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
        if ($ids = $item->getCustomAttribute('option_ids')) {
            $option = $this->getSingleRow(
                'SELECT option_id FROM catalog_product_option_title WHERE option_id IN (:ids) AND title = ":code" LIMIT 1',
                [
                    'ids' => implode(',', $ids),
                    'code' => $code
                ]
            );

            return $this->getSingleRow(
                'SELECT `value` FROM quote_item_option WHERE code = :option',
                [
                    'option' => $option
                ]
            );
        }

        $this->addLog(sprintf('Product "%s" - could not find custom attribute "%s"', $item->getSku(), $code));
        return null;
    }

    private function addLog($msg)
    {
        if ($this->getConfigFlag('debug')) {
            $this->_logger->debug($msg);
        }
    }

    private function notInUse()
    {
        foreach($this->debugs as $debug) {
            $this->_logger->debug($debug);
        }

        return false;
    }

    private function getSingleRow($query, array $values = [])
    {
        $stmt = $this->getPDO()->prepare($query);
        $stmt->execute($values);
        return $stmt->fetchColumn();
    }

    private function getPDO()
    {
        if (! self::$db) {
            $config = include BP . '/app/etc/env.php';
            $dsn = sprintf(
                'mysql:dbname=%s;host=%s',
                $config['db']['connection']['default']['dbname'],
                $config['db']['connection']['default']['host']
            );

            self::$db = new \PDO(
                $dsn,
                $config['db']['connection']['default']['username'],
                $config['db']['connection']['default']['password']
            );
        }

        return self::$db;
    }

}
