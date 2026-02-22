<?php

declare(strict_types=1);

class Entity_Media_Player
{
    // --- States ---
    public const STATE_ON = 'ON'; // The media player is switched on
    public const STATE_OFF = 'OFF'; // The media player is switched off
    public const STATE_PLAYING = 'PLAYING'; // The media player is playing something
    public const STATE_PAUSED = 'PAUSED'; // The media player is paused
    public const STATE_STANDBY = 'STANDBY'; // The device is in low power state and accepting commands
    public const STATE_BUFFERING = 'BUFFERING'; // The media player is buffering to start playback


    // --- Device Classes ---
    public const DEVICE_CLASS_RECEIVER = 'receiver'; // Audio-video receiver.
    public const DEVICE_CLASS_SET_TOP_BOX = 'set_top_box'; // Set-top box for multichannel video and media playback.
    public const DEVICE_CLASS_SPEAKER = 'speaker'; // Smart speakers or stereo device.
    public const DEVICE_CLASS_STREAMING_BOX = 'streaming_box'; // Device for media streaming services.
    public const DEVICE_CLASS_TV = 'tv'; // Television device.

    // --- Commands (cmd_id) ---
    public const CMD_ON = 'on'; // Switch on media player.
    public const CMD_OFF = 'off'; // Switch off media player.
    public const CMD_TOGGLE = 'toggle'; // Toggle the current power state, either from on -> off or from off -> on.
    public const CMD_PLAY_PAUSE = 'play_pause'; // Toggle play / pause.
    public const CMD_STOP = 'stop'; // Stop playback.
    public const CMD_PREVIOUS = 'previous'; // Go back to previous track.
    public const CMD_NEXT = 'next'; // Skip to next track.
    public const CMD_FAST_FORWARD = 'fast_forward'; // Fast forward current track.
    public const CMD_REWIND = 'rewind'; // Rewind current track.
    public const CMD_SEEK = 'seek'; // Seek to given position in current track. Position is given in seconds.
    public const CMD_VOLUME = 'volume'; // Set volume to given level.
    public const CMD_VOLUME_UP = 'volume_up'; // Increase volume.
    public const CMD_VOLUME_DOWN = 'volume_down'; // Decrease volume.
    public const CMD_MUTE_TOGGLE = 'mute_toggle'; // Toggle mute state.
    public const CMD_MUTE = 'mute'; // Mute volume.
    public const CMD_UNMUTE = 'unmute'; // Unmute volume.
    public const CMD_REPEAT = 'repeat'; // Repeat track or playlist.
    public const CMD_SHUFFLE = 'shuffle'; // Shuffle playlist or start random playback.
    public const CMD_CHANNEL_UP = 'channel_up'; // Channel up.
    public const CMD_CHANNEL_DOWN = 'channel_down'; // Channel down.
    public const CMD_CURSOR_UP = 'cursor_up'; // Directional pad up.
    public const CMD_CURSOR_DOWN = 'cursor_down'; // Directional pad down.
    public const CMD_CURSOR_LEFT = 'cursor_left'; // Directional pad left.
    public const CMD_CURSOR_RIGHT = 'cursor_right'; // Directional pad right.
    public const CMD_CURSOR_ENTER = 'cursor_enter'; // Directional pad enter.
    public const CMD_DIGIT_0 = 'digit_0'; // Number pad digit 0.
    public const CMD_DIGIT_1 = 'digit_1'; // Number pad digit 1.
    public const CMD_DIGIT_2 = 'digit_2'; // Number pad digit 2.
    public const CMD_DIGIT_3 = 'digit_3'; // Number pad digit 3.
    public const CMD_DIGIT_4 = 'digit_4'; // Number pad digit 4.
    public const CMD_DIGIT_5 = 'digit_5'; // Number pad digit 5.
    public const CMD_DIGIT_6 = 'digit_6'; // Number pad digit 6.
    public const CMD_DIGIT_7 = 'digit_7'; // Number pad digit 7.
    public const CMD_DIGIT_8 = 'digit_8'; // Number pad digit 8.
    public const CMD_DIGIT_9 = 'digit_9'; // Number pad digit 9.
    public const CMD_FUNCTION_RED = 'function_red'; // Function red.
    public const CMD_FUNCTION_GREEN = 'function_green'; // Function green.
    public const CMD_FUNCTION_YELLOW = 'function_yellow'; // Function yellow.
    public const CMD_FUNCTION_BLUE = 'function_blue'; // Function blue.
    public const CMD_HOME = 'home'; // Home menu.
    public const CMD_MENU = 'menu'; // Menu.
    public const CMD_CONTEXT_MENU = 'context_menu'; // Context menu.
    public const CMD_GUIDE = 'guide'; // Program guide menu.
    public const CMD_INFO = 'info'; // Information menu / what's playing.
    public const CMD_BACK = 'back'; // Back / exit function for menu navigation (to exit menu, guide, info).
    public const CMD_SELECT_SOURCE = 'select_source'; // Select an input source from the available sources.
    public const CMD_SELECT_SOUND_MODE = 'select_sound_mode'; // Select a sound mode from the available modes.
    public const CMD_RECORD = 'record'; // Start, stop or open recording menu (device dependant).
    public const CMD_MY_RECORDINGS = 'my_recordings'; // Open recordings.
    public const CMD_LIVE = 'live'; // Switch to live view.
    public const CMD_EJECT = 'eject'; // Eject media.
    public const CMD_OPEN_CLOSE = 'open_close'; // Open or close.
    public const CMD_AUDIO_TRACK = 'audio_track'; // Switch or select audio track.
    public const CMD_SUBTITLE = 'subtitle'; // Switch or select subtitle.
    public const CMD_SETTINGS = 'settings'; // Settings menu.

