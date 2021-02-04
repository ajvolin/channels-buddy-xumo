<?php

namespace ChannelsBuddy\Xumo\Services;

use ChannelsBuddy\SourceProvider\Models\Airing;
use ChannelsBuddy\SourceProvider\Models\Channel;
use ChannelsBuddy\SourceProvider\Models\Channels;
use ChannelsBuddy\SourceProvider\Models\Guide;
use ChannelsBuddy\SourceProvider\Models\GuideEntry;
use ChannelsBuddy\SourceProvider\Contracts\ChannelSource;
use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use JsonMachine\JsonMachine;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use stdClass;
use Throwable;

class XumoService implements ChannelSource
{
    protected $baseUrl;
    protected $loginUrl;
    protected $channelListId;
    protected $geoId;
    protected $httpClient;
    protected $defaultChannelArtUrl =
        "https://komonews.com/resources/media2/4x3/full/1440/center/100/22bd9810-78d3-43d9-9425-ba4373465f58-full1x1_STIRR.Logo.BlackYellow01.png";
    private $sortValueNumber = 200;

    public function __construct()
    {
        $this->loginUrl =
            'http://www.xumo.tv';
        $this->baseUrl =
            'https://valencia-app-mds.xumo.com/v2/';

        $this->setChannelListAndGeoId();

        $this->httpClient = new Client(['base_uri' => $this->baseUrl]);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getChannels(?string $device = null): Channels
    {
        $onNowStream = $this->httpClient->get(
            sprintf(
                'channels/list/%s.json?geoId=%s',
                $this->channelListId,
                $this->geoId
            )
        );
        $onNowJson = $onNowStream->getBody()->getContents();

        $onNowChannels = collect(json_decode($onNowJson)->results)
        ->filter(function($onNowChannel) {
            return $onNowChannel->contentType != 'COMPOSITE';
        })->keyBy('channelId');

        $channelStream = $this->httpClient->get(
            sprintf(
                'channels/list/%s.json?geoId=%s',
                $this->channelListId,
                $this->geoId
            )
        );
        $channelJson = \GuzzleHttp\Psr7\StreamWrapper::getResource(
            $channelStream->getBody()
        );

        $channels = LazyCollection::make(JsonMachine::fromStream(
            $channelJson, '/channel/item', new ExtJsonDecoder
        ))
        ->filter(function($channel) use ($onNowChannels) {
            return $onNowChannels->has($channel->guid->value);
        })
        ->map(function($channel) use ($onNowChannels) {
            $channel->streamAssetId =
                $onNowChannels->get($channel->guid->value)->id;
            return $this->generateChannel($channel);
        })->keyBy('id');

        return new Channels($channels);
    }

    public function getGuideData(?int $startTimestamp, ?int $duration, ?string $device = null): Guide
    {
        return new Guide(LazyCollection::make(function(){
            yield null;
        }));
    }

    private function generateAiring(Channel $channel, stdClass $entry): Airing
    {
    }
    
    private function generateChannel(stdClass $channel): Channel
    {        
        $streamUrl = $this->getStreamUrl($channel->streamAssetId);

        return new Channel([
            "id"            => $channel->guid->value,
            "name"          => $channel->title,
            "number"        => $channel->number,
            "title"         => $channel->title,
            "callSign"      => $channel->callsign,
            "description"   => $channel->description,
            "logo"          => $this->getLogoUrl($channel->guid->value),
            "channelArt"    => $this->getChannelArtUrl($channel->guid->value),
            "category"      => $channel->genre[0]->value ?? null,
            "streamUrl"     => $streamUrl
        ]);
    }

    private function getChannelArtUrl(string $channelId): string
    {
        return sprintf(
            'https://image.xumo.com/v1/channels/channel/%s/1024x768.png?type=channelTile',
            $channelId
        );
    }

    private function getLogoUrl(string $channelId, string $color = 'White'): string
    {
        return sprintf(
            'https://image.xumo.com/v1/channels/channel/%s/1024x768.png?type=color_on%s',
            $channelId,
            ucwords(strtolower($color))
        );
    }

    private function getStreamUrl(string $streamAssetId): string
    {
        try {
            $assetStream = $this->httpClient->get(
                sprintf(
                    'assets/asset/%s.json?f=providers',
                    $streamAssetId
                )
            );
            $assetJson = $assetStream->getBody()->getContents();
            $asset = collect(json_decode($assetJson));

            return $asset
                ->providers
                ->first()
                ->sources
                ->first()
                ->uri ?? '';
        } catch (Throwable $e) {
            return '';
        }
    }

    private function setChannelListAndGeoId(): void
    {
        $ids = Cache::remember('channels-buddy-xumo.ids', 43200, function() {
                try {
                    $request = $this->httpClient->get(
                        $this->loginUrl, [
                            'headers' => [
                                'User-Agent' => 'Mozilla/5.0'
                            ]
                        ]
                    );
                    $response = $request->getBody()->getContents();

                    preg_match('/"channelListId":"(.*?)",/m', $response, $channelList);
                    preg_match('/"geoId":"(.*?)",/m', $response, $geoId);

                    return [
                        'channelListId' => $channelList[1],
                        'geoId' => $geoId[1]
                    ];
                } catch (Throwable $e) {
                    report("Xumo setup failed.");
                    abort(500);
                }
            });
        $this->channelListId = $ids['channelListId'];
        $this->geoId = $ids['geoId'];
    }
}