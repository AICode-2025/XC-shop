<?php
/**
 * Copyright (c) Since 2024 InnoShop - All Rights Reserved
 *
 * @link       https://www.innoshop.com
 * @author     InnoShop <team@innoshop.com>
 * @license    https://opensource.org/licenses/OSL-3.0 Open Software License (OSL 3.0)
 */

namespace Tests\Feature;

use Database\Factories\BrandFactory;
use Database\Factories\ProductFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use InnoShop\Common\Models\OrderReturn;
use InnoShop\Common\Models\Product;
use InnoShop\Common\Repositories\RoleRepo;
use InnoShop\Common\Services\ImageService;
use InnoShop\Common\Services\ReturnStateService;
use InnoShop\Plugin\Controllers\MarketplaceController;
use Tests\TestCase;
use Tests\Traits\CreatesAdmin;

/**
 * 后台回归测试：真实请求（非反射断言），覆盖路由收口、图片缩放、角色权限守卫、
 * 退货状态服务边界与市场接口降级。
 */
class PanelHealthTest extends TestCase
{
    use CreatesAdmin;

    /**
     * 后台主要页面必须可打开（首个管理员 id=1，走 Gate::before 旁路）。
     */
    public function test_panel_core_pages_are_reachable(): void
    {
        $this->actingAsAdmin();

        $pages = [
            'dashboard.index', 'products.index', 'categories.index', 'brands.index',
            'attributes.index', 'options.index', 'reviews.index', 'orders.index',
            'order_returns.index', 'customers.index', 'customer_groups.index',
            'transactions.index', 'withdrawals.index', 'articles.index', 'catalogs.index',
            'tags.index', 'pages.index', 'announcements.index', 'media.index',
            'themes.index', 'plugins.index', 'plugin_coordination.index', 'settings.index',
            'account.index', 'admins.index', 'roles.index', 'countries.index', 'states.index',
            'regions.index', 'locales.index', 'currencies.index', 'tax_classes.index',
            'tax_rates.index', 'weight_classes.index', 'newsletter_subscribers.index',
            'socials.index', 'visits.index', 'analytics.index',
        ];

        $failed = [];
        foreach ($pages as $page) {
            $status = $this->get(panel_route($page))->getStatusCode();
            if ($status >= 400) {
                $failed[$page] = $status;
            }
        }

        $this->assertSame([], $failed, '以下后台页面不可用：'.json_encode($failed, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 未实现动作的路由必须不再注册，避免暴露 500 端点。
     */
    public function test_unimplemented_panel_routes_are_not_registered(): void
    {
        $removed = [
            'panel.products.show', 'panel.categories.show', 'panel.attributes.show',
            'panel.articles.show', 'panel.catalogs.show', 'panel.tags.show',
            'panel.announcements.show', 'panel.pages.show', 'panel.reviews.show',
            'panel.customers.show', 'panel.customer_groups.show', 'panel.weight_classes.show',
            'panel.admins.show', 'panel.order_returns.show', 'panel.order_returns.create',
            'panel.orders.create', 'panel.orders.store', 'panel.orders.update',
            'panel.plugins.create', 'panel.attribute_groups.create', 'panel.attribute_groups.edit',
            'panel.option_values.create', 'panel.option_values.edit',
            'panel.attribute_values.index', 'panel.attribute_values.create',
            'panel.attribute_values.show', 'panel.attribute_values.edit',
        ];

        $stillRegistered = array_values(array_filter($removed, fn (string $name) => Route::has($name)));

        $this->assertSame([], $stillRegistered, '以下端点未收口：'.implode(', ', $stillRegistered));
    }

    /**
     * 商品复制必须为 POST，GET 不应触发写操作。
     */
    public function test_product_copy_is_post_only(): void
    {
        $this->actingAsAdmin();

        $route = Route::getRoutes()->getByName('panel.products.copy');
        $this->assertNotNull($route);
        $this->assertContains('POST', $route->methods());
        $this->assertNotContains('GET', $route->methods());

        $brand   = BrandFactory::new()->create();
        $product = ProductFactory::new()->create(['brand_id' => $brand->id, 'tax_class_id' => 0]);

        $this->get(panel_route('products.copy', $product))->assertStatus(405);
        $this->assertSame(1, Product::query()->count());

        // POST 才是合法入口：真实复制一次，验证 END-TO-END 可用
        $this->post(panel_route('products.copy', $product))->assertStatus(302);
        $this->assertSame(2, Product::query()->count());
    }

    /**
     * 缩略图必须真正生成（回归 decode() 误用）。
     */
    public function test_image_service_generates_thumbnail_file(): void
    {
        $url = ImageService::getInstance('images/placeholder.png')->resize(37, 37);

        $this->assertStringContainsString('cache/', $url);

        $thumbnail = public_path('cache/images/placeholder-37x37-cover.png');
        $this->assertFileExists($thumbnail, '缩略图未生成：'.$thumbnail);
        $this->assertGreaterThan(0, (int) filesize($thumbnail));

        @unlink($thumbnail);
    }

    /**
     * 角色权限必须与角色同守卫（admin），否则 syncPermissions 抛异常。
     */
    public function test_role_permissions_are_created_with_admin_guard(): void
    {
        $role = RoleRepo::getInstance()->create([
            'name'        => 'Editor',
            'permissions' => ['orders_index', 'products_index'],
        ]);

        $this->assertSame('admin', $role->guard_name);
        $this->assertTrue($role->hasPermissionTo('orders_index'));

        $admin = $this->createAdmin();
        $admin->assignRole($role);

        $this->assertTrue($admin->hasPermissionTo('products_index'));
    }

    /**
     * 新建（未落库）退货单不应因 null id 抛 TypeError。
     */
    public function test_return_state_service_accepts_unsaved_order_return(): void
    {
        $service = ReturnStateService::getInstance(new OrderReturn);

        $this->assertInstanceOf(ReturnStateService::class, $service);
    }

    /**
     * 市场接口不可用时返回可读提示，而不是透传远端 404 文案。
     */
    public function test_marketplace_token_failure_returns_friendly_message(): void
    {
        Http::fake(['*' => Http::response(['message' => 'The route api/domains/token could not be found.'], 422)]);

        $response = (new MarketplaceController)->getToken();

        $this->assertSame(422, $response->getStatusCode());

        $payload = $response->getData(true);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsStringIgnoringCase('token', (string) $payload['message']);
        $this->assertStringNotContainsString('could not be found', (string) $payload['message']);
    }

    /**
     * 未配置授权令牌时，账户接口不请求远端，直接给出可读提示。
     */
    public function test_marketplace_account_short_circuits_without_token(): void
    {
        Http::fake();

        $response = (new MarketplaceController)->proxyAccountMe(Request::create('/panel/marketplaces/account', 'GET'));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        Http::assertNothingSent();
    }
}