    // --- Supported attributes ---
    public const ATTR_STATE = 'state'; // State of the media player, influenced by the play and power commands.
    public const ATTR_VOLUME = 'volume'; // Current volume level (0..100).
    public const ATTR_MUTED = 'muted'; // Flag if the volume is muted.
    public const ATTR_MEDIA_DURATION = 'media_duration'; // Media duration in seconds.
    public const ATTR_MEDIA_POSITION = 'media_position'; // Current media position in seconds.
    public const ATTR_MEDIA_POSITION_UPDATED_AT = 'media_position_updated_at'; // Timestamp when media_position was last updated (ISO 8601).
    public const ATTR_MEDIA_TYPE = 'media_type'; // The type of media being played.
    public const ATTR_MEDIA_IMAGE_URL = 'media_image_url'; // URL to retrieve the album art or an image representing what's being played.
    public const ATTR_MEDIA_TITLE = 'media_title'; // Currently playing media information (title).
    public const ATTR_MEDIA_ARTIST = 'media_artist'; // Currently playing media information (artist).
    public const ATTR_MEDIA_ALBUM = 'media_album'; // Currently playing media information (album).
    public const ATTR_REPEAT = 'repeat'; // Current repeat mode (OFF, ONE, ALL).
    public const ATTR_SHUFFLE = 'shuffle'; // Shuffle mode on or off.
    public const ATTR_SOURCE = 'source'; // Currently selected media or input source.
    public const ATTR_SOURCE_LIST = 'source_list'; // Available media or input sources (text list).
    public const ATTR_SOUND_MODE = 'sound_mode'; // Currently selected sound mode.
    public const ATTR_SOUND_MODE_LIST = 'sound_mode_list'; // Available sound modes (text list).

