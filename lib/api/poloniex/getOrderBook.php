<?php
namespace cryptos\api\poloniex;

/**
 * Appel de l'API Poloniex pour récupérer un orderBook
 *
 * API Bittrex documentation :
 * https://poloniex.com/support/api/
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
        // Formatage du marketName
        $marketName = strtoupper($marketName);
        $marketName = str_replace('-', '_', $marketName);

        $url = 'https://poloniex.com/public?command=returnOrderBook&depth=10000&currencyPair=' . $marketName;

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_object($res)) {
            return false;
        }

        return $res;
    }
}
