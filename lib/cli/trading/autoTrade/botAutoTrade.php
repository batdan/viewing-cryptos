<?php
namespace cryptos\cli\trading\autoTrade;

use core\cliColorText;
use core\dbSingleton;
use core\config;

/**
 * Bot de trading traitant des ordres de vente et d'achat sur la plateforme Binance
 * en répondant à des alertes emails Tradingview
 *
 * Module Bot-Telegram :
 * https://img.shields.io/packagist/v/telegram-bot/api.svg?style=flat-square)](https://packagist.org/packages/telegram-bot/api
 *
 * @author Daniel Gomes
 */
class botAutoTrade
{
    /**
	 * Attributs
	 */
    private $_nameBDD;                              // Nom de la base de données du bot
    private $_dbh;                                  // Instance PDO

    private $_colorCli;                             // Coloration syntaxique
    private $_wait = 1;                             // Temps en secondes entre chaque boucle
    private $_validTimeAlert = 3;                   // Temps en minutes durant lequel une alerte est valide

    private $_ticker;                               // Prix actuel du market

    private $_capitalCryptoRef;                     // Capital disponible dans la monnaie de référence
    private $_quantityCryptoTde;                    // Quantité achetée dans la monnaie tradée

    private $_apiBinance;                           // Instance de l'API Binance
    private $_stepSize;                             // Récupération du nombre de décimales pour le market


    /**
	 * Constructeur
	 */
	public function __construct()
	{
        // Gestion des couleurs en interface CLI
        $this->_colorCli = new cliColorText();

        // Récupération de la configuration
        $this->getConf();

        // Instance de l'API Telegram
        // $this->BotApiTelegram();
        // $this->_botTelegram = new BotApi($this->_telegram_token);
	}


    /**
     * Récupération de la configuration
     */
    private function getConf()
    {
        // Connexion à la base de données
		$confBot = config::getConfig('botAutoTrade');

        $this->_nameBDD = $confBot['nameBDD'];
    }


    /**
     * Démarrage de la boucle de traitement des alertes
     */
    public function run()
    {
        // Boucle infinie
        for ($i=0; $i==$i; $i++) {
            $this->actions();
            sleep($this->_wait);
        }
    }


    /**
     * Actions d'une boucle
     */
    public function actions()
    {
        try {
            // Instance PDO : open
            $this->_dbh = dbSingleton::getInstance($this->_nameBDD);

            // Traitement des alertes
            $checkAlert = $this->checkAlert();

            // Instance PDO : close
            $this->_dbh = dbSingleton::closeInstance($this->_nameBDD);

        } catch (\Exception $e) {

            echo chr(10);
            echo $this->_colorCli->getColor('['.date('Y-m-d H:i:s') . ']',  'light_gray');
            echo $this->_colorCli->getColor('[ERROR] ' . $e->getMessage(), 'light_red');
            echo chr(10) . chr(10);
        }
    }


    /**
     * Traitement des alertes
     */
    private function checkAlert()
    {
        // Récupération des alertes en statut "wait"
        $req = "SELECT          a.date_email, a.json,
                                a.id as alertId,
                                u.id as userId,
                                u.nom, u.prenom, u.email,
                                u.telegram_id, u.telegram_token,
                                u.connect_binance_apiKey,
                                u.connect_binance_apiSecret,
                                u.activ_alert_telegram,
                                u.activ_alert_email

                FROM            tradingview_alert   a

                INNER JOIN      users               u
                ON              a.userId = u.id

                WHERE           a.status = :status
                AND             u.activ = 1";

        $sql = $this->_dbh->prepare($req);
        $sql->execute(array(':status' => 'wait'));
        $alerts = $sql->fetchAll();

        // Requête préparée de mise à jour du statut
        $reqMaj =  "UPDATE      tradingview_alert
                    SET         status  = :status,
                                message = :message
                    WHERE       id      = :id";
        $sqlMaj = $this->_dbh->prepare($reqMaj);

        // Début de la boucle de traitement des transactions
        foreach ($alerts as $alert) {

            // Traitement de l'alerte
            $alertReader = $this->alertReader($alert->alertId, $alert->date_email, $alert->json);

            // Gestion des alertes télégram
            if ($alert->activ_alert_telegram == 1) {
                $this->msgTelegram($alertReader);
            }

            // Gestion des alertes télégram
            if ($alert->activ_alert_email == 1) {
                $this->msgEmail($alertReader);
            }

            // Mise à jour du statut de la transaction
            if (is_array($alertReader)) {
                $sqlMaj->execute(array(
                    ':status'   => $alertReader['status'],
                    ':message'  => $alertReader['message'],
                    ':id'       => $alert->alertId,
                ));
            }
        }
    }


