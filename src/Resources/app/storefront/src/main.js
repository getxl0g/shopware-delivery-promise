import Xl0gDeliveryPromisePlugin from './xl0g-delivery-promise/xl0g-delivery-promise.plugin';

const PluginManager = window.PluginManager;
PluginManager.register(
    'Xl0gDeliveryPromise',
    Xl0gDeliveryPromisePlugin,
    '[data-xl0g-delivery-promise]'
);