    // --- Supported features ---
    public const FEATURE_ON_OFF = 'on_off'; // The media player can be switched on and off.
    public const FEATURE_TOGGLE = 'toggle'; // The media player's power state can be toggled.
    public const FEATURE_VOLUME = 'volume'; // The volume level can be set to a specific level.
    public const FEATURE_VOLUME_UP_DOWN = 'volume_up_down'; // The volume can be adjusted up (louder) and down.
    public const FEATURE_MUTE_TOGGLE = 'mute_toggle'; // The mute state can be toggled.
    public const FEATURE_MUTE = 'mute'; // The volume can be muted.
    public const FEATURE_UNMUTE = 'unmute'; // The volume can be un-muted.
    public const FEATURE_PLAY_PAUSE = 'play_pause'; // The player supports starting and pausing media playback.
    public const FEATURE_STOP = 'stop'; // The player supports stopping media playback.
    public const FEATURE_NEXT = 'next'; // The player supports skipping to the next track.
    public const FEATURE_PREVIOUS = 'previous'; // The player supports returning to the previous track.
    public const FEATURE_FAST_FORWARD = 'fast_forward'; // The player supports fast-forwarding the current track.
    public const FEATURE_REWIND = 'rewind'; // The player supports rewinding the current track.
    public const FEATURE_REPEAT = 'repeat'; // The current track or playlist can be repeated.
    public const FEATURE_SHUFFLE = 'shuffle'; // The player supports random playback / shuffling the current playlist.
    public const FEATURE_SEEK = 'seek'; // The player supports seeking the playback position.
    public const FEATURE_MEDIA_DURATION = 'media_duration'; // The player announces the duration of the current media being played.
    public const FEATURE_MEDIA_POSITION = 'media_position'; // The player announces the current position of the media being played.
    public const FEATURE_MEDIA_TITLE = 'media_title'; // The player announces the media title.
    public const FEATURE_MEDIA_ARTIST = 'media_artist'; // The player announces the media artist.
    public const FEATURE_MEDIA_ALBUM = 'media_album'; // The player announces the media album if music is being played.
    public const FEATURE_MEDIA_IMAGE_URL = 'media_image_url'; // The player provides an image url of the media being played.
    public const FEATURE_MEDIA_TYPE = 'media_type'; // The player announces the type of media being played.
    public const FEATURE_DPAD = 'dpad'; // Directional pad navigation, provides up / down / left / right / enter commands.
    public const FEATURE_NUMPAD = 'numpad'; // Number pad, provides digit_0, .. , digit_9 commands.
    public const FEATURE_HOME = 'home'; // Home navigation support with home & back commands.
    public const FEATURE_MENU = 'menu'; // Menu navigation support with menu & back commands.
    public const FEATURE_CONTEXT_MENU = 'context_menu'; // Context menu (e.g. right clicking or long pressing an item).
    public const FEATURE_GUIDE = 'guide'; // Program guide support with guide & back commands.
    public const FEATURE_INFO = 'info'; // Information popup / menu support with info & back commands.
    public const FEATURE_COLOR_BUTTONS = 'color_buttons'; // Color button support for red / green / yellow / blue function commands.
    public const FEATURE_CHANNEL_SWITCHER = 'channel_switcher'; // Channel zapping support with channel up and down commands.
    public const FEATURE_SELECT_SOURCE = 'select_source'; // Media playback sources or inputs can be selected.
    public const FEATURE_SELECT_SOUND_MODE = 'select_sound_mode'; // Sound modes can be selected, e.g. stereo or surround.
    public const FEATURE_EJECT = 'eject'; // The media can be ejected, e.g. a slot-in CD or USB stick.
    public const FEATURE_OPEN_CLOSE = 'open_close'; // The player supports opening and closing, e.g. a disc tray.
    public const FEATURE_AUDIO_TRACK = 'audio_track'; // The player supports selecting or switching the audio track.
    public const FEATURE_SUBTITLE = 'subtitle'; // The player supports selecting or switching subtitles.
    public const FEATURE_RECORD = 'record'; // The player has recording capabilities with record, my_recordings, live commands.
    public const FEATURE_SETTINGS = 'settings'; // The player supports a settings menu.

