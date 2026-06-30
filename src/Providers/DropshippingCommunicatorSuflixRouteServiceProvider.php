<?php

namespace DropshippingCommunicatorSuflix\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\ApiRouter;
use Plenty\Plugin\Routing\Router as WebRouter;

class DropshippingCommunicatorSuflixRouteServiceProvider extends RouteServiceProvider
{
    public function map(ApiRouter $api, WebRouter $webRouter): void
    {
        $api->version(['v1'], ['namespace' => 'DropshippingCommunicatorSuflix\Controllers'], function ($router) {
            $router->post('suflix/export/{orderId}', ['uses' => 'ExportController@sendOrder']);
        });
    }
}
