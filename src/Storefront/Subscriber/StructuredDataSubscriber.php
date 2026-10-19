<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Storefront\Subscriber;

use Shopware\Core\Framework\Feature;
use Shopware\Core\Framework\Struct\ArrayStruct;
use Shopware\Storefront\Page\Product\ProductPageLoadedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Xl0g\DeliveryPromise\Service\RenderedPromiseCollector;
use Xl0g\DeliveryPromise\Service\ShippingMethodResolver;
use Xl0g\DeliveryPromise\Service\StructuredDataBuilder;
use Xl0g\DeliveryPromise\Service\VisitorClassifier;
use Xl0g\DeliveryPromise\Service\Xl0gPromiseClient;

/**
 * Enriches the product page with Schema.org `shippingDetails` JSON-LD (Google rich results + AI
 * assistants). Uses the xl0g numbers (via {@see Xl0gPromiseClient}) + the {@see StructuredDataBuilder}.
 * Missing date / not configured → no extension (no empty/broken markup). The `<script>` tag is rendered
 * by the Twig template.
 *
 * SEO plugin markup: if product JSON-LD of an SEO plugin is already on the page (DreiSC SEO Pro), the
 * xl0g data is injected into THAT markup instead of emitting a second, competing Product block —
 * `availability` stays solely the SEO plugin's business. Only without such markup does the plugin
 * render its own block (fallback); its availability is then closeout-aware instead of purely
 * stock-based.
 *
 * Core JSON-LD: if the Shopware core outputs JSON-LD itself (feature flag JSON_LD_DATA, standard from
 * 6.8), the subscriber puts the date into the page extension `xl0gShippingDetails`; the template
 * override `layout/structured-data/json-ld-product.html.twig` merges the shippingDetails into the core
 * product node via the Twig function `xl0g_inject_shipping_details`. The own fallback block is dropped
 * then — otherwise there would again be two Product nodes on the page.
 */
class StructuredDataSubscriber implements EventSubscriberInterface
{
    /**
     * Name of the DreiSC SEO Pro page extension (RichSnippetSubscriber). Deliberately a string instead
     * of a class constant — the plugin must not depend hard on DreiSC; access to the struct methods is
     * duck-typed via method_exists().
     */
    final public const SEO_RICH_SNIPPET_EXTENSION = 'dreiscSeoInstallmentRichSnippetData';

    /** Name of the plugin's own page extension (fallback block or data for the core JSON-LD). */
    final public const EXTENSION_NAME = 'xl0gShippingDetails';

    public function __construct(
        private readonly Xl0gPromiseClient $client,
        private readonly StructuredDataBuilder $builder,
        private readonly RenderedPromiseCollector $collector,
        private readonly ShippingMethodResolver $shippingMethodResolver,
        private readonly VisitorClassifier $classifier,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority -10: run AFTER the SEO plugin subscriber (priority 0), so its JSON-LD is already
        // attached to the page when we want to inject.
        return [ProductPageLoadedEvent::class => ['onProductPageLoaded', -10]];
    }

