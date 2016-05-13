<?php
namespace Scandesigns\DeliveryCompany\Model\Source;

use Magento\Framework\Option\ArrayInterface;

class ProductAttributes implements ArrayInterface
{

    public function __construct()
    {
        
    }

    /**
     * Return array of options as value-label pairs
     *
     * @return array Format: array(array('value' => '<value>', 'label' => '<label>'), ...)
     */
    public function toOptionArray()
    {
        // TODO: Implement toOptionArray() method.
    }

    /**
     * Get options in "key-value" format
     *
     * @return array
     */
    public function toArray()
    {
        return [0 => __('No'), 1 => __('Yes')];
    }

}
