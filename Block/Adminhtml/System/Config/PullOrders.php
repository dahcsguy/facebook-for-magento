<?php
/**
 * Copyright (c) Meta Platforms, Inc. and affiliates. All Rights Reserved
 */

namespace Facebook\BusinessExtension\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Widget\Button;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class PullOrders extends Field
{
    /**
     * @var string
     */
    protected $_template = 'Facebook_BusinessExtension::system/config/pull_orders.phtml';

    /**
     * Remove scope label
     *
     * @param  AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();
        return parent::render($element);
    }

    /**
     * @todo move to helper
     * @return string
     */
    public function getAjaxUrl()
    {
        return $this->getUrl('fbeadmin/ajax/pullOrders', ['store' => $this->getRequest()->getParam('store')]);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        return $this->_toHtml();
    }

    /**
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function getButtonHtml()
    {
        /** @var Button $button */
        $button = $this->getLayout()->createBlock(Button::class);
        return $button->setData(['id' => 'fb_pull_orders_btn', 'label' => __('Pull Orders from Facebook')])
            ->toHtml();
    }
}
