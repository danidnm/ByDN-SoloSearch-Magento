<?php

namespace Bydn\SoloSearch\Block;

class Widget extends \Magento\Framework\View\Element\Template
{
    /**
     * @var \Bydn\SoloSearch\Helper\Config
     */
    private $config;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Bydn\SoloSearch\Helper\Config $config
     * @param array $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Bydn\SoloSearch\Helper\Config $config,
        array $data = []
    ) {
        $this->config = $config;
        parent::__construct($context, $data);
    }

    /**
     * Renders nothing when the widget embed is disabled, or when the Search Engine ID hasn't
     * been configured yet - a half-configured store must not emit a broken script tag.
     *
     * {@inheritdoc}
     */
    protected function _toHtml()
    {
        if (!$this->config->isWidgetEnabled() || $this->getSearchEngineId() === '' || $this->getScriptUrl() === '') {
            return '';
        }

        return parent::_toHtml();
    }

    /**
     * @return string
     */
    public function getScriptUrl()
    {
        return $this->config->getWidgetScriptUrl();
    }

    /**
     * @return string
     */
    public function getSearchEngineId()
    {
        return $this->config->getSearchEngineId();
    }

    /**
     * @return string
     */
    public function getInputSelector()
    {
        return $this->config->getWidgetInputSelector();
    }

    /**
     * @return string
     */
    public function getLocale()
    {
        return $this->config->getWidgetLocale();
    }

    /**
     * @return string
     */
    public function getTemplateSetId()
    {
        return $this->config->getWidgetTemplateSetId();
    }

    /**
     * URL the add-to-cart listener posts to when the widget dispatches ss:atc.
     *
     * @return string
     */
    public function getCartAddUrl()
    {
        return $this->getUrl('checkout/cart/add');
    }
}
