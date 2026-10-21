<?php
/**
 * Plugin Name: Olerant SVG Animation Gallery
 * Description: Private SVG intake, owner review and an isolated animation gallery.
 * Version: 0.1.3
 */
if (!defined('ABSPATH')) { exit; }
define('OSVG_VERSION','0.1.3');
define('OSVG_PLAYER_VERSION','svg-gsap-player-1');
define('OSVG_GSAP_SHA','92bb9a96476f983d212a2bc4f54c889039c1696dd4461d40a736860938570fbb');
require_once __DIR__.'/validation.php';
require_once __DIR__.'/store.php';
require_once __DIR__.'/api.php';
require_once __DIR__.'/public.php';
require_once __DIR__.'/admin.php';
require_once __DIR__.'/bulk.php';
register_activation_hook(__FILE__,'osvg_install');
add_action('rest_api_init','osvg_routes');
add_action('admin_menu','osvg_admin_menu');
add_action('admin_post_osvg_action','osvg_admin_action');
add_action('template_redirect','osvg_binary_route',-10);
add_shortcode('olerant_svg_gallery','osvg_catalog');
add_shortcode('olerant_svg_animation','osvg_detail');
