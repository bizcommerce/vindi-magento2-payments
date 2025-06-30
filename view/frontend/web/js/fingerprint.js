define(['uiComponent'], function (Component) {
    'use strict';
    return function (sandbox) {
        if (typeof window.yapayFingerprintLoaded === 'undefined') {
            window.yapayFingerprintLoaded = false;

            try {
                async function loadScript() {
                    // Verificar se o yapay já está disponível
                    if (typeof window.yapay === 'undefined') {
                        // Carregar o script da Yapay se não estiver disponível
                        const script = document.createElement('script');
                        script.src = sandbox === 1 || sandbox === '1' ?
                            'https://sandbox.gateway.yapay.com.br/checkout/api/v3/finger_print' :
                            'https://gateway.yapay.com.br/checkout/api/v3/finger_print';
                        script.async = true;
                        script.onload = function() {
                            initializeFingerprint();
                        };
                        script.onerror = function() {
                            console.warn('Failed to load Yapay fingerprint script');
                            window.yapayFingerprintLoaded = 'error';
                        };
                        document.head.appendChild(script);
                    } else {
                        initializeFingerprint();
                    }
                }

                function initializeFingerprint() {
                    try {
                        let fpOptions = {env: 'production'};
                        if (parseInt(sandbox) === 1) {
                            fpOptions.env = 'sandbox';
                        }

                        if (window.yapay && window.yapay.FingerPrint) {
                            window.yapay.FingerPrint(fpOptions);
                            window.yapayFingerprintLoaded = true;
                        }
                    } catch (e) {
                        console.warn('Error initializing fingerprint:', e);
                        window.yapayFingerprintLoaded = 'error';
                    }
                }

                loadScript();
            } catch (e) {
                console.warn('Error loading fingerprint script:', e);
                window.yapayFingerprintLoaded = 'error';
            }
        }
    };
});
