<?php
/**
 * Plugin Name:       Local TTS
 * Description:       Adds a “Listen” button to posts. Speech is generated on the reader’s device with open voice models (EMA Lightning for Turkish, Piper for English, German, French and Spanish); post text is never sent to a speech service.
 * Version:           0.5.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            Local TTS contributors
 * Plugin URI:        https://github.com/m4v3r4/Wordpress-Local-TTS
 * Update URI:        https://github.com/m4v3r4/Wordpress-Local-TTS
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       local-tts
 * Domain Path:       /languages
 *
 * Local TTS is a tool for running open, local text-to-speech models in WordPress. It ships no voice
 * models: voices are downloaded by the site owner from their publishers, under their own licences.
 *
 * Copyright (C) 2025-2026 Local TTS contributors
 *
 * This program is free software: you can redistribute it and/or modify it under the terms of the GNU
 * General Public License as published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version. This program is distributed WITHOUT ANY WARRANTY;
 * see the GNU General Public License for more details: <https://www.gnu.org/licenses/>.
 *
 * @package Local_TTS
 */

defined( 'ABSPATH' ) || exit;

define( 'LTTS_VERSION', '0.5.0' );
define( 'LTTS_FILE', __FILE__ );
define( 'LTTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'LTTS_URL', plugin_dir_url( __FILE__ ) );

// Runtime version pinned together with the worker and engines. Voices are listed in includes/class-ltts-catalog.php.
define( 'LTTS_ORT_VERSION', '1.30.0' );

require_once LTTS_DIR . 'includes/class-ltts-settings.php';
require_once LTTS_DIR . 'includes/class-ltts-i18n.php';
require_once LTTS_DIR . 'includes/class-ltts-catalog.php';
require_once LTTS_DIR . 'includes/class-ltts-voices.php';
require_once LTTS_DIR . 'includes/class-ltts-storage.php';
require_once LTTS_DIR . 'includes/class-ltts-installer.php';
require_once LTTS_DIR . 'includes/class-ltts-migration.php';
require_once LTTS_DIR . 'includes/class-ltts-plugin.php';
require_once LTTS_DIR . 'includes/class-ltts-updater.php';

if ( is_admin() ) {
	require_once LTTS_DIR . 'includes/class-ltts-admin.php';
	LTTS_Admin::init();
}

LTTS_I18n::init();
LTTS_Plugin::init();
LTTS_Installer::init();
LTTS_Voices::init();
LTTS_Migration::init();
LTTS_Updater::init();

register_activation_hook( __FILE__, array( 'LTTS_Admin', 'activate' ) );
