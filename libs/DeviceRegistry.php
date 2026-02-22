<?php

declare(strict_types=1);
require_once __DIR__ . '/Entity_Button.php';
require_once __DIR__ . '/Entity_Climate.php';
require_once __DIR__ . '/Entity_Cover.php';
require_once __DIR__ . '/Entity_IR_Emitter.php';
require_once __DIR__ . '/Entity_Light.php';
require_once __DIR__ . '/Entity_Media_Player.php';
require_once __DIR__ . '/Entity_Remote.php';
require_once __DIR__ . '/Entity_Sensor.php';
require_once __DIR__ . '/Entity_Switch.php';

class DeviceRegistry
{
    public static function getSupportedDevices(): array
    {
        return [
            [
                'name'            => 'Hue Light',
                'manufacturer'    => 'Signify',
                'guid'            => '{87FA14D1-0ACA-4CBD-BE83-BA4DF8831876}',
                'device_type'     => 'light',
                'device_sub_type' => 'rgbw',
                'features'        => [
                    Entity_Light::FEATURE_ON_OFF,
                    Entity_Light::FEATURE_DIM,
                    Entity_Light::FEATURE_COLOR,
                    Entity_Light::FEATURE_COLOR_TEMP
                ],
                'category'        => 'light_mapping',
                'attributes' => [
                    Entity_Light::ATTR_STATE             => 'on',              // Ident der Schaltvariable
                    Entity_Light::ATTR_BRIGHTNESS        => 'brightness',      // Ident der Helligkeit
                    Entity_Light::ATTR_HUE               => 'color',           // Ident der RGB-Farbe
                    Entity_Light::ATTR_SATURATION        => 'color',           // Ident der RGB-Farbe
                    Entity_Light::ATTR_COLOR_TEMPERATURE => 'color_temperature', // Ident der Farbtemperatur
                ]
            ],
            [
                'name'            => 'Hue Grouped Light',
                'manufacturer'    => 'Signify',
                'guid'            => '{6324AC4A-330C-4CB2-9281-12EECB450024}',
                'device_type'     => 'light',
                'device_sub_type' => 'rgbw',
                'features'        => [
                    Entity_Light::FEATURE_ON_OFF,
                    Entity_Light::FEATURE_DIM,
                    Entity_Light::FEATURE_COLOR,
                    Entity_Light::FEATURE_COLOR_TEMP
                ],
                'category'        => 'light_mapping',
                'attributes' => [
                    Entity_Light::ATTR_STATE             => 'on',              // Ident der Schaltvariable
                    Entity_Light::ATTR_BRIGHTNESS        => 'brightness',      // Ident der Helligkeit
                    Entity_Light::ATTR_HUE               => 'color',           // Ident der RGB-Farbe
                    Entity_Light::ATTR_SATURATION        => 'color',           // Ident der RGB-Farbe
                    Entity_Light::ATTR_COLOR_TEMPERATURE => 'color_temperature', // Ident der Farbtemperatur
                ]
            ],
            [
                'name'            => 'Sonos Speaker',
                'manufacturer'    => 'Sonos',
                'guid'            => '{52F6586D-A1C7-AAC6-309B-E12A70F6EEF6}',
                'device_type'     => 'media_player',
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_SPEAKER,
                'features'        => [
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_VOLUME,
                    Entity_Media_Player::FEATURE_MUTE,
                    Entity_Media_Player::FEATURE_UNMUTE,
                    Entity_Media_Player::FEATURE_PLAY_PAUSE,
                    Entity_Media_Player::FEATURE_NEXT,
                    Entity_Media_Player::FEATURE_PREVIOUS,
                    Entity_Media_Player::FEATURE_SHUFFLE,
                    Entity_Media_Player::FEATURE_REPEAT,
                    Entity_Media_Player::FEATURE_MEDIA_DURATION,
                    Entity_Media_Player::FEATURE_MEDIA_POSITION,
                    Entity_Media_Player::FEATURE_MEDIA_TITLE,
                    Entity_Media_Player::FEATURE_MEDIA_ARTIST,
                    Entity_Media_Player::FEATURE_MEDIA_ALBUM,
                    Entity_Media_Player::FEATURE_MEDIA_IMAGE_URL,
                    Entity_Media_Player::FEATURE_SELECT_SOURCE
                    ],
                'category'        => 'media_player_mapping',
                'attributes' => [
                    Entity_Media_Player::ATTR_STATE    => 'N/A',
                    Entity_Media_Player::ATTR_VOLUME   => 'Volume',
                    Entity_Media_Player::ATTR_MUTED => 'Mute',
                    Entity_Media_Player::ATTR_MEDIA_DURATION  => 'TrackDuration',
                    Entity_Media_Player::ATTR_MEDIA_POSITION  => 'Position',
                    Entity_Media_Player::ATTR_MEDIA_IMAGE_URL  => 'CoverURL',
                    Entity_Media_Player::ATTR_MEDIA_TITLE  => 'Title',
                    Entity_Media_Player::ATTR_MEDIA_ARTIST  => 'Artist',
                    Entity_Media_Player::ATTR_MEDIA_ALBUM  => 'Album',
                    Entity_Media_Player::ATTR_REPEAT  => 'PlayMode',
                    Entity_Media_Player::ATTR_SHUFFLE  => 'Shuffle',
                    Entity_Media_Player::ATTR_SOURCE  => 'Playlist',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_TYPE => 'N/A',
                ]
            ],
            [
                'name'            => 'Denon AVR',
                'manufacturer'    => 'Denon',
                'guid'            => '{DC733830-533B-43CD-98F5-23FC2E61287F}',
                'device_type'     => 'media_player',
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_RECEIVER,
                'features'        => [
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_VOLUME,
                    Entity_Media_Player::FEATURE_MUTE,
                    Entity_Media_Player::FEATURE_UNMUTE,
                    Entity_Media_Player::FEATURE_SELECT_SOURCE,
                    Entity_Media_Player::FEATURE_SELECT_SOUND_MODE
                ],
                'category'        => 'media_player_mapping',
                'attributes' => [
                    Entity_Media_Player::ATTR_STATE    => 'PW',
                    Entity_Media_Player::ATTR_VOLUME   => 'MV',
                    Entity_Media_Player::ATTR_MUTED => 'MU',
                    Entity_Media_Player::ATTR_SOURCE => 'SI',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE => 'MS',
                    Entity_Media_Player::ATTR_SOUND_MODE_LIST => 'N/A',
                ]
            ],
            [
                'name'            => 'Spotify',
                'manufacturer'    => 'Spotify',
                'guid'            => '{DCC40FC6-4447-AA1A-E3E5-B5F32DF81806}',
                'device_type'     => 'media_player',
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_SPEAKER,
                'features'        => [
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_VOLUME,
                    Entity_Media_Player::FEATURE_PLAY_PAUSE,
                    Entity_Media_Player::FEATURE_NEXT,
                    Entity_Media_Player::FEATURE_PREVIOUS,
                    Entity_Media_Player::FEATURE_MEDIA_DURATION,
                    Entity_Media_Player::FEATURE_MEDIA_POSITION,
                    Entity_Media_Player::FEATURE_MEDIA_TITLE,
                    Entity_Media_Player::FEATURE_MEDIA_ARTIST,
                    Entity_Media_Player::FEATURE_MEDIA_ALBUM,
                    Entity_Media_Player::FEATURE_MEDIA_IMAGE_URL,
                    Entity_Media_Player::FEATURE_SELECT_SOURCE,
                ],
                'category'        => 'media_player_mapping',
                'attributes' => [
                    Entity_Media_Player::ATTR_STATE    => 'N/A',
                    Entity_Media_Player::ATTR_VOLUME   => 'Volume',
                    Entity_Media_Player::ATTR_MEDIA_DURATION  => 'CurrentDuration',
                    Entity_Media_Player::ATTR_MEDIA_POSITION  => 'CurrentPosition',
                    Entity_Media_Player::ATTR_MEDIA_IMAGE_URL  => 'Cover',
                    Entity_Media_Player::ATTR_MEDIA_TITLE  => 'CurrentTrack',
                    Entity_Media_Player::ATTR_MEDIA_ARTIST  => 'CurrentArtist',
                    Entity_Media_Player::ATTR_MEDIA_ALBUM  => 'CurrentAlbum',
                    Entity_Media_Player::ATTR_REPEAT  => 'Repeat',
                    Entity_Media_Player::ATTR_SHUFFLE  => 'Shuffle',
                    Entity_Media_Player::ATTR_SOURCE  => 'Device',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_MUTED => 'N/A',
                ]
            ],
            [
                'name'            => 'HEOS',
                'manufacturer'    => 'Denon',
                'guid'            => '{68ED7CBB-76B7-4C24-07A2-61304D38CACD}',
                'device_type'     => 'media_player',
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_SPEAKER,
                'features'        => [
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_VOLUME,
                    Entity_Media_Player::FEATURE_MUTE,
                    Entity_Media_Player::FEATURE_UNMUTE,
                    Entity_Media_Player::FEATURE_PLAY_PAUSE,
                    Entity_Media_Player::FEATURE_NEXT,
                    Entity_Media_Player::FEATURE_PREVIOUS,
                ],
                'category'        => 'media_player_mapping',
                'attributes' => [
                    Entity_Media_Player::ATTR_STATE => 'Power',
                    Entity_Media_Player::ATTR_VOLUME => 'Volume',
                    Entity_Media_Player::ATTR_MUTED => 'Muted',

                    // Optional metadata (if available in the used HEOS/Denon integration)
                    Entity_Media_Player::ATTR_MEDIA_TITLE => 'Title',
                    Entity_Media_Player::ATTR_MEDIA_ARTIST => 'Artist',
                    Entity_Media_Player::ATTR_MEDIA_ALBUM => 'Album',
                    Entity_Media_Player::ATTR_MEDIA_IMAGE_URL => 'N/A',

                    // Optional playback modes
                    Entity_Media_Player::ATTR_SHUFFLE => 'N/A',
                    Entity_Media_Player::ATTR_REPEAT => 'N/A',

                    // Optional selections
                    Entity_Media_Player::ATTR_SOURCE => 'N/A',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE_LIST => 'N/A',
                ]
            ],
            [
                'name'            => 'HomePod',
                'manufacturer'    => 'Apple',
                'guid'            => '{D5C53262-AEEF-AA8A-9EC5-940E6B95A9A8}',
                'device_type'     => 'media_player',
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_SPEAKER,
                'features'        => [
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_VOLUME,
                    Entity_Media_Player::FEATURE_MUTE,
                    Entity_Media_Player::FEATURE_UNMUTE,
                    Entity_Media_Player::FEATURE_PLAY_PAUSE,
                    Entity_Media_Player::FEATURE_NEXT,
                    Entity_Media_Player::FEATURE_PREVIOUS,
                ],
                'category'        => 'media_player_mapping',
                'attributes' => [
                    Entity_Media_Player::ATTR_STATE => 'Power',
                    Entity_Media_Player::ATTR_VOLUME => 'Volume',
                    Entity_Media_Player::ATTR_MUTED => 'Muted',

                    Entity_Media_Player::ATTR_MEDIA_TITLE => 'Title',
                    Entity_Media_Player::ATTR_MEDIA_ARTIST => 'Artist',
                    Entity_Media_Player::ATTR_MEDIA_ALBUM => 'Album',
                    Entity_Media_Player::ATTR_MEDIA_IMAGE_URL => 'N/A',

                    Entity_Media_Player::ATTR_SHUFFLE => 'N/A',
                    Entity_Media_Player::ATTR_REPEAT => 'N/A',

                    Entity_Media_Player::ATTR_SOURCE => 'N/A',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE_LIST => 'N/A',
                ]
            ],
            [
                'name'            => 'Apple TV',
                'manufacturer'    => 'Apple',
                'guid' => '{D5C53262-AEEF-AA8A-9EC5-940E6B95A9A8}',
                'device_type'     => 'media_player',
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_STREAMING_BOX,
                'features'        => [
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_VOLUME,
                    Entity_Media_Player::FEATURE_MUTE,
                    Entity_Media_Player::FEATURE_UNMUTE,
                    Entity_Media_Player::FEATURE_PLAY_PAUSE,
                    Entity_Media_Player::FEATURE_NEXT,
                    Entity_Media_Player::FEATURE_PREVIOUS,
                ],
                'category'        => 'media_player_mapping',
                'attributes' => [
                    Entity_Media_Player::ATTR_STATE => 'Power',
                    Entity_Media_Player::ATTR_VOLUME => 'Volume',
                    Entity_Media_Player::ATTR_MUTED => 'Muted',

                    Entity_Media_Player::ATTR_MEDIA_TITLE => 'Title',
                    Entity_Media_Player::ATTR_MEDIA_ARTIST => 'Artist',
                    Entity_Media_Player::ATTR_MEDIA_ALBUM => 'Album',
                    Entity_Media_Player::ATTR_MEDIA_IMAGE_URL => 'N/A',

                    Entity_Media_Player::ATTR_SOURCE => 'N/A',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE_LIST => 'N/A',

                    Entity_Media_Player::ATTR_SHUFFLE => 'N/A',
                    Entity_Media_Player::ATTR_REPEAT => 'N/A',
                ]
            ],
            [
                'name'            => 'PlayStation 4',
                'manufacturer'    => 'Sony',
                'guid' => '{D4AF1A75-D35E-4592-944D-67736220182E}',
                'device_type'     => 'media_player',
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_STREAMING_BOX,
                'features'        => [
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_VOLUME,
                    Entity_Media_Player::FEATURE_MUTE,
                    Entity_Media_Player::FEATURE_UNMUTE,
                    Entity_Media_Player::FEATURE_PLAY_PAUSE,
                    Entity_Media_Player::FEATURE_NEXT,
                    Entity_Media_Player::FEATURE_PREVIOUS,
                ],
                'category'        => 'media_player_mapping',
                'attributes' => [
                    Entity_Media_Player::ATTR_STATE => 'Power',
                    Entity_Media_Player::ATTR_VOLUME => 'Volume',
                    Entity_Media_Player::ATTR_MUTED => 'Muted',

                    Entity_Media_Player::ATTR_MEDIA_TITLE => 'Title',
                    Entity_Media_Player::ATTR_MEDIA_ARTIST => 'Artist',
                    Entity_Media_Player::ATTR_MEDIA_ALBUM => 'Album',
                    Entity_Media_Player::ATTR_MEDIA_IMAGE_URL => 'N/A',

                    Entity_Media_Player::ATTR_SOURCE => 'N/A',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE_LIST => 'N/A',

                    Entity_Media_Player::ATTR_SHUFFLE => 'N/A',
                    Entity_Media_Player::ATTR_REPEAT => 'N/A',
                ]
            ],
            [
                'name'            => 'Homematic IP Rollladen',
                'manufacturer'    => 'eQ-3',
                'guid'            => '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}',
                'device_type'     => 'cover',
                'device_sub_type' => Entity_Cover::DEVICE_CLASS_BLIND,
                'features'        => [
                    Entity_Cover::FEATURE_OPEN,
                    Entity_Cover::FEATURE_CLOSE,
                    Entity_Cover::FEATURE_STOP,
                    Entity_Cover::FEATURE_POSITION,
                ],
                'category'        => 'cover_mapping',
                'attributes' => [
                    Entity_Cover::ATTR_POSITION    => 'LEVEL',
                    Entity_Cover::ATTR_STATE   => 'LEVEL'
                ]
            ]
        ];
    }

    public static function getDeviceMappingByGUID(string $guid): ?array
    {
        $guid = strtoupper(trim($guid));
        foreach (self::getSupportedDevices() as $def) {
            if (!is_array($def)) {
                continue;
            }
            $dGuid = strtoupper(trim((string)($def['guid'] ?? '')));
            if ($dGuid !== '' && $dGuid === $guid) {
                return $def;
            }
        }
        return null;
    }
}
