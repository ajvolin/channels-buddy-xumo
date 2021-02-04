<?php

namespace ChannelsBuddy\Xumo\Services;

use ChannelsBuddy\SourceProvider\Models\Airing;
use ChannelsBuddy\SourceProvider\Models\Channel;
use ChannelsBuddy\SourceProvider\Models\Channels;
use ChannelsBuddy\SourceProvider\Models\Guide;
use ChannelsBuddy\SourceProvider\Models\GuideEntry;
use ChannelsBuddy\SourceProvider\Contracts\ChannelSource;
use Carbon\Carbon;
use Carbon\CarbonInterval;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
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

    public function __construct()
    {
        $this->loginUrl =
            'http://www.xumo.tv';
        $this->baseUrl =
            'https://valencia-app-mds.xumo.com/v2/';

        $this->httpClient = new Client(['base_uri' => $this->baseUrl]);

        $this->setChannelListAndGeoId();
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getChannels(?string $device = null): Channels
    {
        $onNowStream = $this->httpClient->get(
            sprintf(
                'channels/list/%s/onnowandnext.json?f=asset.title&f=asset.descriptions.json',
                $this->channelListId
            )
        );
        $onNowJson = $onNowStream->getBody()->getContents();

        $onNowChannels = collect(json_decode($onNowJson)->results)
        ->filter(function($onNowChannel) {
            return $onNowChannel->contentType == 'SIMULCAST';
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
        if (is_null($startTimestamp)) {
            $startTimestamp = Carbon::now()->timestamp;
        }

        if (is_null($duration)) {
            $duration = 86400;
        }

        $emptyProgramIntervalStartTime =
            Carbon::createFromTimestamp($startTimestamp);

        $emptyProgramIntervals = CarbonInterval::minutes(60)
        ->toPeriod(
                $emptyProgramIntervalStartTime,
                $emptyProgramIntervalStartTime
                    ->copy()
                    ->addSeconds($duration - 1)
        );

        $guideEntries = LazyCollection::make(function()
            use ($emptyProgramIntervals) {
            foreach ($this->getChannels()->channels as $channel) {
                $guideEntry = new GuideEntry($channel);

                $airings = LazyCollection::make(function()
                    use ($channel, $emptyProgramIntervals) {
                        foreach ($emptyProgramIntervals as $date) {
                            yield $this->generateAiringBlockForChannel(
                                    $channel,
                                    $date
                                );
                        }
                    });

                $guideEntry->airings = $airings;
                yield $guideEntry;
            }
        });

        return new Guide($guideEntries);
    }

    private function generateAiringBlockForChannel(Channel $channel, Carbon $date): Airing
    {
        $airingId = md5(
            $channel->id.$date->copy()->timestamp
        );

        $title = $channel->title
            ?? $channel->name
            ?? "To be announced";

        $subTitle = sprintf("%s hour long block", $title);

        $description = $channel->description
            ?? "To be announced";

        $seriesId = md5($channel->id);
        $programId = $seriesId . "." . $date->copy()->timestamp;
        $seasonNumber = $date->copy()->format("Y");
        $episodeNumber = $date->copy()->format("mdH");
        
        $airing = new Airing([
            'id'                    => $airingId,
            'channelId'             => $channel->id,
            'startTime'             => $date->copy()->startOfHour(),
            'stopTime'              => $date->copy()->endOfHour(),
            'length'                => 3600,
            'title'                 => $title,
            'subTitle'              => $subTitle,
            'description'           => $description,
            'programId'             => $programId,
            'seriesId'              => $seriesId,
            'seasonNumber'          => $seasonNumber,
            'episodeNumber'         => $episodeNumber,
            'originalReleaseDate'   => $date->copy(),
            'isMovie'               => false
        ]);

        $airing->addCategory($channel->getCategory());
            
        return $airing;
    }
    
    private function generateChannel(stdClass $channel): Channel
    {        
        $streamUrl = $this->getStreamUrl($channel->streamAssetId);

        return new Channel([
            "id"            => 'xumo.'.$channel->guid->value,
            "name"          => $channel->title,
            "number"        => $channel->number,
            "title"         => $channel->title,
            "callSign"      => $channel->callsign,
            "description"   => $this->getCleanDescription($channel->description),
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

    private function getCleanDescription(string $description): string
    {
        return preg_replace('/("|“|”)/m', '',
            preg_replace('/(\r\n|\n|\r)/m', ' ', $description)
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
            $asset = json_decode($assetJson);

            return $asset->providers[0]->sources[0]->uri ?? '';
        } catch (Throwable $e) {
            return '';
        }
    }

    private function setChannelListAndGeoId(): void
    {
        $ids = Cache::remember('channels-buddy-xumo.identifiers', 43200, function() {
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

                Log::info("Channels Buddy Xumo Source Provider: Channel List ID set to {$channelList[1]}");
                Log::info("Channels Buddy Xumo Source Provider: geoId set to {$geoId[1]}");

                return [
                    'channelListId' => $channelList[1],
                    'geoId' => $geoId[1]
                ];
            } catch (Throwable $e) {
                report("Channels Buddy Xumo Source Provider: Setup failed.");
            }
        });

        if (empty($ids)) {
            Cache::forget('channels-buddy-xumo.identifiers');
            report("Channels Buddy Xumo Source Provider: Failed to get location identifiers.");
            abort(500);
        } else {
            $this->channelListId = $ids['channelListId'];
            $this->geoId = $ids['geoId'];
        }
    }
}