<?php

namespace Bydn\SoloSearch\Block\Adminhtml\Form\Field;

use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

class FieldMapping extends AbstractFieldArray
{
    /**
     * @var AttributeSelect
     */
    private $attributeRenderer;

    /**
     * {@inheritdoc}
     */
    protected function _prepareToRender()
    {
        $this->addColumn('feed_field', [
            'label' => __('SoloSearch Field'),
            'class' => 'required-entry',
        ]);
        $this->addColumn('attribute_code', [
            'label' => __('Magento Attribute'),
            'renderer' => $this->getAttributeRenderer(),
        ]);

        $this->_addAfter = false;
        $this->_addButtonLabel = __('Add Field');
    }

    /**
     * Pre-selects the current attribute_code value in the rendered select
     *
     * {@inheritdoc}
     * @throws LocalizedException
     */
    protected function _prepareArrayRow(DataObject $row): void
    {
        $attributeCode = $row->getAttributeCode();
        $options = [];

        if ($attributeCode !== null) {
            $options['option_' . $this->getAttributeRenderer()->calcOptionHash($attributeCode)] = 'selected="selected"';
        }

        $row->setData('option_extra_attrs', $options);
    }

    /**
     * @return AttributeSelect
     * @throws LocalizedException
     */
    private function getAttributeRenderer()
    {
        if (!$this->attributeRenderer) {
            $this->attributeRenderer = $this->getLayout()->createBlock(
                AttributeSelect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
        }

        return $this->attributeRenderer;
    }
}
