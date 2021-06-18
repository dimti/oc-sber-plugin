<?php namespace Wpstudio\Sber;

use Wpstudio\Sber\Classes\DefaultMoneyRepair;
use OFFLINE\Mall\Classes\Payments\PaymentGateway;
use OFFLINE\Mall\Classes\Utils\Money;
use System\Classes\PluginBase;
use Wpstudio\Sber\Classes\SberCheckout;

class Plugin extends PluginBase
{
    /**
     * @var array Plugin dependencies
     */
    public $require = ['Offline.Mall'];

    final public function boot(): void
    {
        $gateway = $this->app->get(PaymentGateway::class);
        $gateway->registerProvider(new SberCheckout());

        // For solve this issue https://github.com/OFFLINE-GmbH/oc-mall-plugin/issues/258
        $this->app->singleton(Money::class, function () {
            return new DefaultMoneyRepair();
        });
    }
}