    // --- Supported repeat values ---
    public const REPEAT_OFF = 'off';
    public const REPEAT_ONE = 'one';
    public const REPEAT_ALL = 'all';

    /**
     * Maps a supported feature to the attributes required to implement or represent that feature.
     *
     * This is used by integrations to automatically resolve variable mappings (e.g. via Ident -> VarID)
     * for a given feature.
     *
     * @param string $featureKey One of the FEATURE_* constants.
     * @return string[] List of required ATTR_* constants.
     */
    public static function featureToAttributes(string $featureKey): array
    {
        switch ($featureKey) {
            // Power
            case self::FEATURE_ON_OFF:
            case self::FEATURE_TOGGLE:
                return [self::ATTR_STATE];

            // Volume / mute
            case self::FEATURE_VOLUME:
            case self::FEATURE_VOLUME_UP_DOWN:
                return [self::ATTR_VOLUME];
            case self::FEATURE_MUTE_TOGGLE:
            case self::FEATURE_MUTE:
            case self::FEATURE_UNMUTE:
                return [self::ATTR_MUTED];

            // Playback controls
            case self::FEATURE_PLAY_PAUSE:
            case self::FEATURE_STOP:
            case self::FEATURE_NEXT:
            case self::FEATURE_PREVIOUS:
            case self::FEATURE_FAST_FORWARD:
            case self::FEATURE_REWIND:
            case self::FEATURE_SEEK:
                // Playback state is the primary state attribute; seek also relies on position.
                return [self::ATTR_STATE, self::ATTR_MEDIA_POSITION, self::ATTR_MEDIA_DURATION];

            // Playback modes
            case self::FEATURE_REPEAT:
                return [self::ATTR_REPEAT];
            case self::FEATURE_SHUFFLE:
                return [self::ATTR_SHUFFLE];

            // Now playing metadata
            case self::FEATURE_MEDIA_DURATION:
                return [self::ATTR_MEDIA_DURATION];
            case self::FEATURE_MEDIA_POSITION:
                return [self::ATTR_MEDIA_POSITION, self::ATTR_MEDIA_POSITION_UPDATED_AT];
            case self::FEATURE_MEDIA_TITLE:
                return [self::ATTR_MEDIA_TITLE];
            case self::FEATURE_MEDIA_ARTIST:
                return [self::ATTR_MEDIA_ARTIST];
            case self::FEATURE_MEDIA_ALBUM:
                return [self::ATTR_MEDIA_ALBUM];
            case self::FEATURE_MEDIA_IMAGE_URL:
                return [self::ATTR_MEDIA_IMAGE_URL];
            case self::FEATURE_MEDIA_TYPE:
                return [self::ATTR_MEDIA_TYPE];

            // Input / sound mode selection
            case self::FEATURE_SELECT_SOURCE:
                return [self::ATTR_SOURCE, self::ATTR_SOURCE_LIST];
            case self::FEATURE_SELECT_SOUND_MODE:
                return [self::ATTR_SOUND_MODE, self::ATTR_SOUND_MODE_LIST];

            // Navigation / UI features do not require readable attributes; they are command-only.
            // Still, keeping ATTR_STATE helps for availability/online state handling.
            case self::FEATURE_DPAD:
            case self::FEATURE_NUMPAD:
            case self::FEATURE_HOME:
            case self::FEATURE_MENU:
            case self::FEATURE_CONTEXT_MENU:
            case self::FEATURE_GUIDE:
            case self::FEATURE_INFO:
            case self::FEATURE_COLOR_BUTTONS:
            case self::FEATURE_CHANNEL_SWITCHER:
            case self::FEATURE_EJECT:
            case self::FEATURE_OPEN_CLOSE:
            case self::FEATURE_AUDIO_TRACK:
            case self::FEATURE_SUBTITLE:
            case self::FEATURE_RECORD:
            case self::FEATURE_SETTINGS:
                return [self::ATTR_STATE];

            default:
                return [];
        }
    }
}