    public function onProductPageLoaded(ProductPageLoadedEvent $event): void
    {
        $context = $event->getSalesChannelContext();
        $salesChannelId = $context->getSalesChannelId();
        $this->reportPageView($event, $salesChannelId);
        if (!$this->client->isJsonLdEnabled($salesChannelId)) {
            return;
        }

        $page = $event->getPage();

        // Excluded shipping methods (config): products with an excluded shipping method in the custom
        // field get no xl0g JSON-LD either — the date of the default shipping method would be wrong for
        // them. Google/agents then see the values from core/SEO plugin, as without this plugin.
        $product = $page->getProduct();
        $productFields = $product->getTranslated()['customFields'] ?? $product->getCustomFields() ?? [];
        $productShippingMethod = $productFields[ShippingMethodResolver::PRODUCT_CUSTOM_FIELD_NAME] ?? null;
        if ($this->shippingMethodResolver->isProductShippingMethodExcluded($productShippingMethod, $salesChannelId)) {
            return;
        }

        // The JSON-LD declares the configured destination country as shippingDestination — the date
        // must match it, i.e. get the same country offset (for DE/base country: 0).
        $shippingCountry = $this->client->shippingCountry($salesChannelId);
        $promise = $this->client->fetch(
            $this->client->shippingMethod($salesChannelId),
            'pdp',
            $salesChannelId,
            $shippingCountry
        );
        if ($promise === null) {
            return;
        }

        // The JSON-LD depends on the date too: note the cutoff for the page's s-maxage capping
        // (so it applies even if only JSON-LD is active and the visible widget is off).
        $this->collector->note($promise);

        // Preferred: inject into the existing SEO markup — one source per page.
        $injectedIntoSeoMarkup = false;
        $seoLd = $page->getExtension(self::SEO_RICH_SNIPPET_EXTENSION);
        if ($seoLd !== null && method_exists($seoLd, 'getLdJson') && method_exists($seoLd, 'setLdJson')) {
            $blocks = $seoLd->getLdJson();
            if (\is_array($blocks)) {
                $injected = $this->builder->injectIntoLdBlocks($blocks, $promise, $shippingCountry);
                if ($injected !== null) {
                    $seoLd->setLdJson($injected);
                    $injectedIntoSeoMarkup = true;
                }
            }
        }

        // Core JSON-LD active → provide the date for the template override; the core product JSON-LD
        // gets the shippingDetails via xl0g_inject_shipping_details(). No own block any more — the
        // core is the source for Product/Offer/availability.
        if (self::isCoreJsonLdActive()) {
            $page->addExtension(
                self::EXTENSION_NAME,
                new ArrayStruct([
                    'promise' => $promise->toArray(),
                    'shippingCountry' => $shippingCountry,
                ])
            );

            return;
        }

        if ($injectedIntoSeoMarkup) {
            return;
        }

        // Fallback: own Product block (only without SEO plugin markup and without core JSON-LD).
        $price = $product->getCalculatedPrice()?->getUnitPrice();

        $jsonLd = $this->builder->build(
            name: $product->getTranslation('name'),
            sku: $product->getProductNumber(),
            price: $price !== null ? number_format($price, 2, '.', '') : null,
            currency: $context->getCurrency()->getIsoCode(),
            availability: $this->builder->availability(
                $product->getIsCloseout() === true,
                (int) $product->getAvailableStock()
            ),
            promise: $promise,
            shippingCountry: $shippingCountry,
        );

        $page->addExtension(
            self::EXTENSION_NAME,
            new ArrayStruct(['jsonLd' => json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)])
        );
    }

    /**
     * Page view report for AI agents, search engines and tools (switch “Report page views to xl0g”): they mostly do not
     * run the widget JS, so they are counted here, when the page is generated. Browsers are counted by the page view
     * beacon ({@see \Xl0g\DeliveryPromise\Storefront\Controller\DeliveryPromiseController::pageView()}), so every
     * visit counts once. Pages served from the shop's HTTP cache do not reach PHP: for agents the number is a lower bound.
     */
    private function reportPageView(ProductPageLoadedEvent $event, string $salesChannelId): void
    {
        if (!$this->client->isReportPageViewsEnabled($salesChannelId)) {
            return;
        }
        $request = $event->getRequest();
        $pageView = $this->classifier->pageViewQuery(
            (string) $event->getPage()->getProduct()->getProductNumber(),
            $request->getHost(),
            (string) $request->headers->get('User-Agent', '')
        );
        if (($pageView['visitor'] ?? VisitorClassifier::BROWSER) !== VisitorClassifier::BROWSER) {
            $this->client->reportPageView($pageView, $salesChannelId);
        }
    }

    /**
     * Shopware outputs structured data as JSON-LD if the major flag JSON_LD_DATA is active (6.7.9+)
     * or from 6.8 on, where the flag is dropped and JSON-LD is the standard.
     */
    public static function isCoreJsonLdActive(): bool
    {
        return Feature::isActive('JSON_LD_DATA') || Feature::isActive('v6.8.0.0');
    }
}
