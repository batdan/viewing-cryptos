<?php
namespace cryptos\api\gdax;

/**
 * Récupération des données de l'Exchange GDAX
 *
 * API documentation :
 * https://docs.gdax.com
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
        $url = "https://api-public.sandbox.gdax.com/products/";

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_array($res)) {
            return false;

        } else {

            $marketNames = array();

            foreach ($res as $val) {
                $marketNames[$val->id] = $val->quote_currency . '-' . $val->base_currency;
            }

            //asort($marketNames);
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
        $url = "https://api-public.sandbox.gdax.com/products/$marketName/ticker";

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_object($res)) {
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
        $url = "https://api-public.sandbox.gdax.com/products/$marketName/trades";

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_array($res)) {
            return false;
        }

        return $res;
    }
}
