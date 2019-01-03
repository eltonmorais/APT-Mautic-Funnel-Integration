<?php
if( function_exists('acf_add_options_page') ) {
	
	acf_add_options_sub_page(array(
		'page_title' 	=> 'Integração APP',
		'menu_title'	=> 'Integração APP',
		'menu_slug' 	=> 'appintegration-settings',
		'parent_slug'	=> 'options-general.php',
	));
}
?>