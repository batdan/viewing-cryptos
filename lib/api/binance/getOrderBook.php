<?php
namespace cryptos\api\binance;

/**
 * Appel de l'API Binance pour récupérer un orderBook
 *
 * API documentation :
 * https://github.com/binance-exchange/binance-official-api-docs/blob/master/rest-api.md
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
        $url = 'https://api.binance.com/api/v1/depth?symbol=___market___&limit=1000';
        $url = str_replace('___market___',  $marketName, $url);

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_object($res)) {
            return false;
        }

        return $res;
    }
}
