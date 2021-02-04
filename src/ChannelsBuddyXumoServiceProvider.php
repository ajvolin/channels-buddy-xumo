<?php

namespace ChannelsBuddy\Xumo;

use ChannelsBuddy\SourceProvider\ChannelSourceProvider;
use ChannelsBuddy\SourceProvider\ChannelSourceProviders;
use ChannelsBuddy\Xumo\Services\XumoService;
use Illuminate\Support\ServiceProvider;

class ChannelsBuddyXumoServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap Channels Buddy Stirr Source.
     *
     * @return void
     */
    public function boot(ChannelSourceProviders $sourceProvider)
    {
        $sourceProvider->registerChannelSourceProvider(
            new ChannelSourceProvider(
                'xumo',
                XumoService::class,
                'Xumo',
                true,
                true,
                1814400,
                1814400
            )
        );
    }
}