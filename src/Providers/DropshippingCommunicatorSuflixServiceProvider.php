<?php

namespace DropshippingCommunicatorSuflix\Providers;

use Plenty\Plugin\ServiceProvider;
use Plenty\Plugin\Events\Dispatcher;
use DropshippingCommunicatorSuflix\Services\ExportService;

class DropshippingCommunicatorSuflixServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->getApplication()->register(DropshippingCommunicatorSuflixRouteServiceProvider::class);
        $this->getApplication()->bind(ExportService::class);
    }
}
