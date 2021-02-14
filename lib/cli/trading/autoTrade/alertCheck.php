<?php
namespace cryptos\cli\trading\autoTrade;

use Binance\API;
use core\config;

/**
 * Type         Défaut      Exemple                         Notes
 * compte       :           : 018abcdefg@cryptoview.io      : dans l'alias d'email
 *
 * utilisateur  :           : usr=pym|leo|dan               : Même email d'alerte pour 3 d'où le besoin de reconnaitre l'utilisateur
 *                                                            Nécessaire avec les emails envoyés depuis l'alias 'U8F446N3mfxxL9sQ3JWbA2jz@cryptoview.io'
 *
 * exchange     :           : exc=binance                   : nom complet de l'exchange
 * marché       :           : mar=XLMUSDT                   : marché ciblé, monnaie de référence à la fin
 * action       :           : act=sell                      : buy|sell : pas de short pour l'instant
 * quantité     : 100%      : qty=0.12                      : en % de la balance ou fixe, dans la monnaie de référence du marché
 * execution    : on        : exe=off                       : optionnel, non exécution des ordres mais enregistrement pour debug/paper trading
 * order type   : market    : typ=limit                     : optionnel, à voir car nécessite de définir le type de limit (fill or kill, etc...)
 * take profit  :           : tkp=50%,2%                    : optionnel, sortir 50% des positions à +2% du prix d'achat
 * stop loss    :           : sls=100%,-1.5%                : optionnel, sortir 100% des positions à -1.5%, par défaut négatif même si positif indiqué
 *
 *  {
 *      "exc":"binance",        Exchange name
 *      "typ":"long",           Type : long|short|limit
 *      "mar":"XRPBTC",         Market symbol
 *      "act":"buy",            Action : buy|sell
 *      "qty":0.12              Quantity (ref)
 *  }
 *
 * @author Pierre-Yves Minier & Daniel Gomes
 */
class alertCheck
{
    /**
     * Flux json de l'alerte email
     * @var json
     */
    private $_json;

    /**
     * Récupération de l'API Binance
     * @var object
     */
    private $_apiBinance;

    /**
     * Liste des exhanges supportés
     * @var array
     */
    private $_exchangeList;


    /**
     * Constructeur
     *
     * @param       json        $json
     */
    public function __construct($json)
    {
        $this->_json = $json;
    }


    /**
     * Validation du flux JSON
     *
     * @return      array|string
     */
    public function jsonValidate()
    {
        // Decode le JSON en array
        $result = json_decode($this->_json, true);

        // Vérifie s'il y a des erreurs
        switch (json_last_error()) {
            case JSON_ERROR_NONE:
                $error = ''; // JSON is valid // No error has occurred
                break;
            case JSON_ERROR_DEPTH:
                $error = 'The maximum stack depth has been exceeded.';
                break;
            case JSON_ERROR_STATE_MISMATCH:
                $error = 'Invalid or malformed JSON.';
                break;
            case JSON_ERROR_CTRL_CHAR:
                $error = 'Control character error, possibly incorrectly encoded.';
                break;
            case JSON_ERROR_SYNTAX:
                $error = 'Syntax error, malformed JSON.';
                break;
            // PHP >= 5.3.3
            case JSON_ERROR_UTF8:
                $error = 'Malformed UTF-8 characters, possibly incorrectly encoded.';
                break;
            // PHP >= 5.5.0
            case JSON_ERROR_RECURSION:
                $error = 'One or more recursive references in the value to be encoded.';
                break;
            // PHP >= 5.5.0
            case JSON_ERROR_INF_OR_NAN:
                $error = 'One or more NAN or INF values in the value to be encoded.';
                break;
            case JSON_ERROR_UNSUPPORTED_TYPE:
                $error = 'A value of a type that cannot be encoded was given.';
                break;
            default:
                $error = 'Unknown JSON error occured.';
                break;
        }

        // Retourne une éventuelle erreur
        if (!empty($error)) {
            return $error;
        }

        // Retourne un tableau si le json est valide
        return $result;
    }


    /**
     * Vérifie le contenu de l'alerte email
     *
     * @param       object          $api       Instance de connexion à l'API
     * @param       true|string
     */
    public function checkFormatJson($api)
    {
        $alert = json_decode($this->_json, true);

        // Récupération de la liste des markets de l'exchange
        $allMarkets = $api->getAllMarkets();

        // Vérification de l'existence market
        if (!isset($alert['mar']) || !in_array(strtoupper($alert['mar']), $allMarkets)) {
            return 'Invalid market';
        }

        // Vérification de l'action demandée
        if (!isset($alert['act']) || !in_array(strtolower($alert['act']), array('buy', 'sell'))) {
            return 'Invalid order type : buy or sell';
        }

        // Vérification de la quantité demandée
        if (!isset($alert['qty']) || !is_numeric($alert['qty'])) {
            return 'Invalid quantity';
        }

        return true;
    }
}
