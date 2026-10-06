<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Model\Resolver\Batch;

use Magenx\AutoProductLinks\Model\Link\LinkTypeResolver;
use Magento\CatalogGraphQl\Model\Resolver\Product\ProductFieldsSelector;
use Magento\CatalogGraphQl\Model\Resolver\Products\DataProvider\Product as ProductDataProvider;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\BatchResponse;
use Magento\Framework\GraphQl\Query\Resolver\ContextInterface;
use Magento\RelatedProductGraphQl\Model\DataProvider\RelatedProductDataProvider;
use Magento\RelatedProductGraphQl\Model\Resolver\Batch\AbstractLikedProducts;
use Magento\RelatedProductGraphQl\Model\ResourceModel\RelatedProductsByStoreId;

/**
 * ProductInterface.frequently_bought_together_products.
 *
 * Magento's own batch resolver for related / up-sell / cross-sell, pointed at
 * the module's Frequently Bought Together link type - so the field gets the same
 * batching, website filtering, availability check and position ordering as the
 * stock three, and the storefront renders it with the same fragment.
 */
class FrequentlyBoughtTogetherProducts extends AbstractLikedProducts
{
    /**
     * @param ProductFieldsSelector $productFieldsSelector
     * @param RelatedProductDataProvider $relatedProductDataProvider
     * @param ProductDataProvider $productDataProvider
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param LinkTypeResolver $linkTypeResolver
     * @param RelatedProductsByStoreId|null $relatedProductsByStoreId
     */
    public function __construct(
        ProductFieldsSelector $productFieldsSelector,
        RelatedProductDataProvider $relatedProductDataProvider,
        ProductDataProvider $productDataProvider,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        private readonly LinkTypeResolver $linkTypeResolver,
        ?RelatedProductsByStoreId $relatedProductsByStoreId = null
    ) {
        parent::__construct(
            $productFieldsSelector,
            $relatedProductDataProvider,
            $productDataProvider,
            $searchCriteriaBuilder,
            $relatedProductsByStoreId
        );
    }

    /**
     * An empty list for every product while the link type is not installed,
     * rather than an error that would fail the shopper's whole query.
     *
     * @inheritDoc
     */
    public function resolve(ContextInterface $context, Field $field, array $requests): BatchResponse
    {
        if ($this->linkTypeResolver->getBoughtTogetherId() === null) {
            $response = new BatchResponse();
            foreach ($requests as $request) {
                $response->addResponse($request, []);
            }

            return $response;
        }

        return parent::resolve($context, $field, $requests);
    }

    /**
     * @inheritDoc
     */
    protected function getNode(): string
    {
        return 'frequently_bought_together_products';
    }

    /**
     * @inheritDoc
     */
    protected function getLinkType(): int
    {
        return (int) $this->linkTypeResolver->getBoughtTogetherId();
    }
}
