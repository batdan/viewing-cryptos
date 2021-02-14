<?php
namespace cryptos\api\gdax;

/**
 * Appel de l'API GDAX pour récupérer un orderBook
 *
 * API documentation :
 * https://docs.gdax.com/v1/docs/public-endpoints
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
        $url = "https://api-public.sandbox.gdax.com/products/$marketName/book?level=3";

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_object($res)) {
            return false;
        }

        return $res;
    }
}
