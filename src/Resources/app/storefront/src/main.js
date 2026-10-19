import Xl0gDeliveryPromisePlugin from './xl0g-delivery-promise/xl0g-delivery-promise.plugin';
import Xl0gPageViewPlugin from './xl0g-page-view/xl0g-page-view.plugin';

const PluginManager = window.PluginManager;
PluginManager.register(
    'Xl0gDeliveryPromise',
    Xl0gDeliveryPromisePlugin,
    '[data-xl0g-delivery-promise]'
);
PluginManager.register(
    'Xl0gPageView',
    Xl0gPageViewPlugin,
    '[data-xl0g-page-view]'
);
