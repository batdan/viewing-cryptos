<?php
namespace cryptos\cli\trading\autoTrade;

use core\crypt;
use Binance\API as apiBinance;

/**
 * Toutes les actions avec les API des exchanges supportés par le bot
 * 
 * Module PHP API Binance
 * https://github.com/binance-exchange/php-binance-api
 *
 * Documentation officielle API Binance
 * https://github.com/binance-exchange/binance-official-api-docs/blob/master/rest-api.md
 *
 * @author Daniel Gomes
 */
class exchangeAPI
{
    /**
     * Nom de l'exchange appelé
     * @var string
     */
    private $_exchange;

    /**
     * API key
     * @var string
     */
    private $_apiKey;

    /**
     * API secret
     * @var string
     */
    private $_apiSecret;

    /**
     * Instance de l'API
     * @var object
     */
    private $_api;


    /**
     * Constructeur
     * @param   string      $exchange       Nom de l'exchange
     */
    public function __construct($exchange, $apiKey, $apiSecret)
    {
        $this->_exchange    = $exchange;

        // Dechiffre apiKey & apiSecret
        $this->decrypt($apiKey, $apiSecret);

        // Connexion à l'API de l'exchange
        $this->apiConnect();
    }


    /**
     * Déchiffre apiKey & apiSecret
     * @param  string $apiKey
     * @param  string $apiSecret
     */
    private function decrypt($apiKey, $apiSecret)
    {
        $crypt = new crypt();

        $this->_apiKey      = $crypt->decrypt($apiKey);
        $this->_apiSecret   = $crypt->decrypt($apiSecret);
    }


    /**
     * Connexion à l'API de l'exchange
     * @return
     */
    public function apiConnect()
    {
        switch ($this->_exchange)
        {
            case 'binance' :
                try {
                    $this->_api = new apiBinance($this->_apiKey, $this->_apiSecret);
                } catch ( \Exception $e ) {
                    return $e->getMessage();
                }

                break;

                // $markets = array_keys($apiBinance->prices());
                // echo $e->getMessage();
        }
    }


    /**
     * Récupération de la liste des markets d'un exchange
     *
     * @param       string          $exchange   Nom de l'exchange
     * @param       object          $api        Instance de connexion à l'API
     * @return      array
     */
    public function getAllMarkets()
    {
        switch ($this->_exchange)
        {
            case 'binance' :

                try {
                    $markets = array_keys($this->_api->prices());
                } catch ( \Exception $e ) {
                    echo $e->getMessage();
                }

                break;
        }

        return $markets;
    }
}
