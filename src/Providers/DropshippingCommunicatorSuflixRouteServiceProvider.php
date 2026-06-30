<?php

namespace DropshippingCommunicatorSuflix\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\ApiRouter;
use Plenty\Plugin\Routing\Router as WebRouter;

class DropshippingCommunicatorSuflixRouteServiceProvider extends RouteServiceProvider
{
    public function map(ApiRouter $api, WebRouter $webRouter): void
    {
        // Route ohne Middleware – direkt erreichbar
        $api->version(['v1'], [], function ($router) {
            $router->post(
                'suflix/export/{orderId}',
                ['uses' => 'DropshippingCommunicatorSuflix\Controllers\ExportController@sendOrder']
            );
        });
    }
}
