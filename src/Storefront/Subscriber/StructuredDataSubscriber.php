<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Xl0g\DeliveryPromise\Service\StructuredDataBuilder;
use Xl0g\DeliveryPromise\Service\VisitorClassifier;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * B3: Reichert die Produktseite um Schema.org `Product`/`Offer`/`OfferShippingDetails`-JSON-LD an
 * (Google-Rich-Results + KI-Assistenten). Nutzt die xl0g-Zahlen (via {@see Xl0gPromiseClient}) +
 * den {@see StructuredDataBuilder}. Fehlt der Termin / nicht konfiguriert → keine Extension
 * (kein leeres/kaputtes Markup). Das Rendern des `<script>`-Tags macht die Twig-Erweiterung.
 *
 * 0.7.0: Ist „Seitenaufrufe an xl0g melden“ an, meldet der Subscriber Besuche von KI-Agenten, Suchdiensten und
 * Werkzeugen — sie führen das Widget-JS meist nicht aus. Browser zählt der {@see DeliveryPromiseController}. Seiten aus
 * dem HTTP-Cache des Shops erreichen PHP nicht und zählen hier nicht: für Agenten ist die Zahl eine Untergrenze.
 */
class StructuredDataSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Xl0gPromiseClient $client,
        private readonly StructuredDataBuilder $builder,
        private readonly VisitorClassifier $classifier,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [ProductPageLoadedEvent::class => 'onProductPageLoaded'];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $salesChannelId = $context->getSalesChannelId();
        $pageView = $this->pageView($event, $salesChannelId);
        if (!$this->client->isJsonLdEnabled($salesChannelId)) {
            if ($pageView !== []) {
                // Nur melden: kurzer Abruf, die Antwort wird nicht gebraucht.
                $this->client->fetch($this->client->shippingMethod($salesChannelId), 'pdp', $salesChannelId, $pageView, 1.0);
            }

            return;
        }

        $promise = $this->client->fetch($this->client->shippingMethod($salesChannelId), 'pdp', $salesChannelId, $pageView);
        if ($promise === null) {
            return;
        }

        $product = $event->getPage()->getProduct();
        $price = $product->getCalculatedPrice()?->getUnitPrice();

        $jsonLd = $this->builder->build(
            name: $product->getTranslation('name'),
            sku: $product->getProductNumber(),
            price: $price !== null ? number_format($price, 2, '.', '') : null,
            currency: $context->getCurrency()->getIsoCode(),
            inStock: (int) $product->getAvailableStock() > 0,
            promise: $promise,
            shippingCountry: $this->client->shippingCountry($salesChannelId),
        );

        $event->getPage()->addExtension(
            'xl0gShippingDetails',
            new ArrayStruct(['jsonLd' => json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)])
        );
    }

    /**
     * Die Meldung für diesen Besuch — leer, wenn der Schalter aus ist oder ein Browser kommt (den zählt das Widget).
     *
     * @return array<string, string>
     */
    private function pageView(ProductPageLoadedEvent $event, string $salesChannelId): array
    {
        if (!$this->client->isReportPageViewsEnabled($salesChannelId)) {
            return [];
        }
        $request = $event->getRequest();
        $q = $this->classifier->pageViewQuery(
            (string) $event->getPage()->getProduct()->getProductNumber(),
            $request->getHost(),
            (string) $request->headers->get('User-Agent', '')
        );

        return ($q['visitor'] ?? VisitorClassifier::BROWSER) === VisitorClassifier::BROWSER ? [] : $q;
    }
}
