<?php namespace Wpstudio\Sber\Classes;

use OFFLINE\Mall\Classes\Utils\DefaultMoney;

/***
 * Helper class to solve this problem https://github.com/OFFLINE-GmbH/oc-mall-plugin/issues/258
 * paypal error after redirect to site
 * After payment via PayPal upon returning to the site, we get an error Call to a member function getCurrent() on null
 */
class DefaultMoneyRepair extends DefaultMoney
{
    final protected function render($contents, array $vars): string
    {
        return number_format($vars['price'],$vars['currency']->decimals,  ',', ' ').' '.$vars['currency']->symbol;
    }
}
