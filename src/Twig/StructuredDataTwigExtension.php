<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise\Twig;

use Shopware\Core\Framework\Struct\ArrayStruct;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Xl0g\DeliveryPromise\Service\PromiseResult;
use Xl0g\DeliveryPromise\Service\StructuredDataBuilder;
use Xl0g\DeliveryPromise\Storefront\Subscriber\StructuredDataSubscriber;

/**
 * Twig function `xl0g_inject_shipping_details(productData, page.extensions.xl0gShippingDetails)` for the
 * override of `layout/structured-data/json-ld-product.html.twig`. The Shopware core builds its product
 * JSON-LD in Twig (variable `productData`); this function merges the xl0g shippingDetails into it via the
 * same {@see StructuredDataBuilder} that also serves SEO plugin markup.
 * Without the extension (plugin not configured, no date, JSON-LD off) productData comes back unchanged.
 */
class StructuredDataTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly StructuredDataBuilder $builder,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('xl0g_inject_shipping_details', $this->injectShippingDetails(...)),
        ];
    }

    /**
     * @param mixed $productData the core template's `productData` array (Product or ProductGroup)
     * @param mixed $extension   the page extension {@see StructuredDataSubscriber::EXTENSION_NAME} or null
     *
     * @return array<string, mixed>
     */
    public function injectShippingDetails(mixed $productData, mixed $extension): array
    {
        if (!\is_array($productData)) {
            return [];
        }
        if (!$extension instanceof ArrayStruct) {
            return $productData;
        }

        $promiseData = $extension->get('promise');
        if (!\is_array($promiseData)) {
            return $productData;
        }

        $promise = PromiseResult::fromArray($promiseData);
        if ($promise === null) {
            return $productData;
        }

        $shippingCountry = $extension->get('shippingCountry');
        if (!\is_string($shippingCountry) || $shippingCountry === '') {
            $shippingCountry = 'DE';
        }

        return $this->builder->injectIntoProductData($productData, $promise, $shippingCountry);
    }
}
