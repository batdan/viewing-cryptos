<?php
namespace cryptos\api\bitfinex;

/**
 * Récupération des données de l'Exchange Bitfinex
 *
 * API documentation :
 * https://docs.bitfinex.com/v1/docs/public-endpoints
 *
 * @author Daniel Gomes
 */
class getMarket
{
    /**
     * Récupération des noms des marketNames supportés
     * Tradution des noms des couples au format REF-TDE tout
     * tout en conservant les dénominations d'origines dans les clés du tableau retourné
     *
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     * @return      object
     */
    public static function getSymbols($addCurlopt=null)
    {
        $url = "https://api.bitfinex.com/v1/symbols";

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_array($res)) {
            return false;

        } else {

            $marketNames = array();

            foreach ($res as $val) {
                $marketNames[$val] = substr($val, 3, 3) . '-' . substr($val, 0, 3);
            }

            asort($marketNames);
        }

        return $marketNames;
    }


    /**
     * Récupération d'un market
     *
     * @param       string      $marketName         Places de marché à récupérer ou liste (array)
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     * @return      object
     */
     public static function getMarketSummary($marketName, $addCurlopt=null)
    {
        $url = "https://api.bitfinex.com/v1/pubticker/" . $marketName;

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_object($res) || isset($res->error)) {
            return false;
        }

        return $res;
    }


    /**
     * Récupération de tous les markets
     *
     * @param       string      $marketName         Places de marché à récupérer ou liste (array)
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     * @return      object
     */
     public static function getMarketSummaries($addCurlopt=null)
    {
        $symbols = self::getSymbols($addCurlopt);

        $getSymbols = array();
        foreach ($symbols as $k=>$v) {
            $getSymbols[] = 't' . strtoupper($k);
        }
        $getSymbols = implode(',', $getSymbols);

        $url = "https://api.bitfinex.com/v2/tickers?symbols=" . $getSymbols;

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_array($res)) {
            return false;
        }

        return $res;
    }


    /**
     * Récupération de l'historique des trades d'un market
     *
     * @param       string      $marketName         Places de marché à récupérer ou liste (array)
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     * @return      object
     */
    public function getMarketHistory($marketName, $addCurlopt=null)
    {
        $url = "https://api.bitfinex.com/v1/trades/" . $marketName;

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_array($res)) {
            return false;
        }

        return $res;
    }
}
