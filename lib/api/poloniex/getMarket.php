<?php
namespace cryptos\api\poloniex;

/**
 * Récupération des données de l'Exchange Poloniex
 *
 * API Bittrex documentation :
 * https://poloniex.com/support/api/
 *
 * @author Daniel Gomes
 */
class getMarket
{
    /**
     * Récupération des informations sur les crypto-monnaies gérées
     *
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     * @return      object
     */
    public static function getCurrencies($addCurlopt=null)
    {
        $url = "https://poloniex.com/public?command=returnCurrencies";

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_object($res)) {
            return false;
        }

        return $res;
    }


    /**
     * Récupération de l'ensemble des marketNames
     *
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     * @return      object
     */
    public static function getMarketSummaries($addCurlopt=null)
    {
        $url = "https://poloniex.com/public?command=returnTicker";

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
     * @param       integer     $interval           Antériorité des trades à récupérer (exprimé en secondes)
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     * @return      object
     */
    public function getMarketHistory($marketName, $interval=60, $addCurlopt=null)
    {
        // Formatage du marketName
        $marketName = strtoupper($marketName);
        $marketName = str_replace('-', '_', $marketName);

        // Calcul des timestamps de début et fin de plage pour la récupération des trade (timestamp UTC)
        $gmdate = gmdate('Y-m-d H:i:s');
        $d      = new \DateTime();
        $end    = $d->getTimestamp();
        $start  = $end - $interval;

        $url = "https://poloniex.com/public?command=returnTradeHistory&currencyPair=$marketName&start=$start&end=$end";

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res);

        if (! is_array($res)) {
            return false;
        }

        return $res;
    }
}
