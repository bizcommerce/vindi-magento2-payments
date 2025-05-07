// File: app/code/Vindi/VP/view/frontend/web/js/view/payment/vindi.js
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (
    Component,
    rendererList
) {
    'use strict';

    rendererList.push(
        {
            type: 'vindi_vp_bankslip',
            component: 'Vindi_VP/js/view/payment/method-renderer/bankslip'
        },
        {
            type: 'vindi_vp_bankslippix',
            component: 'Vindi_VP/js/view/payment/method-renderer/bankslippix'
        },
        {
            type: 'vindi_vp_pix',
            component: 'Vindi_VP/js/view/payment/method-renderer/pix'
        },
        {
            type: 'vindi_vp_cc',
            component: 'Vindi_VP/js/view/payment/method-renderer/cc'
        },
        {
            type: 'vindi_vp_cardpix',
            component: 'Vindi_VP/js/view/payment/method-renderer/cardpix'
        },
        {
            type: 'vindi_vp_cardcard',
            component: 'Vindi_VP/js/view/payment/method-renderer/cardcard'
        },
        {
            type: 'vindi_vp_cardbankslippix',
            component: 'Vindi_VP/js/view/payment/method-renderer/cardbankslippix'
        }
    );

    return Component.extend({});
});
