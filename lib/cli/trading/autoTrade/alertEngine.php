<?php
namespace cryptos\cli\trading\autoTrade;

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
class alertEngine
{
    /**
     * Récupération de l'API Binance
     * @var object
     */
    private static $_apiBinance;


    /**
     * Constructeur
     *
     * @param       integer     $userId     Id de l'utilisateur (table:user)
     * @param       json        $json
     */
    public static function exec($userId, $json)
    {
        $alert = json_decode($json, true);

        
    }
}
