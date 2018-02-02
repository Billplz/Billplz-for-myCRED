<?php

/**
 * Plugin Name: Billplz for myCRED
 * Plugin URI: http://github.com/billplzplugin/Billplz-for-myCRED
 * Description: Billplz Payment Gateway | Accept Payment using all participating FPX Banking Channels. <a href="https://www.billplz.com/join/8ant7x743awpuaqcxtqufg" target="_blank">Sign up Now</a>.
 * Author: Wan @ Billplz
 * Author URI: http://www.github.com/billplzplugin
 * Version: 3.00
 * License: GPLv3
 */

//error_reporting(E_ALL);
//ini_set('display_errors', 'On');

add_filter('mycred_setup_gateways', 'billplz_payment_gateway_mycred');
function billplz_payment_gateway_mycred($installed)
{

    // Add a custom remote gateway
    $installed['billplz_gateway'] = array(
        'title'    => 'Billplz Payment Gateway',
        'callback' => array( 'billplz_buycred_gateway' ),
        'external' => true
    );

    return $installed;
}

add_action('mycred_buycred_load_gateways', 'billplz_load_class_mycred');

function billplz_load_class_mycred()
{
    require __DIR__ .'/includes/main_class.php';
}

/*
 * Delete Bills before deleting pending payment
 */
add_action('before_delete_post', array('billplz_buycred_gateway', 'delete_bill'), 10, 1);
//add_action('wp_trash_post', array('billplz_buycred_gateway', 'delete_bill'));
