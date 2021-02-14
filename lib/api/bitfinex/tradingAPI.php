<?php
namespace cryptos\api\bitfinex;

/**
 * Trading sur l'exchange Bitfinex
 *
 * API documentation :
 * https://docs.bitfinex.com/v1/docs/public-endpoints
 *
 * @author Daniel Gomes
 */
class tradingAPI
{
    /**
	 * Attributs
	 */
    private $_dbh;					// Instance PDO

	private $_apiKey;
	private $_apiSecret;
    private $_apiSign;


    /**
	 * Constructeur
	 */
	public function __construct()
	{
		// Instance PDO
        $this->_dbh = \core\dbSingleton::getInstance('cryptos');

        // Information de connexion
        $this->_apiKey      = '5049b1f9ba2549f5b27bd622b5cbc17d';
        $this->_apiSecret   = '89a494f6d9c240479398a2fc4dbfee2f';
	}


    /**
     * Ajoute le CURLOPT_HTTPHEADER dans les options de curl
     * Ici, elle est utilisée pour l'authentification
     *
     * @param       string      $url    URL du webservice à appeler
     */
    private function curlHeader($url)
    {
        $sign   = hash_hmac('sha512', $url, $this->_apiSecret);

        $curlHeader = array(
            CURLOPT_HTTPHEADER => array('apisign:' . $sign)
        );

        return $curlHeader;
    }


    /**
     * Récupération de la balance des portefeuils du compte
     */
    public function getBalances()
    {
        $url = 'https://bittrex.com/api/v1.1/account/getbalances?apikey=' . $this->_apiKey . '&nonce=' . time();

        $curlHeader = $this->curlHeader($url);

        $res = \core\curl::curlGet($url, $curlHeader);
        $res = json_decode($res);

        if (isset($res->success) && $res->success !== true) {
            return false;
        }

        $result = array();

        foreach ($res->result as $k=>$v) {
            if ($v->Balance > 0 || $v->Available > 0 || $v->Pending > 0 ) {
                $result[] = $v;
            }
        }

        return $result;
    }


    /**
     * Récupération de la balance d'un portefeuil en Bitcoin
     */
    public function getBalance($currency)
    {
        $url = 'https://bittrex.com/api/v1.1/account/getbalance?apikey=' . $this->_apiKey . '&currency=' . $currency . '&nonce=' . time();

        $curlHeader = $this->curlHeader($url);

        $res = \core\curl::curlGet($url, $curlHeader);
        $res = json_decode($res);

        if (isset($res->success) && $res->success !== true) {
            return false;
        }

        return $res->result;
    }


    /**
     * Récupération des ordres ouverts d'achats et de ventes
     */
    public function getOpenOrders()
    {
        $url    = 'https://bittrex.com/api/v1.1/market/getopenorders?apikey=' . $this->_apiKey . '&nonce=' . time();

        $curlHeader = $this->curlHeader($url);

        $res = \core\curl::curlGet($url, $curlHeader);
        $res = json_decode($res);

        return $res;
    }


    /**
     * Récupération de l'historique des ordres d'achats et de ventes
     */
    public function getOrderHistory()
    {
        $url    = 'https://bittrex.com/api/v1.1/account/getorderhistory?apikey=' . $this->_apiKey . '&nonce=' . time();

        $curlHeader = $this->curlHeader($url);

        $res = \core\curl::curlGet($url, $curlHeader);
        $res = json_decode($res);

        return $res;
    }


    /**
     * Récupération des informations d'un ordre passé
     *
     * @param       string      $uuid           Identifiant unique de la transaction
     *
     * @return      mixed
     */
    public function getOrderHistoryInfos($uuid)
    {
        $url = 'https://bittrex.com/api/v1.1/account/getorder?apikey=' . $this->_apiKey . '&nonce=' . time() . '&uuid=' . $uuid;

        $curlHeader = $this->curlHeader($url);

        $res = \core\curl::curlGet($url, $curlHeader);
        $res = json_decode($res);

        if (isset($res->success) && $res->success !== true) {
            return false;
        }

        return $res->result;
    }


    /**
     * Passer un ordre d'achats
     *
     * @param       string      $marketName         Nom de la place de marché - ex : BTC-ETH
     * @param       number      $quantity           Montant à acheter : exprimé dans la monnaie de référence
     * @param       number      $rate               Valeur de la monnaie tradée au moment de l'achat
     */
    public function buyLimit($marketName, $quantity, $rate)
    {
        $url = 'https://bittrex.com/api/v1.1/market/buylimit?apikey=' . $this->_apiKey . '&nonce=' . time() . '&market=' . $marketName . '&quantity=' . $quantity . '&rate=' . $rate;

        $curlHeader = $this->curlHeader($url);

        $res = \core\curl::curlGet($url, $curlHeader);
        $res = json_decode($res);

        return $res;
    }


    /**
     * Passer un ordre d'achats
     *
     * @param       string      $marketName         Nom de la place de marché - ex : BTC-ETH
     * @param       number      $quantity           Montant à vendre : exprimé dans la monnaie de référence
     * @param       number      $rate               Valeur de la monnaie tradée au moment de la vente
     */
    public function sellLimit($marketName, $quantity, $rate)
    {
        $url = 'https://bittrex.com/api/v1.1/market/selllimit?apikey=' . $this->_apiKey . '&nonce=' . time() . '&market=' . $marketName . '&quantity=' . $quantity . '&rate=' . $rate;

        $curlHeader = $this->curlHeader($url);

        $res = \core\curl::curlGet($url, $curlHeader);
        $res = json_decode($res);

        return $res;
    }


    /**
     * Annulation d'un ordre d'achat ou de vente
     *
     * @param       string      $orderUuid      UUID de l'ordre à annuler (présent dans les informations de la méthode "getOpenOrders()")
     */
    public function cancelOrder($orderUuid)
    {
        $url = 'https://bittrex.com/api/v1.1/market/cancel?apikey=' . $this->_apiKey . '&nonce=' . time() . '&uuid=' . $orderUuid;

        $curlHeader = $this->curlHeader($url);

        $res = \core\curl::curlGet($url, $curlHeader);
        $res = json_decode($res);

        return $res;
    }
}
