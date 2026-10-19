<?php declare(strict_types=1);

namespace Xl0g\DeliveryPromise;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Xl0g\DeliveryPromise\Service\ShippingMethodResolver;

/**
 * xl0g Delivery Promise — Shopware 6 plugin.
 *
 * Principle: xl0g delivers the NUMBERS (delivery date, cutoff, transit days) language-neutrally via
 * `GET /api/promise?format=data`. Text and translation are THIS plugin's job — per Shopware sales
 * channel, via the native snippet system. In addition it emits the `shippingDetails` markup (JSON-LD)
 * for Google rich results and AI assistants.
 *
 * Open source (MIT) + freemium: the plugin code is open; the commercial value lies in the xl0g licence
 * (the promise engine behind the API), not in the plugin code.
 */
class Xl0gDeliveryPromise extends Plugin
{
    /**
     * Own set only as the carrier of the mapping field ({@see ShippingMethodResolver}) — custom field
     * names are globally unique, so the existence of the FIELD (not of the set) decides whether it is
     * created. A field of the same name maintained manually in the admin stays untouched that way.
     */
    private const CUSTOM_FIELD_SET_NAME = 'xl0g_delivery_promise';

    public function install(InstallContext $installContext): void
    {
        $this->ensureShippingMethodMappingField($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        $this->ensureShippingMethodMappingField($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Remove only the plugin's OWN set (field + relations cascade with it). A manually created
        // field hangs in a foreign set and is kept that way.
        /** @var EntityRepository $setRepo */
        $setRepo = $this->container->get('custom_field_set.repository');
        $context = $uninstallContext->getContext();

        $setId = $setRepo->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('name', self::CUSTOM_FIELD_SET_NAME)),
            $context
        )->firstId();

        if ($setId !== null) {
            $setRepo->delete([['id' => $setId]], $context);
        }
    }

    /**
     * Creates the shipping method mapping field idempotently: field exists (in whichever set) ⇒ do
     * nothing; own set exists without the field ⇒ add the field; otherwise create set + field + relation
     * to the shipping method. Fresh environments are thus ready without manual admin work.
     */
    private function ensureShippingMethodMappingField(Context $context): void
    {
        /** @var EntityRepository $fieldRepo */
        $fieldRepo = $this->container->get('custom_field.repository');

        $fieldExists = $fieldRepo->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('name', ShippingMethodResolver::CUSTOM_FIELD_NAME)),
            $context
        )->firstId() !== null;

        if ($fieldExists) {
            return;
        }

        $fieldPayload = [
            'name' => ShippingMethodResolver::CUSTOM_FIELD_NAME,
            'type' => CustomFieldTypes::TEXT,
            'config' => [
                'type' => 'text',
                'componentName' => 'sw-field',
                'customFieldType' => 'text',
                'customFieldPosition' => 1,
                'label' => [
                    'de-DE' => 'xl0g-Versandart (Mapping)',
                    'en-GB' => 'xl0g shipping method (mapping)',
                ],
                'helpText' => [
                    'de-DE' => 'xl0g-Versandart für diese Shopware-Versandart (z. B. „express"). Leer ⇒ Standard aus der Plugin-Konfiguration.',
                    'en-GB' => 'xl0g shipping method for this Shopware shipping method (e.g. "express"). Empty ⇒ default from the plugin configuration.',
                ],
            ],
        ];

        /** @var EntityRepository $setRepo */
        $setRepo = $this->container->get('custom_field_set.repository');

        $setId = $setRepo->searchIds(
            (new Criteria())->addFilter(new EqualsFilter('name', self::CUSTOM_FIELD_SET_NAME)),
            $context
        )->firstId();

        if ($setId !== null) {
            $fieldRepo->create([$fieldPayload + ['customFieldSetId' => $setId]], $context);

            return;
        }

        $setRepo->create([[
            'name' => self::CUSTOM_FIELD_SET_NAME,
            'config' => [
                'label' => [
                    'de-DE' => 'xl0g Lieferversprechen',
                    'en-GB' => 'xl0g Delivery Promise',
                ],
            ],
            'customFields' => [$fieldPayload],
            'relations' => [['entityName' => 'shipping_method']],
        ]], $context);
    }
}
