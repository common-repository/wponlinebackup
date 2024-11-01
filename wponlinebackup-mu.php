<?php

// Online Backup for WordPress "Must Use Proxy PHP Loader"

// You should read the "Must Use" documentation for WordPress first:
// http://codex.wordpress.org/Must_Use_Plugins
// Where it mentions "Proxy PHP Loader" - this is the file you should use

// Installation overview: (always refer to to the WordPress documentation too)
// 1. To activate the plugin as a "Must Use" plugin, extract the plugin and place the "wponlinebackup" folder into your mu-plugins directory
// 2. Then copy this file into your mu-plugins directory as well, alongside the "wponlinebackup" folder

// If you decide to rename the "wponlinebackup" folder you'll need to adjust this path to match
require WPMU_PLUGIN_DIR . '/wponlinebackup/wponlinebackup.php';

?>
