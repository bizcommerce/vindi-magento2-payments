var config = {
    paths: {
        'vindi-cc-form': 'Vindi_VP/js/credit-card/card',
        'vindi-cc-mask': 'Vindi_VP/js/credit-card/mask',
        'jQueryMask': 'Vindi_Payment/js/libs/jquery.mask.min',
        'mage/url': 'mage/url',
        'vindi_vp/validation': 'Vindi_VP/js/validation',
        'jquery/jquery.mask': 'Vindi_VP/js/libs/jquery.mask.min'
    },
    shim: {
        'vindi-cc-mask': {}
    },
    map: {
        '*': {
            'Vindi_VP/payment/form/cardbankslippix': 'Vindi_VP/template/payment/cardbankslippix'
        }
    }
};
