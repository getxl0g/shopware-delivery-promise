<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Subscriber;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Xl0g\DeliveryPromise\Service\StructuredDataBuilder;
use Xl0g\DeliveryPromise\Service\VisitorClassifier;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * B3: Enriches the product page with Schema.org `Product`/`Offer`/`OfferShippingDetails` JSON-LD
 * (Google rich results + AI assistants). Uses the xl0g numbers (via {@see Xl0gPromiseClient}) +
 * the {@see StructuredDataBuilder}. If the date is missing / not configured → no extension
 * (no empty/broken markup). The Twig extension renders the `<script>` tag.
 *
 * 0.7.0: If "Report page views to xl0g" is on, the subscriber reports visits by AI agents, search engines and
 * tools — they mostly do not run the widget JS. Browsers are counted by the {@see DeliveryPromiseController}. Pages
 * from the shop's HTTP cache do not reach PHP and are not counted here: for agents the number is a lower bound.
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
                // Report only: short request, the response is not needed.
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
     * The report for this visit — empty if the switch is off or a browser comes (the widget counts it).
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
