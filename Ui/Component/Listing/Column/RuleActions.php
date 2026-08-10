<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AutoProductLinks\Ui\Component\Listing\Column;

use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Magento\Ui\Component\Listing\Columns\Column;

/**
 * Edit / Delete links in the rule grid.
 */
class RuleActions extends Column
{
    private const URL_EDIT = 'magenx_autoproductlinks/rule/edit';
    private const URL_DELETE = 'magenx_autoproductlinks/rule/delete';

    /**
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param UrlInterface $urlBuilder
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        private readonly UrlInterface $urlBuilder,
        array $components = [],
        array $data = []
    ) {
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource): array
    {
        if (!isset($dataSource['data']['items'])) {
            return $dataSource;
        }

        foreach ($dataSource['data']['items'] as &$item) {
            $ruleId = (int) ($item['rule_id'] ?? 0);
            if (!$ruleId) {
                continue;
            }

            $item[$this->getData('name')] = [
                'edit' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_EDIT, ['rule_id' => $ruleId]),
                    'label' => __('Edit'),
                ],
                'delete' => [
                    'href' => $this->urlBuilder->getUrl(self::URL_DELETE, ['rule_id' => $ruleId]),
                    'label' => __('Delete'),
                    'post' => true,
                    'confirm' => [
                        'title' => __('Delete rule'),
                        // Names the consequence rather than asking "are you sure":
                        // deleting a rule also removes every link it created.
                        'message' => __(
                            'This deletes the rule and every product link it created. '
                            . 'Links you added by hand are not affected.'
                        ),
                    ],
                ],
            ];
        }

        return $dataSource;
    }
}
