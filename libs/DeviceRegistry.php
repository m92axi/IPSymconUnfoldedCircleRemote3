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
    // --- Device Types ---
    public const DEVICE_TYPE_BUTTON = 'button'; // Device Type Button
    public const DEVICE_TYPE_SWITCH = 'switch'; // Device Type Switch
    public const DEVICE_TYPE_CLIMATE = 'climate'; // Device Type Climate
    public const DEVICE_TYPE_COVER = 'cover'; // Device Type Cover
    public const DEVICE_TYPE_LIGHT = 'light'; // Device Type Light
    public const DEVICE_TYPE_MEDIA_PLAYER = 'media_player'; // Device Type Media Player
    public const DEVICE_TYPE_REMOTE = 'remote'; // Device Type Remote
    public const DEVICE_TYPE_SENSOR = 'sensor'; // Device Type Sensor
    public const DEVICE_TYPE_IR_EMITTER = 'ir_emitter'; // Device Type IR-Emitter
    public const DEVICE_TYPE_SELECT = 'select'; // Device Type Select
    public const DEVICE_TYPE_VOICE_ASSISTANT = 'voice_assistant'; // Device Type Voice Assistant
    public const DEVICE_TYPE_MIXED = 'mixed'; // Device Type is not defined by GUID for example KNX, Homematic IP


    public static function getSupportedDevices(): array
    {
        return [
            [
                'name'            => 'Hue Light',
                'manufacturer'    => 'Signify',
                'guid'            => '{87FA14D1-0ACA-4CBD-BE83-BA4DF8831876}',
                'device_type' => self::DEVICE_TYPE_LIGHT,
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
                'device_type' => self::DEVICE_TYPE_LIGHT,
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
                'device_type' => self::DEVICE_TYPE_MEDIA_PLAYER,
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
                'device_type' => self::DEVICE_TYPE_MEDIA_PLAYER,
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
                'device_type' => self::DEVICE_TYPE_MEDIA_PLAYER,
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
                'device_type' => self::DEVICE_TYPE_MEDIA_PLAYER,
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
                'device_type' => self::DEVICE_TYPE_MEDIA_PLAYER,
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
                'device_type' => self::DEVICE_TYPE_MEDIA_PLAYER,
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
                'device_type' => self::DEVICE_TYPE_MEDIA_PLAYER,
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_STREAMING_BOX,
                'features'        => [
                    // Based on the Symcon PS4 module dump we currently only have a reliable power state.
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_DPAD
                ],
                'category' => 'media_player_mapping',
                'attributes' => [
                    Entity_Media_Player::ATTR_STATE => 'PS4_Power',

                    // Not available in the current Symcon PS4 module instance
                    Entity_Media_Player::ATTR_VOLUME => 'N/A',
                    Entity_Media_Player::ATTR_MUTED => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_DURATION => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_POSITION => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_IMAGE_URL => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_TITLE => 'PS4_AppTitle',
                    Entity_Media_Player::ATTR_MEDIA_ARTIST => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_ALBUM => 'N/A',
                    Entity_Media_Player::ATTR_SOURCE => 'N/A',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_SHUFFLE => 'N/A',
                    Entity_Media_Player::ATTR_REPEAT => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_TYPE => 'N/A'
                ]
            ],
            [
                'name' => 'Sony TV',
                'manufacturer' => 'Sony',
                'guid' => '{3B91F3E3-FB8F-4E3C-A4BB-4E5C92BBCD58}',
                'device_type' => self::DEVICE_TYPE_MEDIA_PLAYER,
                // We keep this generic to avoid dependency on a possibly missing TV constant
                'device_sub_type' => Entity_Media_Player::DEVICE_CLASS_TV,
                'features' => [
                    Entity_Media_Player::FEATURE_ON_OFF,
                    Entity_Media_Player::FEATURE_VOLUME,
                    Entity_Media_Player::FEATURE_MUTE,
                    Entity_Media_Player::FEATURE_UNMUTE,
                    Entity_Media_Player::FEATURE_SELECT_SOURCE,
                ],
                'category'        => 'media_player_mapping',
                'attributes' => [
                    // PowerStatus: 0=off, 1=standby, 2=on (module-specific mapping will be handled elsewhere)
                    Entity_Media_Player::ATTR_STATE => 'PowerStatus',
                    Entity_Media_Player::ATTR_VOLUME => 'SpeakerVolume',
                    Entity_Media_Player::ATTR_MUTED => 'AudioMute',
                    Entity_Media_Player::ATTR_SOURCE => 'InputSource',
                    Entity_Media_Player::ATTR_SOURCE_LIST => 'N/A',

                    // Optional / not provided by this module dump
                    Entity_Media_Player::ATTR_MEDIA_TITLE => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_ARTIST => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_ALBUM => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_IMAGE_URL => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_DURATION => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_POSITION => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE => 'N/A',
                    Entity_Media_Player::ATTR_SOUND_MODE_LIST => 'N/A',
                    Entity_Media_Player::ATTR_SHUFFLE => 'N/A',
                    Entity_Media_Player::ATTR_REPEAT => 'N/A',
                    Entity_Media_Player::ATTR_MEDIA_TYPE => 'N/A',
                ]
            ],
            [
                'name' => 'Sensibo',
                'manufacturer' => 'Sensibo',
                'guid' => '{661213AB-C412-087F-7F96-4FCBAA704433}',
                'device_type' => self::DEVICE_TYPE_CLIMATE,
                'device_sub_type' => 'ac',
                'features' => [
                    Entity_Climate::FEATURE_ON_OFF,
                    Entity_Climate::FEATURE_TARGET_TEMPERATURE,
                    Entity_Climate::FEATURE_CURRENT_TEMPERATURE
                ],
                'category' => 'climate_mapping',
                'attributes' => [
                    Entity_Climate::ATTR_TARGET_TEMPERATURE => 'acStatetargetTemperature',
                    Entity_Climate::ATTR_CURRENT_TEMPERATURE => 'measurementstemperature',
                ]
            ],
            // Netatmo Weather: multiple device variants share the same GUID.
            // We map each measurement ident as its own Sensor entity and differentiate via `match.required_child_idents`.
            [
                'name' => 'Netatmo Sensor: Temperature',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'temperature',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Temperature',
                    Entity_Sensor::ATTR_UNIT => 'unit:°C',
                ],
                'match' => [
                    'required_child_idents' => ['Temperature']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Humidity',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'humidity',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Humidity',
                    Entity_Sensor::ATTR_UNIT => 'unit:%',
                ],
                'match' => [
                    'required_child_idents' => ['Humidity']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Absolute Humidity',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'absolute_humidity',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'AbsoluteHumidity',
                    Entity_Sensor::ATTR_UNIT => 'unit:g/m³',
                ],
                'match' => [
                    'required_child_idents' => ['AbsoluteHumidity']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: CO2',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'co2',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'CO2',
                    Entity_Sensor::ATTR_UNIT => 'unit:ppm',
                ],
                'match' => [
                    'required_child_idents' => ['CO2']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Noise',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'noise',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Noise',
                    Entity_Sensor::ATTR_UNIT => 'unit:dB',
                ],
                'match' => [
                    'required_child_idents' => ['Noise']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Pressure',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'pressure',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Pressure',
                    Entity_Sensor::ATTR_UNIT => 'unit:mbar',
                ],
                'match' => [
                    'required_child_idents' => ['Pressure']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Absolute Pressure',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'absolute_pressure',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'AbsolutePressure',
                    Entity_Sensor::ATTR_UNIT => 'unit:mbar',
                ],
                'match' => [
                    'required_child_idents' => ['AbsolutePressure']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Dewpoint',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'dewpoint',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Dewpoint',
                    Entity_Sensor::ATTR_UNIT => 'unit:°C',
                ],
                'match' => [
                    'required_child_idents' => ['Dewpoint']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Heatindex',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'heatindex',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Heatindex',
                    Entity_Sensor::ATTR_UNIT => 'unit:°C',
                ],
                'match' => [
                    'required_child_idents' => ['Heatindex']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Temperature Max',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'temperature_max',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'TemperatureMax',
                    Entity_Sensor::ATTR_UNIT => 'unit:°C',
                ],
                'match' => [
                    'required_child_idents' => ['TemperatureMax']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Temperature Min',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'temperature_min',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'TemperatureMin',
                    Entity_Sensor::ATTR_UNIT => 'unit:°C',
                ],
                'match' => [
                    'required_child_idents' => ['TemperatureMin']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Temperature Trend',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'temperature_trend',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'TemperatureTrend',
                    Entity_Sensor::ATTR_UNIT => 'unit:',
                ],
                'match' => [
                    'required_child_idents' => ['TemperatureTrend']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Pressure Trend',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'pressure_trend',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'PressureTrend',
                    Entity_Sensor::ATTR_UNIT => 'unit:',
                ],
                'match' => [
                    'required_child_idents' => ['PressureTrend']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Rain',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'rain',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Rain',
                    Entity_Sensor::ATTR_UNIT => 'unit:mm',
                ],
                'match' => [
                    'required_child_idents' => ['Rain']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Rain (1h)',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'rain_1h',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Rain_1h',
                    Entity_Sensor::ATTR_UNIT => 'unit:mm',
                ],
                'match' => [
                    'required_child_idents' => ['Rain_1h']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Rain (24h)',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'rain_24h',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Rain_24h',
                    Entity_Sensor::ATTR_UNIT => 'unit:mm',
                ],
                'match' => [
                    'required_child_idents' => ['Rain_24h']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Wind Speed',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'wind_speed',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'WindSpeed',
                    Entity_Sensor::ATTR_UNIT => 'unit:km/h',
                ],
                'match' => [
                    'required_child_idents' => ['WindSpeed']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Wind Strength',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'wind_strength',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'WindStrength',
                    Entity_Sensor::ATTR_UNIT => 'unit:bft',
                ],
                'match' => [
                    'required_child_idents' => ['WindStrength']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Wind Angle',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'wind_angle',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'WindAngle',
                    Entity_Sensor::ATTR_UNIT => 'unit:°',
                ],
                'match' => [
                    'required_child_idents' => ['WindAngle']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Gust Speed',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'gust_speed',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'GustSpeed',
                    Entity_Sensor::ATTR_UNIT => 'unit:km/h',
                ],
                'match' => [
                    'required_child_idents' => ['GustSpeed']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Gust Strength',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'gust_strength',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'GustStrength',
                    Entity_Sensor::ATTR_UNIT => 'unit:bft',
                ],
                'match' => [
                    'required_child_idents' => ['GustStrength']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: RF Signal',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'rf_signal',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'RfSignal',
                    Entity_Sensor::ATTR_UNIT => 'unit:',
                ],
                'match' => [
                    'required_child_idents' => ['RfSignal']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Battery',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'battery',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Battery',
                    Entity_Sensor::ATTR_UNIT => 'unit:',
                ],
                'match' => [
                    'required_child_idents' => ['Battery']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Last Measure',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'last_measure',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'LastMeasure',
                    Entity_Sensor::ATTR_UNIT => 'unit:timestamp',
                ],
                'match' => [
                    'required_child_idents' => ['LastMeasure']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Temperature Max Timestamp',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'temperature_max_timestamp',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'TemperatureMaxTimestamp',
                    Entity_Sensor::ATTR_UNIT => 'unit:timestamp',
                ],
                'match' => [
                    'required_child_idents' => ['TemperatureMaxTimestamp']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Temperature Min Timestamp',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'temperature_min_timestamp',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'TemperatureMinTimestamp',
                    Entity_Sensor::ATTR_UNIT => 'unit:timestamp',
                ],
                'match' => [
                    'required_child_idents' => ['TemperatureMinTimestamp']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Gust Max Speed',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'gust_max_speed',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'GustMaxSpeed',
                    Entity_Sensor::ATTR_UNIT => 'unit:km/h',
                ],
                'match' => [
                    'required_child_idents' => ['GustMaxSpeed']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Gust Max Strength',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'gust_max_strength',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'GustMaxStrength',
                    Entity_Sensor::ATTR_UNIT => 'unit:bft',
                ],
                'match' => [
                    'required_child_idents' => ['GustMaxStrength']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Gust Max Timestamp',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'gust_max_timestamp',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'GustMaxTimestamp',
                    Entity_Sensor::ATTR_UNIT => 'unit:timestamp',
                ],
                'match' => [
                    'required_child_idents' => ['GustMaxTimestamp']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Gust Angle',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'gust_angle',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'GustAngle',
                    Entity_Sensor::ATTR_UNIT => 'unit:°',
                ],
                'match' => [
                    'required_child_idents' => ['GustAngle']
                ]
            ],
            [
                'name' => 'Netatmo Sensor: Gust Max Angle',
                'manufacturer' => 'Netatmo',
                'guid' => '{1023DB4A-D491-A0D5-17CD-380D3578D0FA}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => Entity_Sensor::DEVICE_CLASS_CUSTOM,
                'custom_sub_type' => 'gust_max_angle',
                'features' => [],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'GustMaxAngle',
                    Entity_Sensor::ATTR_UNIT => 'unit:°',
                ],
                'match' => [
                    'required_child_idents' => ['GustMaxAngle']
                ]
            ],
            [
                'name' => 'Homematic IP HCU Device',
                'manufacturer' => 'eQ-3',
                'guid' => '{36B89A98-2608-4272-8144-B8D572F7C708}',
                'device_type' => self::DEVICE_TYPE_MIXED,
                'device_sub_type' => 'hcu_device',
                'features' => [
                    // This is a generic placeholder mapping for the HCU device module.
                    // Concrete mapping depends on the functionalChannelType / variable idents.
                ],
                'category' => 'mixed_mapping',
                'attributes' => [
                    // Common base flags seen in multiple HCU devices
                    'low_battery' => '0_DEVICE_BASE_lowBat',
                    'unreach' => '0_DEVICE_BASE_unreach',
                ]
            ],
            [
                'name' => 'Homematic IP HCU Rollladen (auto-detected)',
                'manufacturer' => 'eQ-3',
                'guid' => '{36B89A98-2608-4272-8144-B8D572F7C708}',
                'device_type' => self::DEVICE_TYPE_COVER,
                'device_sub_type' => Entity_Cover::DEVICE_CLASS_BLIND,
                'features' => [
                    Entity_Cover::FEATURE_OPEN,
                    Entity_Cover::FEATURE_CLOSE,
                    Entity_Cover::FEATURE_STOP,
                    Entity_Cover::FEATURE_POSITION,
                ],
                'category' => 'cover_mapping',
                'attributes' => [
                    // HCU shading actuator
                    Entity_Cover::ATTR_POSITION => '1_SHADING_ACTUATOR_shutterLevel',
                    Entity_Cover::ATTR_STATE => '1_SHADING_ACTUATOR_shutterLevel'
                ],
                // Generic matching rules so we can differentiate multiple device types on the same GUID
                'match' => [
                    'required_child_idents' => [
                        '1_SHADING_ACTUATOR_shutterLevel'
                    ]
                ]
            ],
            [
                'name' => 'KNX EIS Group',
                'manufacturer' => 'KNX',
                'guid' => '{D62B95D3-0C5E-406E-B1D9-8D102E50F64B}',
                'device_type' => self::DEVICE_TYPE_SENSOR,
                'device_sub_type' => 'knx_group',
                'features' => [
                ],
                'category' => 'sensor_mapping',
                'attributes' => [
                    Entity_Sensor::ATTR_VALUE => 'Value',
                ]
            ],
            [
                'name'            => 'Homematic IP Rollladen',
                'manufacturer'    => 'eQ-3',
                'guid'            => '{EE4A81C6-5C90-4DB7-AD2F-F6BBD521412E}',
                'device_type' => self::DEVICE_TYPE_COVER,
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
            ],
            [
                'name' => 'Harmony Device',
                'manufacturer' => 'Logitech',
                'guid' => '{B0B4D0C2-192E-4669-A624-5D5E72DBB555}',
                'device_type' => self::DEVICE_TYPE_REMOTE,
                'device_sub_type' => 'harmony_device',
                'features' => [
                    Entity_Remote::FEATURE_SEND_COMMAND
                ],
                'category' => 'remote_mapping',
                'attributes' => [
                ]
            ]
        ];
    }

    /**
     * Return *all* registry entries for a given GUID (case-insensitive).
     * Useful when a single Symcon module GUID can represent multiple device types (e.g. Homematic HCU, Netatmo).
     */
    public static function getDeviceMappingsByGUID(string $guid): array
    {
        $guid = strtoupper(trim($guid));
        $out = [];
        foreach (self::getSupportedDevices() as $def) {
            if (!is_array($def)) {
                continue;
            }
            $dGuid = strtoupper(trim((string)($def['guid'] ?? '')));
            if ($dGuid !== '' && $dGuid === $guid) {
                $out[] = $def;
            }
        }
        return $out;
    }

    /**
     * Resolve a variable id for a given feature/attribute of an entity type.
     * This is a utility for mapping features (e.g. position, control) to variable ids.
     *
     * @param string $entityType DeviceRegistry::DEVICE_TYPE_*
     * @param array $attrs Mapping of attribute => variable ident or variable id
     * @param string $feature Attribute/feature to resolve (e.g. Entity_Cover::ATTR_CONTROL)
     * @return int|null
     */
    public static function ResolveFeatureVarID(string $entityType, array $attrs, string $feature)
    {
        // Cover: if a dedicated control mapping is requested but not present, fall back to state/position.
        if ($entityType === self::DEVICE_TYPE_COVER) {
            if (defined('Entity_Cover::ATTR_CONTROL')) {
                if (!isset($attrs[\Entity_Cover::ATTR_CONTROL]) && isset($attrs[\Entity_Cover::ATTR_POSITION])) {
                    $attrs[\Entity_Cover::ATTR_CONTROL] = $attrs[\Entity_Cover::ATTR_POSITION];
                }
                if (!isset($attrs[\Entity_Cover::ATTR_CONTROL]) && isset($attrs[\Entity_Cover::ATTR_STATE])) {
                    $attrs[\Entity_Cover::ATTR_CONTROL] = $attrs[\Entity_Cover::ATTR_STATE];
                }
            }
        }
        if (isset($attrs[$feature])) {
            $val = $attrs[$feature];

            // Sensor units may be stored as literal strings (not variable idents). Use the prefix `unit:`.
            // Example: Entity_Sensor::ATTR_UNIT => 'unit:°C'
            if ($entityType === self::DEVICE_TYPE_SENSOR && $feature === Entity_Sensor::ATTR_UNIT && is_string($val)) {
                $v = trim($val);
                if (stripos($v, 'unit:') === 0) {
                    return trim(substr($v, 5));
                }
            }

            return $val;
        }
        return null;
    }

    /**
     * Resolve the best matching mapping for a concrete Symcon instance.
     * This enables multiple registry entries with the same GUID.
     *
     * @param string $guid Instance module GUID
     * @param int $instanceId Symcon instance id (used for match rules; 0 disables instance checks)
     * @param string|null $preferredType Optional device type filter (e.g. self::DEVICE_TYPE_COVER)
     */
    public static function resolveDeviceMapping(string $guid, int $instanceId = 0, ?string $preferredType = null): ?array
    {
        $candidates = self::getDeviceMappingsByGUID(strtoupper(trim($guid)));
        if ($preferredType !== null && $preferredType !== '') {
            $candidates = array_values(array_filter($candidates, static function ($def) use ($preferredType) {
                return is_array($def) && (($def['device_type'] ?? null) === $preferredType);
            }));
        }

        if (count($candidates) === 0) {
            return null;
        }

        // If no instance is provided, fall back to the first candidate.
        if ($instanceId <= 0) {
            return $candidates[0];
        }

        // Score candidates: match rules first, then prefer more specific definitions.
        $best = null;
        $bestScore = -1;
        foreach ($candidates as $def) {
            if (!is_array($def)) {
                continue;
            }
            $score = self::scoreMappingForInstance($instanceId, $def);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $def;
            }
        }

        // If nothing matched, decide whether a fallback is allowed.
        // For GUIDs where candidates include match rules (multi-purpose modules like Homematic HCU / Netatmo),
        // we must NOT fall back when no match rule succeeded.
        if ($bestScore < 0) {
            return null;
        }

        return $best ?? $candidates[0];
    }

    private static function scoreMappingForInstance(int $instanceId, array $def): int
    {
        $match = $def['match'] ?? null;

        // No match rules => lowest specificity, but still a valid fallback.
        if (!is_array($match)) {
            return 1;
        }

        // If match rules exist, they must be satisfied.
        if (!self::mappingMatchesInstance($instanceId, $match)) {
            return -1;
        }

        // Base score for successful match.
        $score = 100;

        // Prefer mappings with more required constraints.
        $score += count((array)($match['required_child_idents'] ?? [])) * 10;
        $score += count((array)($match['any_child_ident_regex'] ?? [])) * 5;
        $score += count((array)($match['required_child_profile_regex'] ?? [])) * 5;

        return $score;
    }

    private static function mappingMatchesInstance(int $instanceId, array $match): bool
    {
        // required_child_idents: all must exist
        $requiredIdents = (array)($match['required_child_idents'] ?? []);
        foreach ($requiredIdents as $ident) {
            if ($ident === '' || !self::instanceHasChildIdent($instanceId, (string)$ident)) {
                return false;
            }
        }

        // any_child_ident_regex: at least one regex must match a child ident
        $anyIdentRegex = (array)($match['any_child_ident_regex'] ?? []);
        if (count($anyIdentRegex) > 0) {
            $ok = false;
            foreach ($anyIdentRegex as $pattern) {
                if ($pattern !== '' && self::instanceHasChildIdentRegex($instanceId, (string)$pattern)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                return false;
            }
        }

        // required_child_profile_regex: all regex must match at least one child variable profile
        $requiredProfileRegex = (array)($match['required_child_profile_regex'] ?? []);
        foreach ($requiredProfileRegex as $pattern) {
            if ($pattern === '' || !self::instanceHasChildProfileRegex($instanceId, (string)$pattern)) {
                return false;
            }
        }

        return true;
    }

    private static function instanceHasChildIdent(int $instanceId, string $ident): bool
    {
        foreach (IPS_GetChildrenIDs($instanceId) as $childId) {
            $obj = IPS_GetObject($childId);
            if (($obj['ObjectType'] ?? null) !== OBJECTTYPE_VARIABLE) {
                continue;
            }
            if ((string)($obj['ObjectIdent'] ?? '') === $ident) {
                return true;
            }
        }
        return false;
    }

    private static function instanceHasChildIdentRegex(int $instanceId, string $pattern): bool
    {
        foreach (IPS_GetChildrenIDs($instanceId) as $childId) {
            $obj = IPS_GetObject($childId);
            if (($obj['ObjectType'] ?? null) !== OBJECTTYPE_VARIABLE) {
                continue;
            }
            $ident = (string)($obj['ObjectIdent'] ?? '');
            if ($ident !== '' && @preg_match($pattern, $ident)) {
                if (preg_match($pattern, $ident) === 1) {
                    return true;
                }
            }
        }
        return false;
    }

    private static function instanceHasChildProfileRegex(int $instanceId, string $pattern): bool
    {
        foreach (IPS_GetChildrenIDs($instanceId) as $childId) {
            $obj = IPS_GetObject($childId);
            if (($obj['ObjectType'] ?? null) !== OBJECTTYPE_VARIABLE) {
                continue;
            }
            $var = IPS_GetVariable($childId);
            $profile = (string)($var['VariableProfile'] ?? '');
            if ($profile !== '' && @preg_match($pattern, $profile)) {
                if (preg_match($pattern, $profile) === 1) {
                    return true;
                }
            }
        }
        return false;
    }
}