    /**
     * Traitement d'une alerte
     *
     * @param  integer      $userId         Id de l'utilisateur
     * @param  string       $dateEmail      Date de l'envoi du mail
     * @param  string       $json           Flux JSON de l'alerte
     * @return array
     */
    private function alertReader($userId, $dateEmail, $json)
    {
        // Vérification de la fraicheur de l'alerte avant de la lancer l'ordre
        if ($this->checkDatetime($dateEmail) === false) {
            return array(
                'status'    => 'error',
                'message'   => 'Too much time since the alert',
            );
        }

        // Traitement des alertes
        $alertEngine = alertEngine::exec($userId, $json);
    }


    /**
     * Vérification de la fraicheur de l'alerte avant de la lancer l'ordre
     * (durée de vie d'une alerte : 3 minutes)
     *
     * @param  string       $dateEmail      Date de l'envoi du mail
     * @return boolean
     */
    private function checkDatetime($dateEmail)
    {
        $res = true;

        $d1 = new \DateTime($dateEmail);
        $s1 = $d1->getTimestamp();

        $gmdate = gmdate('Y-m-d H:i:s');
        $d2 = new \DateTime($gmdate);
        $s2 = $d2->getTimestamp();

        $diff = floor(($s2-$s1) / 60);

        // Si l'alerte est trop ancienne, elle n'est pas exécutée
        if ($diff >= $this->_validTimeAlert) {
            $res = false;
        }

        return $res;
    }


    /**
     * Ouverture ou fermeture d'un trade
     */
    private function newTrade($action)
    {
        // Option pour récupérer le prix moyen d'achat ou de vente et les frais d'un ordre passé
        $orderOpt = array('newOrderRespType' => 'FULL');

        // Ordre d'achat
        if ($action == 'BUY') {

            $buy = $this->buy($orderOpt);

            $order   = $buy['order'];
            $message = $buy['message'];
        }

        // Ordre de vente
        if ($action == 'SELL') {

            $sell = $this->sell($orderOpt);

            $order   = $sell['order'];
            $message = $sell['message'];
        }

        // Requête REST : l'ordre retourne une erreur
        if (isset($order['code'])) {

            $message  = '___ Trade order ERROR ___' . chr(10) . chr(10);
            $message .= 'Side : '    . $action          . chr(10);
            $message .= 'Code : '    . $order['code']   . chr(10);
            $message .= 'Message : ' . $order['msg']    . chr(10);

            if ($action == 'BUY') {
                $message .= 'buyStrat : '  . $this->_buyStrat  . chr(10) . chr(10);
            }
            if ($action == 'SELL') {
                $message .= 'sellStrat : ' . $this->_sellStrat . chr(10) . chr(10);
            }


            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);

        // Enregistrement de l'ordre
        } else {

            // Calcul du prix moyen et des frais
            $sumPrice = 0;
            $sumQty   = 0;
            $sumFees  = 0;

            foreach($order['fills'] as $val) {
                $sumPrice += $val['price'] * $val['qty'];
                $sumQty   += $val['qty'];
                $sumFees  += $val['commission'];
                $asset     = $val['commissionAsset'];
            }

            $order['price'] = $sumPrice / $sumQty;

            // Gestion du prix pour un market virtuel
            if (isset($order['virtualMarket']) && $order['virtualMarket'] == 1) {
                if ($order['side'] == 'BUY') {
                    $order['price'] = $order['price'] * $order['stepBtc_price'];
                } else {
                    $order['price'] = $order['price'] * $order['stepAlt_price'];
                }
            }

            $order['commission']        = $sumFees;
            $order['commissionAsset']   = $asset;

            // Ajout du prix d'achat au message de log
            $message .= 'Price : ' . $order['price'] . chr(10);

            // Calcul du gain et ajout au message de log
            $order['gain'] = 0;
            if ($action == 'SELL') {
                $order['gain'] = ((100 / $this->_buyPrice) * $order['price']) - 100;
                $message .= 'Gain : ' . number_format($order['gain'], 2, '.', '') . '%' . chr(10) . chr(10);
            } else {
                $message .= chr(10);
            }

            // Message Log & Telegram
            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);

