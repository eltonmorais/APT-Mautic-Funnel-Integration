<?php

namespace apt\thewhale;

/**
 * @package APT Mautic Funnel Integration
 * @version 0.13.2
 */
/*
Plugin Name: APT Mautic Funnel Integration
Plugin URI: http://autopilottools.com
Description: Permite ajustar o conteúdo de cada página de acordo com o usuário que recomendou o acesso.
Author: Auto Pilot Tools
Version: 0.13.2
Author URI: http://autopilottools.com/
*/

//Insert The Whale Framework embedder
$dir = __DIR__;
require_once 'the-whale/embedder.php';

//Insert the Config
require_once 'config.php';

/**
 * We execute our plugin, passing this file so they can find assetsour config file
 */
$plugin_file = __FILE__;
$mautic_funnel_integration = new mautic_funnel_integration($config);

?>