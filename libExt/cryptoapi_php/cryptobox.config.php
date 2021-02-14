<?php
/**
 *  ... Please MODIFY this file ...
 *
 *  YOUR MYSQL DATABASE DETAILS
 *
 */
if ($_SERVER['HTTP_HOST'] == 'www.cryptoview.io') {
    define("DB_NAME",   "cryptoview_gourl");            // database name - prod
}
if ($_SERVER['HTTP_HOST'] == 'crypto.dpy.ovh') {
    define("DB_NAME",   "cryptoview_dev_gourl");        // database name - preprod
}

define("DB_HOST",       "localhost");                   // hostname
define("DB_USER",       "cryptoview_gourl");            // database username
define("DB_PASSWORD",   "XNg2m8BtWotvzh7wKJgYZeCj");    // database password


/**
 *  ARRAY OF ALL YOUR CRYPTOBOX PRIVATE KEYS
 *  Place values from your gourl.io signup page
 *  array("your_privatekey_for_box1", "your_privatekey_for_box2 (otional), etc...");
 */
$cryptobox_private_keys = array(
    "21733AAnAb6sBitcoin77BTCPRVPuBEVHS8KBUFh9Ioc8FqD6J",   // Private key BTC
    "21847AA1VwhSBitcoincash77BCHPRVOtbtli850gdsawlrqRf",   // Private key BCH
    "21755AAkaNESDash77DASHPRV9ESFkG8ecWmFOZ6nPFTY17tu1",   // Private key DASH
    "21846AAksxXgLitecoin77LTCPRVIvN3ZJEy7fwGIVFUCo5n2m",   // Private key LTC
);

define("CRYPTOBOX_PRIVATE_KEYS", implode("^", $cryptobox_private_keys));
unset($cryptobox_private_keys);
?>
