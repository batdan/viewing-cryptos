<?php
namespace cryptos\api\bittrex;

/**
 * Appel de l'API Bittrex pour récupérer un orderBook
 *
 * API documentation :
 * https://bittrex.com/home/api
 *
 * @author Daniel Gomes
 */
class getOrderBook
{
    /**
     * Récupération de l'order book d'une crypto-monnaie
     *
     * @param       string      $marketName         Nom du market - ex : BTC-LTC
     * @param       string      $type               OrderBook souhaité : both|bid|ask
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     *
     * @return      object
     *
     */
    public static function getOrderBook($marketName, $type='both', $addCurlopt=null)
    {
        $url = 'https://bittrex.com/api/v1.1/public/getorderbook?market=___market___&type=___type___';
        $url = str_replace('___market___',  $marketName, $url);
        $url = str_replace('___type___',    $type,       $url);

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_object($res) || $res->success !== true) {
            return false;
        }

        return $res->result;
    }
}
