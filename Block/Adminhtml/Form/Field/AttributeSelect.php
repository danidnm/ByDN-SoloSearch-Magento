<?php

namespace Bydn\SoloSearch\Block\Adminhtml\Form\Field;

class AttributeSelect extends \Magento\Framework\View\Element\Html\Select
{
    // Static product columns (not EAV attributes, so they never appear in the attribute
    // collection below) that are still safely readable via Product::getData() in
    // ProductFieldsBuilder::getProductAttributeValue() and are useful enough to offer here.
    const EXTRA_STATIC_OPTIONS = [
        ['value' => 'type_id', 'label' => 'Product Type (type_id)'],
    ];

    /**
     * @var \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory
     */
    private $attributeCollectionFactory;

    /**
     * @param \Magento\Framework\View\Element\Context $context
     * @param \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $attributeCollectionFactory
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Context $context,
        \Magento\Catalog\Model\ResourceModel\Product\Attribute\CollectionFactory $attributeCollectionFactory,
        array $data = []
    ) {
        $this->attributeCollectionFactory = $attributeCollectionFactory;
        parent::__construct($context, $data);
    }

    /**
     * Required by AbstractFieldArray column renderers
     *
     * @param string $value
     * @return $this
     */
    public function setInputName($value)
    {
        return $this->setName($value);
    }

    /**
     * Required by AbstractFieldArray column renderers
     *
     * @param string $value
     * @return $this
     */
    public function setInputId($value)
    {
        return $this->setId($value);
    }

    /**
     * {@inheritdoc}
     */
    public function _toHtml()
    {
        if (!$this->getOptions()) {
            $this->setOptions($this->getSourceOptions());
        }

        return parent::_toHtml();
    }

    /**
     * Builds the option list from EXTRA_STATIC_OPTIONS plus every catalog product attribute,
     * excluding those already used for a structural feed field (see
     * ProductFieldsBuilder::STRUCTURAL_FEED_FIELDS) - mapping them again here would have no effect.
     *
     * @return array
     */
    private function getSourceOptions()
    {
        $attributes = $this->attributeCollectionFactory->create();
        $reservedAttributeCodes = array_merge(['sku'], \Bydn\SoloSearch\Model\ProductFieldsBuilder::STRUCTURAL_ATTRIBUTE_CODES);

        $options = self::EXTRA_STATIC_OPTIONS;

        foreach ($attributes as $attribute) {
            if (in_array($attribute->getAttributeCode(), $reservedAttributeCodes, true)) {
                continue;
            }

            $options[] = [
                'value' => $attribute->getAttributeCode(),
                'label' => sprintf('%s (%s)', (string) $attribute->getDefaultFrontendLabel(), $attribute->getAttributeCode()),
            ];
        }

        usort($options, function ($optionA, $optionB) {
            return strcasecmp($optionA['label'], $optionB['label']);
        });

        return $options;
    }
}
