<?php
namespace cryptos\api\binance;

/**
 * Récupération des données de l'Exchange Binance
 *
 * API documentation :
 * https://github.com/binance-exchange/binance-official-api-docs/blob/master/rest-api.md
 *
 * @author Daniel Gomes
 */
class getMarket
{
	/**
	 * Attributs
	 */
    /*
    private $_urlGetMarket          = 'https://bittrex.com/api/v1.1/public/getmarkets';                                 // Récupération de toutes les places de marché
    private $_urlGetCurrencies      = 'https://bittrex.com/api/v1.1/public/getcurrencies';                              // Récupération des information sur les crypto-monnaies gérées
    private $_urlGetTicker			= 'https://bittrex.com/api/v1.1/public/getticker?market=';							// Récupération des 'Last', 'Bid' et 'Ask' d'un market
    private $_urlGetMarketSummaries = 'https://bittrex.com/api/v1.1/public/getmarketsummaries';                         // Récupération du cours de toutes les monnaies
    private $_urlGetMarketSummary   = 'https://bittrex.com/api/v1.1/public/getmarketsummary?market=';                   // Récupération du cours d'une monnaie
    private $_urlGetMarketHistory   = 'https://bittrex.com/api/v1.1/public/getmarkethistory?market=';                   // Récupération du cours d'une monnaie
    */

	/**
	 * Constructeur
	 */
	public function __construct()
	{
	}


    /**
     * Récupération de toutes les places de marché
     *
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     *
     * @return      array
     */
    // public function getMarkets($addCurlopt=null)
    // {
    //     $res = \core\curl::curlGet($this->_urlGetMarket, $addCurlopt);
    //     $res = json_decode($res);
    //
    //     if (! is_object($res) || $res->success !== true) {
    //         return false;
    //     }
    //
    //     return $res->result;
    // }


    /**
     * Récupération des informations sur les crypto-monnaies gérées
     *
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     *
     * @return      array
     */
    // public function getCurrencies($addCurlopt=null)
    // {
    //     $res = \core\curl::curlGet($this->_urlGetCurrencies, $addCurlopt);
    //     $res = json_decode($res);
    //
    //     if (! is_object($res) || $res->success !== true) {
    //         return false;
    //     }
    //
    //     return $res->result;
    // }


    /**
     * Récupération des 'Last', 'Bid' et 'Ask' d'un market
     *
     * @param       string      $marketName          Places de marché à récupérer ou liste (array)
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     *
     * @return      array
     */
    // public function getTicker($marketName, $addCurlopt=null)
    // {
    //     $res = \core\curl::curlGet($this->_urlGetTicker . $marketName, $addCurlopt);
    //     $res = json_decode($res);
    //
    //     if (! is_object($res) || $res->success !== true) {
    //         return false;
    //     }
    //
    //     return $res->result;
    // }


    /**
     * Récupération de l'ensemble des marketNames
     *
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     *
     * @return      array
     */
    // public function getMarketSummaries($addCurlopt=null)
    // {
    //     $res = \core\curl::curlGet($this->_urlGetMarketSummaries, $addCurlopt);
    //     $res = json_decode($res);
    //
    //     if (! is_object($res) || $res->success !== true) {
    //         return false;
    //     }
    //
    //     return $res->result;
    // }


    /**
     * Récupération de l'ensemble d'un ou plusieurs marketNames
     *
     * @param       mixed       $marketNames         Places de marché à récupérer ou liste (array)
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     *
     * @return      array
     */
    // public function getMarketSummary($marketNames, $addCurlopt=null)
    // {
    //     $res = array();
    //
    //     if (is_array($marketNames)) {
    //
    //         foreach($marketNames as $marketName) {
    //
    //             $resMarket = \core\curl::curlGet($this->_urlGetMarketSummary . $marketName, $addCurlopt);
    //             $resMarket = json_decode($resMarket);
    //
    //             if ($resMarket->success === true) {
    //                 $res[] = $resMarket->result;
    //             }
    //         }
    //
    //     } else {
    //
    //         $resMarket = \core\curl::curlGet($this->_urlGetMarketSummary . $marketNames, $addCurlopt);
    //         $resMarket = json_decode($resMarket);
    //
    //         if ($resMarket->success === true) {
    //             $res[] = $resMarket->result[0];
    //         }
    //     }
    //
    //     if (count($res) == 0) {
    //         return false;
    //     }
    //
    //     return $res;
    // }


    /**
     * Récupération de l'historique des trades d'un market
     *
     * @param       mixed       $marketName          Places de marché à récupérer ou liste (array)
     * @param       array       $addCurlopt         Permet de passer des options pour la requête cURL
     *
     * @return      array
     */
    // public function getMarketHistory($marketName, $addCurlopt=null)
    // {
    //     $res = \core\curl::curlGet($this->_urlGetMarketHistory . $marketName, $addCurlopt);
    //     $res = json_decode($res);
    //
    //     if (! is_object($res) || $res->success !== true) {
    //         return false;
    //     }
    //
    //     return $res->result;
    // }
}