            // Sauvegarde de l'ordre en base de donnée
            $this->saveOrderBDD($order);
            $this->_timestampLastTrade = time();

            /**
             * Clean des Variables à la fin d'un trade
             */
            if ($action == 'BUY') {
                $this->_buyPrice = $order['price'];
            } else  {
                $this->_topPrice    = 0;
                $this->_buyPrice    = null;

                $this->_stopLossDyn = null;
                $this->_stopLossDynTop          = null;
                $this->_stopLossTimerInactivity = 0;
            }
        }
    }


    /**
     * Calcul de la quantité à acheter en fonction de la configuration (PCT/Price) dans viewing
     */
    private function buyQuantity($marketTable=null)
    {
        if (is_null($marketTable)) {
            $marketTable  = $this->_marketTable;
            $ticker       = $this->_ticker;
        } else {
            $ticker       = \cryptos\trading\tickerData::ticker($this->_nameExBDD, $marketTable);
        }

        if ($this->_buyOption == 'pct') {

            // Achat du pourcentage définit dans Viewing
            $this->capitalCryptoRef($marketTable);

            $quantity = $this->_capitalCryptoRef / $ticker;
            $quantity = ($quantity / 100) * $this->_buyWithPct;

        } else {

            // Si la monnaie de référence n'est pas USDT, il est nécessaire de convertir la mise dans la bonne monnaie de référence
            $expMarket = explode('_', $marketTable);

            if ($expMarket[1] == 'usdt') {
                $buyWithPrice = $this->_buyWithPrice;
            } else {
                $tickerUsdtCoin = \cryptos\trading\tickerData::ticker($this->_nameExBDD, 'market_usdt_' . $expMarket[1]);
                $buyWithPrice   = $this->_buyWithPrice / $tickerUsdtCoin;
            }

            // Achat au montant définit dans Viewing
            $quantity = $buyWithPrice / $ticker;
        }

        return array(
            'ticker'    => $ticker,
            'quantity'  => $quantity,
        );
    }


    /**
     * Achat au market
     */
    private function buy($orderOpt)
    {
        // Calcul de la quantité à acheter en fonction de la configuration (PCT/Price) dans viewing
        $quantity = $this->buyQuantity();

        // Arrondi au nombre maximum de décimales autorisées pour ce market
        $this->_quantityCryptoTde = round($quantity['quantity'], $this->_stepSize, PHP_ROUND_HALF_DOWN);

        // API REST : Ordre d'achat au market
        $order = $this->_apiBinance->marketBuy($this->_marketName, $this->_quantityCryptoTde, $orderOpt);

        echo chr(10) . chr(10) . ' Trade : BUY -> ' . $this->_quantityCryptoTde . chr(10) . chr(10);

        $message  = '___ Trade order BUY ___'                       . chr(10) . chr(10);
        $message .= 'Market : '    .  $this->_marketName            . chr(10);
        $message .= 'DateTime : '    .  date('Y:m:d H:i:s')         . chr(10);
        $message .= 'QuantityRef : ' . $this->_capitalCryptoRef     . chr(10);
        $message .= 'QuantityTde : ' . $this->_quantityCryptoTde    . chr(10);
        $message .= 'buyStrat : '    . $this->_buyStrat             . chr(10);
        $message .= 'sellStrat : '   . $this->_sellStrat            . chr(10);

        return array(
            'order'     => $order,
            'message'   => $message,
        );
    }


    /**
     * Vente au market
     */
    private function sell($orderOpt)
    {
        // API REST : Ordre de vente au market
        $order = $this->_apiBinance->marketSell($this->_marketName, $this->_quantityCryptoTde, $orderOpt);

        echo chr(10) . chr(10) . ' Trade : SELL -> ' . $this->_quantityCryptoTde . chr(10) . chr(10);

        $message  = '___ Trade order SELL ___'                  . chr(10) . chr(10);
        $message .= 'Market : '    .  $this->_marketName        . chr(10);
        $message .= 'DateTime : '  .  date('Y:m:d H:i:s')       . chr(10);
        $message .= 'Quantity : '  . $this->_quantityCryptoTde  . chr(10);
        $message .= 'sellStrat : ' . $this->_sellStrat          . chr(10);

        return array(
            'order'     => $order,
            'message'   => $message,
        );
    }


    /**
     * Sauvegarde de l'ordre en base
     */
    private function saveOrderBDD($order)
    {
        try {
            $saveOrder = array(
                ':user'             => $this->_user,
                ':symbol'           => $order['symbol'],
                ':orderId'          => $order['orderId'],
                ':clientOrderId'    => $order['clientOrderId'],
                ':transactTime'     => $order['transactTime'],
                ':price'            => $order['price'],
                ':commission'       => $order['commission'],
                ':commissionAsset'  => $order['commissionAsset'],
                ':gain'             => $order['gain'],
                ':origQty'          => $order['origQty'],
                ':executedQty'      => $order['executedQty'],
                ':status'           => $order['status'],
                ':timeInForce'      => $order['timeInForce'],
                ':type'             => $order['type'],
                ':side'             => $order['side'],
                ':buyStrat'         => $this->_buyStrat,
                ':sellStrat'        => $this->_sellStrat,
            );

            $addReqChp = '';
            $addReqVal = '';

            $req = "INSERT INTO bin_ema_spread_trade
                (
                    user, symbol, orderId, clientOrderId, transactTime, price, commission, commissionAsset, gain,
                    origQty, executedQty, status, timeInForce, type, side, buyStrat, sellStrat, date_crea
                    $addReqChp
                )
                VALUES
                (
                    :user, :symbol, :orderId, :clientOrderId, :transactTime, :price, :commission, :commissionAsset, :gain,
                    :origQty, :executedQty, :status, :timeInForce, :type, :side, :buyStrat, :sellStrat, NOW()
                    $addReqVal
                )";

            $sql = $this->_dbh->prepare($req);
            $sql->execute($saveOrder);

        } catch (\Exception $e) {

            $message  = '___ Error BDD : method saveOrderBDD  ___' . chr(10) . chr(10);
            $message .= 'Save Trade ' . $this->_user . '  ' . $order['orderId'] . ' : ' . $order['side'] . chr(10);

            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);
            $message .= $e . chr(10) . chr(10);

            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);
        }
    }


    /**
     * Récupération du marketName
     */
    private function marketName($marketTable=null)
    {
        if (is_null($marketTable)) {

            $expMarket  = explode('_', $this->_marketTable);
            $marketName = $expMarket[2] . $expMarket[1];

            $this->_marketName = strtoupper($marketName);

        } else {

            $expMarket  = explode('_', $marketTable);
            $marketName = $expMarket[2] . $expMarket[1];

            return strtoupper($marketName);
        }
    }


    /**
     * Récupération du capital de la crypto de référence
     */
    private function capitalCryptoRef($marketTable=null)
    {
        if (is_null($marketTable)) {
            $marketTable = $this->_marketTable;
        }

        $expMarket = explode('_', $marketTable);
        $cryptoRef = strtoupper($expMarket[1]);

        $balances = $this->_apiBinance->balances();

        if (isset($balances['code'])) {

            $message  = '___ Method capitalCryptoRef : ERROR ___' . chr(10) . chr(10);
            $message .= 'Code : '        . $order['code'] . chr(10);
            $message .= 'Message : '     . $order['msg'] . chr(10);
            $message .= 'marketTable : ' . $marketTable . chr(10) . chr(10);

            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);

        } else {

            $message  = '___ Method capitalCryptoRef : Good ___' . chr(10) . chr(10);
            $message .= 'CryptoRef : '   . $cryptoRef. chr(10);
            $message .= 'Balance : '     . $balances[$cryptoRef]['available'] . chr(10) . chr(10);

            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);

            $this->_capitalCryptoRef = $balances[$cryptoRef]['available'];
        }
    }


    /**
     * Récupération du capital de la crypto tradée
     */
    private function capitalCryptoTde($marketTable=null)
    {
        if (is_null($marketTable)) {
            $marketTable = $this->_marketTable;
        }

        $expMarket = explode('_', $marketTable);
        $cryptoTde = strtoupper($expMarket[2]);

        $balances = $this->_apiBinance->balances();

        if (isset($balances['code'])) {

            $message  = '___ Method capitalCryptoTde ERROR ___' . chr(10) . chr(10);
            $message .= 'Code : '        . $order['code'] . chr(10);
            $message .= 'Message : '     . $order['msg'] . chr(10);
            $message .= 'marketTable : ' . $order['msg'] . chr(10) . chr(10);

            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);

        } else {

            return $balances[$cryptoTde]['available'];
        }
    }


    /**
     * Vérification du capital d'une crypto
     */
    private function balanceCrypto($crypto)
    {
        $balances = $this->_apiBinance->balances();

        if (isset($balances['code'])) {

            $message  = '___ Method balanceCrypto ERROR : ' . $crypto . ' ___' . chr(10) . chr(10);
            $message .= 'Code : '    . $order['code'] . chr(10);
            $message .= 'Message : ' . $order['msg']  . chr(10) . chr(10);

            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);

        } else {

            $crypto = strtoupper($crypto);
            return $balances[$crypto]['available'];
        }
    }


    /**
     * Récupération des ordres d'achat ou de vente
     */
    private function checkTradingview()
    {
        // Récupération des ordres d'achat ou de vente (durée de vie d'une alerte : 3 minutes)
        $gmdate = gmdate('Y-m-d H:i:s');

        $req = "SELECT      *
                FROM        tradingview_alert
                WHERE       date_email  > DATE_ADD('$gmdate', INTERVAL -3 MINUTE)
                ORDER BY    id DESC LIMIT 1";

        $sql = $this->_dbh->query($req);

        if ($sql->rowCount() > 0) {

            while ($res = $sql->fetch()) {


            }
        }

        // Pas de trade en cours, on vérifie s'il y a un signal d'achat
        if ($this->_status == 'wait' && count($confBuy) > 0) {

            foreach ($confBuy as $strat => $conf) {

                if ($conf == 'on' || ($conf == 'bullish' && $this->_changeTrend == 'bullish')) {

                    $sql->execute(array(
                        ':market'   => $this->_marketName,
                        ':strat'    => $strat,
                        ':side'     => '%buy%'
                    ));

                    if ($sql->rowCount() > 0) {

                        $res = $sql->fetch();

                        // Le nom de profil du Bot (Viewing) doit être celui du profil de l'alerte
                        if ($this->_user == $res->profil) {
                            $this->_buyStrat  = $strat . 'Buy';
                            $this->_sellStrat = $this->_confSellStrat;
                            return 'BUY';
                            break;
                        }
                    }
                }
            }
        }

        // Un trade est lancé, on attend le signal de revente
        if ($this->_status == 'trade' && count($confSell) > 0) {

            foreach ($confSell as $strat => $conf) {

                if ($conf == 'on') {

                    $sql->execute(array(
                        ':market'   => $this->_marketName,
                        ':strat'    => $strat,
                        ':side'     => '%sell%'
                    ));

                    if ($sql->rowCount() > 0) {

                        $res = $sql->fetch();

                        // Le nom de profil du Bot (Viewing) doit contenirle nom du profil de l'alerte
                        if (stristr($this->_user, $res->profil)) {

                            $this->_sellStrat = $strat . 'Sell';
                            return 'SELL';
                            break;
                        }
                    }
                }
            }
        }

        return 'false';
    }
}
