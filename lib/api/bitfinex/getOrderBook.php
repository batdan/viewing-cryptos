<?php
namespace cryptos\api\bitfinex;

/**
 * Appel de l'API Bitfinex pour récupérer un orderBook
 *
 * API documentation :
 * https://docs.bitfinex.com/v1/docs/public-endpoints
 *
 * @author Daniel Gomes
 */
class getOrderBook
{
    /**
     * Récupération de l'order book d'une crypto-monnaie
     *
     * @param       string      $marketName         Nom du market - ex : BTC-LTC
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     *
     * @return      object
     *
     */
    public static function getOrderBook($marketName, $addCurlopt=null)
    {
        $url = 'https://api.bitfinex.com/v1/book/' . $marketName . '?limit_bids=10000&limit_asks=10000';

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_object($res)) {
            return false;
        }

        return $res;
    }
}
