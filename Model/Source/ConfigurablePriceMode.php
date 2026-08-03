<?php

namespace Bydn\SoloSearch\Model\Source;

class ConfigurablePriceMode implements \Magento\Framework\Option\ArrayInterface
{
    // Cheapest enabled child's price - falls back to the cheapest child of any status when the
    // configurable has no enabled child at all (see FeedGenerator::resolvePriceSourceProduct()).
    const CHEAPEST_CHILD = 'cheapest_child';

    // Cheapest child's price regardless of status (enabled or disabled).
    const CHEAPEST_CHILD_ANY = 'cheapest_child_any';

    /**
     * Returns the available price resolution strategies for configurable products. There is no
     * "configurable's own price" option: configurables don't have a meaningful price of their own
     * in Magento (it's typically 0/empty), so every strategy here is based on a child's price.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ['value' => self::CHEAPEST_CHILD, 'label' => __('Cheapest enabled child price')],
            ['value' => self::CHEAPEST_CHILD_ANY, 'label' => __('Cheapest child price')],
        ];
    }
}
