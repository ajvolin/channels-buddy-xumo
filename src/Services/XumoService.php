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
use Illuminate\Support\LazyCollection;
use JsonMachine\JsonMachine;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use stdClass;

class StirrService implements ChannelSource
{
    protected $baseUrl;
    protected $baseStationUrl;
    protected $httpClient;
    protected $defaultChannelArtUrl =
        "https://komonews.com/resources/media2/4x3/full/1440/center/100/22bd9810-78d3-43d9-9425-ba4373465f58-full1x1_STIRR.Logo.BlackYellow01.png";
    private $sortValueNumber = 200;

    public function __construct()
    {
        $this->baseUrl =
            'https://ott-gateway-stirr.sinclairstoryline.com/api/rest/v3/';
        $this->baseStationUrl =
            'https://ott-stationselection.sinclairstoryline.com/stationAutoSelection';

        $this->httpClient = new Client(['base_uri' => $this->baseUrl]);
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getChannels(?string $device = null): Channels
    {
        $channels = LazyCollection::make(function() {
            $stationLineups = config(
                    'channels.channelSources.stirr.stationLineups', 
                    ['national']
                );

            $processedChannels = [];

            $lineupAutoSelectStream = $this->httpClient->get($this->baseStationUrl);
            $lineupAutoSelectJson = \GuzzleHttp\Psr7\StreamWrapper::getResource(
                $lineupAutoSelectStream->getBody()
            );
            $lineupAutoSelectList = JsonMachine::fromStream(
                $lineupAutoSelectJson, '/page', new ExtJsonDecoder
            );

            foreach ($lineupAutoSelectList as $lineups) {
                foreach ($lineups
                    ->button
                    ->{'media:content'}
                    ->{'sinclair:action_config'}
                    ->station as $lineup) {
                            
                    if(!in_array($lineup, $stationLineups)) {
                        array_push($stationLineups, $lineup);
                    }
                }
            }

            foreach ($stationLineups as $lineup) {
                $lineupChannelsStream = $this->httpClient->get(
                    sprintf('channels/stirr?station=%s', $lineup)
                );
                $lineupChannelsJson = \GuzzleHttp\Psr7\StreamWrapper::getResource(
                    $lineupChannelsStream->getBody()
                );
                $lineupChannels = JsonMachine::fromStream(
                    $lineupChannelsJson, '/channel', new ExtJsonDecoder
                );

                foreach ($lineupChannels as $lineupChannel) {
                    if (!in_array($lineupChannel->id, $processedChannels)) {
                        try {
                            $channelsStream = $this->httpClient->get(
                                sprintf('status/%s', $lineupChannel->id)
                            );
                            $channelsJson = \GuzzleHttp\Psr7\StreamWrapper::getResource(
                                $channelsStream->getBody()
                            );
                            $channels = JsonMachine::fromStream(
                                $channelsJson, '', new ExtJsonDecoder
                            );
                            foreach($channels as $channel) {
                                if (substr($channel->channel->title, 0, 3) != 'zzz') {
                                    try {
                                        $channelMediaStream = $this->httpClient->get(
                                            sprintf('stirr/media/%s', $lineupChannel->id)
                                        );
                                        $channelMediaJson = $channelMediaStream->getBody()->getContents();
                                        $channelMedia = json_decode($channelMediaJson);
                                    } catch (RequestException $e) {
                                        $channelMedia = null;
                                    }
                                    yield $lineupChannel->id =>
                                        $this->generateChannel(
                                            $lineupChannel,
                                            $channel,
                                            $channelMedia
                                        );
                                }
                            }
                        } catch (RequestException $e) {}
                        array_push($processedChannels, $lineupChannel->id);
                    }
                }
            }
        })->keyBy('id');
        
        return new Channels($channels);
    }

    public function getGuideData(?int $startTimestamp, ?int $duration, ?string $device = null): Guide
    {
        $guideEntries = LazyCollection::make(function() {
            $channels = $this->getChannels()->channels;
            foreach ($channels as $channel) {
                try {
                    $stream = $this->httpClient->get(
                        sprintf('program/stirr/ott/%s', $channel->id)
                    );
                    $json = \GuzzleHttp\Psr7\StreamWrapper::getResource(
                        $stream->getBody()
                    );
                    $guideData = JsonMachine::fromStream(
                        $json, '/programme', new ExtJsonDecoder
                    );
    
                    $guideEntry = new GuideEntry($channel);

                    $airings = LazyCollection::make(function() use ($channel, $guideData) {
                        foreach ($guideData as $entry) {        
                            yield $this->generateAiring($channel, $entry);
                        }
                    });

                    $guideEntry->airings = $airings;
                    yield $guideEntry;
                } catch (RequestException $e) {}
            }
        });
    
        return new Guide($guideEntries);
    }

    private function generateAiring(Channel $channel, stdClass $entry): Airing
    {
        $startTime = Carbon::parse($entry->start);
        $stopTime = Carbon::parse($entry->stop);
        $length = $startTime->copy()->diffInSeconds($stopTime);
        $airingId = $channel->id . $startTime->copy()->timestamp;
        $seriesId = md5($entry->title->value);
        $programId = $seriesId.".".md5($entry->desc->value);
        $isLive = filter_var($entry->{'sinclair:isLiveProgram'},
            FILTER_VALIDATE_BOOLEAN
        );

        $airing = new Airing([
            'id'                    => $airingId,
            'channelId'             => $channel->id,
            'source'                => "stirr",
            'title'                 => $entry->title->value,
            'titleLanguage'         => substr($entry->title->lang, 0, 2),
            'description'           => $entry->desc->value,
            'descriptionLanguage'   => substr($entry->desc->lang, 0, 2),
            'startTime'             => $startTime,
            'stopTime'              => $stopTime,
            'length'                => $length,
            'programId'             => $programId,
            'seriesId'              => $seriesId,
            'isLive'                => $isLive
        ]);
        
        if (isset($entry->category)) {
            foreach ($entry->category as $category) {
                if(substr($category->value, 0, 2) == 'HD') {
                    $airing->setIsHdtv(true);
                }
                if($category->value == 'Live') {
                    $airing->setIsLive(true);
                }
                if($category->value == 'New') {
                    $airing->setIsNew(true);
                }
                if($category->value == 'Stereo') {
                    $airing->setIsStereo(true);
                }
                if($category->value == 'CC') {
                    $airing->setHasClosedCaptioning(true);
                }
            }
        }

        return $airing;
    }
    
    private function generateChannel(stdClass $lineupChannel, stdClass $channel, stdClass $channelMedia = null): Channel
    {
        if (isset($lineupChannel->icon->src)) {
            $logo = str_replace(
                "180/center/90",
                "512/center/100",
                strtok(
                    $lineupChannel->icon->src, '?'
                )
            );
        } else {
            $logo = null;
        }

        if (!is_null($mediaUrls = $channelMedia
            ->rss
            ->channel
            ->item
            ->{'media:content'}
            ->{'media:thumbnail'} ?? null)) {
            
            $channelArt = collect($mediaUrls)
            ->filter(function($mediaUrl){
                return ($mediaUrl->width == $mediaUrl->height ||
                    $mediaUrl->width == "1920") &&
                    strpos($mediaUrl->url, "EPG.png") === false;
            })
            ->transform(function($mediaUrl){
                $mediaUrl->url = str_replace(
                    "media2/1x1",
                    "media2/4x3",
                    str_replace(
                        "center/90",
                        "center/100",
                        strtok($mediaUrl->url, '?')
                    )
                );
                return $mediaUrl;
            })->sortBy([
                ["width", "asc"],
                ["height", "desc"]
            ])->values()->first()->url ?? null;
        }

        if (trim($lineupChannel->{'display-name'}) == 'dco') {
            $sortValue = "1_" . $channel->channel->title;
        } else {
            $sortValue = $this->sortValueNumber;
            $this->sortValueNumber++;
        }

        return new Channel(
            [
                'id'            => $lineupChannel->id,
                'name'          => trim($channel->channel->title),
                'number'        => null,
                'title'         => trim($channel->channel->title),
                'callSign'      => trim($lineupChannel->{'display-name'}),
                'description'   => trim($channel->channel->description),
                'logo'          => $logo,
                'channelArt'    => $channelArt ?? $this->defaultChannelArtUrl,
                'category'      => trim($channel->channel->item->category),
                'streamUrl'     => $channel->channel->item->link,
                'sortValue'     => (string) $sortValue
            ]
        );
    }
}