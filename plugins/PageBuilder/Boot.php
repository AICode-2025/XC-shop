<?php
/**
 * Copyright (c) Since 2024 InnoShop - All Rights Reserved
 *
 * @link       https://www.innoshop.com
 * @author     InnoShop <team@innoshop.com>
 * @license    https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace Plugin\PageBuilder;

use InnoShop\Plugin\Core\BaseBoot;

class Boot extends BaseBoot
{
    /**
     * 容器级标记：Octane 下防重复，`route:cache` 新建容器时仍能重新注册钩子。
     */
    private const BOOTED_KEY = 'page_builder.booted';

    /**
     * @return void
     */
    public function init(): void
    {
        if (app()->bound(self::BOOTED_KEY)) {
            return;
        }

        app()->instance(self::BOOTED_KEY, true);

        $this->addPanelMenus();
        $this->addPageDesignButton();
    }

    /**
     * @return void
     */
    private function addPanelMenus(): void
    {
        listen_hook_filter('panel.component.sidebar.design.routes', function ($data) {
            $data[] = [
                'route' => 'pbuilder.index',
                'title' => trans('PageBuilder::route.title'),
                'blank' => true,
            ];

            return $data;
        });
    }

    /**
     * Add design button to page list
     *
     * @return void
     */
    private function addPageDesignButton(): void
    {
        listen_blade_insert('panel.page.list.table.row.actions.before', function ($data) {
            $item = $data['item'] ?? null;
            if ($item) {
                return view('PageBuilder::panel.pages.design-button', ['item' => $item])->render();
            }

            return null;
        });
    }
}
