<?php
namespace cryptos\cli\trading\binance;

/**
 * Bot de trading utilisant la méthode EMASpread sur la plateforme Binance
 *
 * Module PHP API Binance
 * https://github.com/binance-exchange/php-binance-api
 *
 * Documentation officielle API Binance
 * https://github.com/binance-exchange/binance-official-api-docs/blob/master/rest-api.md
 *
 * Module Bot-Telegram :
 * https://img.shields.io/packagist/v/telegram-bot/api.svg?style=flat-square)](https://packagist.org/packages/telegram-bot/api
 *
 * @author Daniel Gomes
 */
class botEmaSpread
{
    /**
	 * Attributs
	 */
    private $_purge = true;                         // Boolean - Permet de terminer les trades en cours sans en lancer de nouveaux

    private $_idProfil;                             // Id du profil
    private $_user;                                 // Utilisateur du Bot

    private $_dbh;                                  // Instance PDO
    private $_dbhEx;                                // Instance PDO - collecte de l'exchange

    private $_nameBDD   = 'cryptos_bots';           // Nom de la base de données du bot
    private $_nameExBDD = 'cryptos_ex_binance';     // Nom de la base de données de l'exchange

    private $_marketTable;                          // Nom de la table du market
    private $_marketName;                           // Nom du marketName

    private $_manualTrade;                          // Permet de réaliser un achat ou une vente en manuel depuis Viewing

    private $_confBuyStrat;                         // manual|auto : Choix de la stratégie d'achat dans Viewing
    private $_confSellStrat;                        // none|emaFast|emaCross : Choix de la stratégie de vente dans Viewing

    // Achat Tradingview
    private $_confBuyTradingview1;                  // Stratégie d'achat basée  sur un script Tradingview : Ver 1
    private $_confBuyTradingview2;                  // Stratégie d'achat basée  sur un script Tradingview : Ver 2
    private $_confBuyTradingview3;                  // Stratégie d'achat basée  sur un script Tradingview : Ver 3
    private $_confBuyTradingview4;                  // Stratégie d'achat basée  sur un script Tradingview : Ver 4
    private $_confBuyTradingview5;                  // Stratégie d'achat basée  sur un script Tradingview : Ver 5

    // Vente Tradingview
    private $_confSellTradingview1;                 // Stratégie de vente basée sur un script Tradingview : Ver 1
    private $_confSellTradingview2;                 // Stratégie de vente basée sur un script Tradingview : Ver 2
    private $_confSellTradingview3;                 // Stratégie de vente basée sur un script Tradingview : Ver 3
    private $_confSellTradingview4;                 // Stratégie de vente basée sur un script Tradingview : Ver 4
    private $_confSellTradingview5;                 // Stratégie de vente basée sur un script Tradingview : Ver 5

    private $_commentsTradingview;                  // Commentaires sur les stratégies utilisées

    private $_buyOption;                            // Choix achat au prix ou au poucentage
    private $_buyWithPct;                           // Achat : Pourcentage engagé
    private $_buyWithPrice;                         // Achat : Montant engagé

    private $_logFileName;                          // Nom du fichier de log

    private $_interval;                             // Interval de temps du graphique (utilisé pour ema, bearish|bullish et deltaSecond)
    private $_unit;                                 // Unité de l'interval de temps   (utilisé pour ema, bearish|bullish et deltaSecond)
    private $_deltaSecond;                          // Durée en secondes d'une bougie

    private $_emaAgressivBuyOnOff;                  // Activation des stratégie EMA agressives
    private $_emaCrossBuyOnOff;                     // Activation des stratégie EMA Cross
    private $_emaSpreadBuyOnOff;                    // Activation des stratégie EMA Spread

    private $_emaSlowCandles;                       // Nombre de cycles de la Moyenne mobile exponentielle longue
    private $_emaMedCandles;                        // Nombre de cycles de la Moyenne mobile exponentielle moyenne
    private $_emaFastCandles;                       // Nombre de cycles de la Moyenne mobile exponentielle rapide

    private $_emaSlow;                              // Dataset Ema Slow des X derniers cycles
    private $_emaMed;                               // Dataset Ema Med  des X derniers cycles
    private $_emaFast;                              // Dataset Ema Fast des X derniers cycles

    private $_emaSpreadTrigger;                     // Ecart attendu en PCT entre EMA Slow et EMA Fast
    private $_emaSpreadTriggerMax;                  // Pas d'achat au delà de cette valeur d'emaSpread

    private $_volBuyOnOff;                          // Activation des achats avec la surveillance 'Trade History' & 'Order Book'

    private $_stopLossType;                         // dynamic|static : Stop Loss Dynamique ou Statique
    private $_stopLoss;                             // Stop loss de démarrage
    private $_stopLossDyn;                          // Stop loss évoluant vers une stratégie de gain en suivant les prix max du trade
    private $_stopLossDynCoeff;                     // Le Coefficient de Stop Loss dynamique permet de créer un canal de volatilité fermant de 0 à 2 %
    private $_stopLossDynCoeff2;                    // Le Coefficient de Stop Loss dynamique permet de créer un canal de volatilité ouvrant au dessus de 2%

    private $_stopLossTimer;                        // Activation de la remonté du Stop Loss avec un temps d'inactivité
    private $_stopLossTimerInactivity;              // Compteur d'inactivité du Stop Loss Timer
    private $_stopLossTimerWait;                    // Temps entre chaque remontée
    private $_stopLossTimerStep;                    // % de remontée
    private $_stopLossDynTop;                       // Pourcentage de remontée max du Stop Loss Dynamique pendant un trade

    private $_Alltickers;                           // Tableau des clôtures des bougies
    private $_ticker;                               // Prix actuel du market
    private $_buyPrice;                             // Prix d'achat
    private $_topPrice;                             // Prix maximum atteint pendant le trade

    private $_buyStrat;                             // Stratégie d'achat
    private $_sellStrat;                            // Stratégie de vente

    private $_timeInit;                             // Permet de stocker le démarrage d'un tour pour en calculer le temps
    private $_timeEnd;                              // Permet de stocker le démarrage d'un tour pour en calculer le temps

    private $_status = 'wait';                      // Action en cours : wait | trade
    private $_timestampLastTrade;                   // Date et heure de la dernière ouverture ou fermeture de position

    private $_capitalCryptoRef;                     // Capital disponible dans la monnaie de référence
    private $_quantityCryptoTde;                    // Quantité achetée dans la monnaie tradée
    private $_btcNotSent;                           // Market virtuel : partie BTC qui ne pourra être envoyée en altcoin (somme à connaître pour le retour BTC > USDT)

    private $_apiBinance;                           // Instance de l'API Binance
    private $_stepSize;                             // Récupération du nombre de décimales pour le market

    private $_activBot;                             // Activation du bot
    private $_activBotChange;                       // Permet de savoir s'il y a eu un message "Bot inactif !"

    private $_changeConfDate;                       // Date de la dernière configuration
    private $_changeConfMarket;                     // Permet de détecter un changement de Market
    private $_changeTrend;                          // Changement de tendance

    private $_checkBDDStatusTable;                  // Permet de n'envoyer le status du market seulement aux changements

    private $_telegram_activ;                       // Activation de Telegram
    private $_telegram_id;                          // ChatId Telegram
    private $_telegram_token;                       // API Token Telegram
    private $_botTelegram;                          // Instance de l'API Telegram

    private $_rsi;                                  // Stockage de la valeur RSI actuelle

    private $_rsiBuyOnOff;                          // Activation de l'achat par RSI
    private $_rsiSellOnOff;                         // Activation de la vente par RSI

    private $_rsiInterval;                          // RSI : intervalle de temps
    private $_rsiUnit;                              // RSI : Unité de temps

    private $_rsiMaxSellWatch;                      // RSI maximum au delà duquel on surveille pour vendre si plat ou baisse
    private $_rsiMinBuyWatch;                       // RSI minimum en dessous du quel on surveille pour acheter si plat ou hausse

    private $_rsiTop;                               // Enregistrement de la valeur la plus haute au dessus de la zone 'watch' d'achat
    private $_rsiBottom;                            // Enregistrement de la valeur la plus basse au dessus de la zone 'watch' de vente

    private $_rsiDiffBuy;                           // Déclencheur d'achat en pourcent dans la zone 'watch'
    private $_rsiDiffSell;                          // Déclencheur de vente en pourcent dans la zone 'watch'

    private $_rsiMaxBuyLimit;                       // RSI maximum au delà duquel on interdit d'acheter
    private $_rsiMinSellLimit;                      // RSI minimum en dessous duquel on interdit de vendre

    private $_stochRsiBuyOnOff;                     // Activation de l'achat par Stoch RSI

    private $_stochRsiIntFast;                      // Stoch RSI : intervalle de temps court
    private $_stochRsiIntSlow;                      // Stoch RSI : intervalle de temps long
    private $_stochRsiUnit;                         // Stoch RSI : Unité de temps

    private $_logAff = 'light';                     // full|light :Utilisation du bot Tradingview -> affichage des logs lights suffisants


    /**
	 * Constructeur
	 */
	public function __construct($user)
	{
        $this->_user = strtolower($user);

        // Instances PDO
        $this->_dbh = \core\dbSingleton::getInstance($this->_nameBDD);

        // Instances PDO Exchange
        $this->_dbhEx = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Vérification de l'existence d'un profil de log pour cet utilisateur
        $this->checkExistViewLog();

        // Récupération des informations de configuration nécessaires au démarrage
        $getConf = $this->getConf('initBot');

        // Instance de l'API Telegram
        $this->BotApiTelegram();
        $this->_botTelegram = new \TelegramBot\Api\BotApi($this->_telegram_token);

        // Utilisateur désactivé ou inconnu
        if (!empty($getConf)) {
            die( chr(10) . chr(10) . $getConf . chr(10) . chr(10) );
        }

        // Gestion des couleurs en interface CLI
        $this->_colorCli = new \core\cliColorText();

        // Initisalition du suivi RSI
        $this->initRSI();
	}


    /**
     * Démarrage de la boucle
     */
    public function run()
    {
        // Création et déclaration du fichier de log de l'utilisateur
        $this->logFile();

        // Boucle infinie (cycle : 1 sec)
        for ($i=0; $i==$i; $i++) {

            $this->actions();

            // Attente souhaitée : 1 secondes
            $timeExec = ($this->_timeEnd - $this->_timeInit) * 1000000;
            $uSleep = 1000000 - $timeExec;

            if ($uSleep > 0) {
                usleep($uSleep);
            }
        }
    }


    /**
     * Actions d'une boucle
     */
    public function actions()
    {
        $this->_timeInit = microtime(true);

        // Récupération de la configuration
        $getConf = $this->getConf('conf', 1);

        // Bot inactif
        if ($this->_activBot == 0) {

            // Désactivation de l'affichage des logs pour ce user
            $this->stopViewLog();

            // Fin de la boucle
            $this->_timeEnd = microtime(true);

            return;
        }

        // Check BDD status
        $this->checkBDDStatus();

        // Vérification des EMA
        $ema = \cryptos\trading\ema::get3Ema(
            $this->_nameExBDD,                  // Nom de la base de données
            $this->_marketTable,                // Nom de la table
            $this->_unit,                       // Unité de temps
            $this->_interval,                   // Interval de temps
            $this->_emaSlowCandles,             // Nombre de bougies pour le calcul de la Ema Slow
            $this->_emaMedCandles,              // Nombre de bougies pour le calcul de la Ema Med
            $this->_emaFastCandles,             // Nombre de bougies pour le calcul de la Ema Fast
            7,                                  // Nombre de résultats souhaités
            'fixe'                              // Calcul des clôtures sur un tableau 'fixe' ou 'glissant'
        );

        // Tableau des clôtures des bougies
        $this->_Alltickers = $ema['dataSet'];

        $emaSlow        = $ema['ema'][$this->_emaSlowCandles];
        $emaSlowMax     = end($emaSlow);
        $emaSlowAff     = number_format($emaSlowMax, 8, '.', '');
        $this->_emaSlow = $emaSlow;

        $emaMed         = $ema['ema'][$this->_emaMedCandles];
        $emaMedMax      = end($emaMed);
        $emaMedAff      = number_format($emaMedMax, 8, '.', '');
        $this->_emaMed  = $emaMed;

        $emaFast        = $ema['ema'][$this->_emaFastCandles];
        $emaFastMax     = end($emaFast);
        $emaFastAff     = number_format($emaFastMax, 8, '.', '');
        $this->_emaFast = $emaFast;

        // Calcul du Ema Spread
        $emaSpread      = ((100 / $emaSlowMax) * $emaFastMax) - 100;
        $emaSpreadAff   = number_format( $emaSpread, 4, '.', '') . '%';

        // Séprateur
        $sep = $this->_colorCli->getColor( ' | ', 'dark_gray');

        // Affichage en couleur des résultats
        $userAff        = $this->_colorCli->getColor( $this->_user,  'white');
        $date           = $this->_colorCli->getColor( date('H:i:s'), 'light_blue');

        if ($this->_logAff == 'full') {
            $emaSlowAff     = $this->_colorCli->getColor( $emaSlowAff,   'light_green');
            $emaMedAff      = $this->_colorCli->getColor( $emaMedAff,    'yellow');
            $emafastAff     = $this->_colorCli->getColor( $emaFastAff,   'light_red');

            $emaSpreadAff   = $this->_colorCli->getColor( $emaSpreadAff, 'white');

            // Mise dans l'ordre des colonnes Ema Fast, MEd et Slow en fonction du prix
            if ($emaFastMax > $emaMedMax  && $emaMedMax  > $emaSlowMax) { $emaList = $emaSlowAff . $sep . $emaMedAff  . $sep . $emafastAff; }
            if ($emaFastMax < $emaMedMax  && $emaMedMax  < $emaSlowMax) { $emaList = $emafastAff . $sep . $emaMedAff  . $sep . $emaSlowAff; }
            if ($emaMedMax  > $emaSlowMax && $emaSlowMax > $emaFastMax) { $emaList = $emafastAff . $sep . $emaSlowAff . $sep . $emaMedAff;  }
            if ($emaMedMax  < $emaSlowMax && $emaSlowMax < $emaFastMax) { $emaList = $emaMedAff  . $sep . $emaSlowAff . $sep . $emafastAff; }
            if ($emaMedMax  > $emaFastMax && $emaFastMax > $emaSlowMax) { $emaList = $emaSlowAff . $sep . $emafastAff . $sep . $emaMedAff;  }
            if ($emaMedMax  < $emaFastMax && $emaFastMax < $emaSlowMax) { $emaList = $emaMedAff  . $sep . $emafastAff . $sep . $emaSlowAff; }
        }

        // Prix actuel du Market
        $this->_ticker  = \cryptos\trading\tickerData::ticker($this->_nameExBDD, $this->_marketTable, 'market_usdt_btc', $this->_apiBinance);
        $tickerAff      = number_format($this->_ticker, 8, '.', '');
        $tickerAff      = $this->_colorCli->getColor($tickerAff, 'white');

        // Stockage du prix le plus haut pour la stratégie de Stop Loss > Gain
        if ($this->_status == 'trade' && (empty($this->_topPrice) || $this->_ticker > $this->_topPrice)) {
            $this->_topPrice = $this->_ticker;
        }

        // Calcul du gain et du Stop Loss dynamique
        $gainAff = '';
        if ($this->_status == 'trade') {

            $resultLog  = array();

            // Calcul du gain
            $gain = (((100 / $this->_buyPrice) * $this->_ticker) - 100);
            $gain = number_format($gain, 2, '.', '') . '%';
            $resultLog[] = $gain;

            // Calcul du Stop Loss dynamique
            if (!empty($this->_stopLossDyn)) {
                $gain .= ' / ' . number_format($this->_stopLossDyn, 2, '.', '') . '%';
                $resultLog[] = $this->_stopLossDyn;
            }

            $gainAff = $sep . $this->_colorCli->getColor($gain, 'light_cyan');
            $resultLog = json_encode($resultLog);
        }

        // Calcul des Ema Spread des périodes précédentes
        $checkWait = '';

        if ($this->_status == 'wait') {

            if ($this->_logAff == 'full') {
                $emaSpreadN1 = (((100 / $emaSlow[5]) * $emaFast[5]) - 100) - $emaSpread;
                if ($emaSpreadN1 >= 0) { $emaSpreadN1 = chr(32) . chr(32) . $emaSpreadN1; }
                $emaSpreadN1 = 'N-1 ' . number_format( $emaSpreadN1, 2, '.', '') . '%';
                $emaSpreadN1Aff = $this->_colorCli->getColor($emaSpreadN1, 'light_cyan');

                $emaSpreadN2 = (((100 / $emaSlow[4]) * $emaFast[4]) - 100) - $emaSpread;
                if ($emaSpreadN2 >= 0) { $emaSpreadN2 = chr(32) . chr(32) . $emaSpreadN2; }
                $emaSpreadN2 = 'N-2 ' . number_format( $emaSpreadN2, 2, '.', '') . '%';
                $emaSpreadN2Aff = $this->_colorCli->getColor($emaSpreadN2, 'light_cyan');

                $emaSpreadN3 = (((100 / $emaSlow[3]) * $emaFast[3]) - 100) - $emaSpread;
                if ($emaSpreadN3 >= 0) { $emaSpreadN3 = chr(32) . chr(32) . $emaSpreadN3; }
                $emaSpreadN3 = 'N-3 ' . number_format( $emaSpreadN3, 2, '.', '') . '%';
                $emaSpreadN3Aff = $this->_colorCli->getColor($emaSpreadN3, 'light_cyan');

                $emaSpreadN4 = (((100 / $emaSlow[2]) * $emaFast[2]) - 100) - $emaSpread;
                if ($emaSpreadN4 >= 0) { $emaSpreadN4 = chr(32) . chr(32) . $emaSpreadN4; }
                $emaSpreadN4 = 'N-4 ' . number_format( $emaSpreadN4, 2, '.', '') . '%';
                $emaSpreadN4Aff = $this->_colorCli->getColor($emaSpreadN4, 'light_cyan');

                $checkWait .= $sep . $emaSpreadN1Aff . $sep . $emaSpreadN2Aff . $sep . $emaSpreadN3Aff . $sep . $emaSpreadN4Aff;

                $resultLog = json_encode(array($emaSpreadN1, $emaSpreadN2, $emaSpreadN3, $emaSpreadN3));

            } else {
                $resultLog = json_encode(array('Wait...'));
            }
        }

        // Tendance du marché
        $detectTrend = $this->detectTrend();

        // Marché Bearish ou Bullish
        if ($this->_changeTrend == 'bearish')  { $trend = 'Bear'; $trendAff = $this->_colorCli->getColor( $trend, 'light_red');    }
        else                                   { $trend = 'Bull'; $trendAff = $this->_colorCli->getColor( $trend, 'light_green');  }

        // Vérification du RSI sur une echelle de temps courte
        $this->_rsi = -1;
        $rsi = \cryptos\trading\rsi::getRSI($this->_nameExBDD, $this->_marketTable, $this->_rsiUnit, $this->_rsiInterval, 14, 1);
        if (isset($rsi['rsi']) && is_array($rsi['rsi'])) {
            $this->_rsi = end($rsi['rsi']);
        }

        // RSI Actuel
        $rsiAff = $this->_colorCli->getColor(number_format($this->_rsi, 2, '.', ' '), 'light_purple');

        // Market Name
        $marketName = explode('_', $this->_marketTable);
        $marketNameAff = $this->_colorCli->getColor(strtoupper($marketName[1] . '-' . $marketName[2]) . ' : ', 'yellow');

        // On replace l'écran pour n'afficher qu'une seule ligne
        system('clear');

        // Affichage de la ligne de résultats
        if ($this->_logAff == 'full') {
            echo $userAff . $sep . $date . $sep . $marketNameAff . $trendAff . $sep . $rsiAff . $sep . $tickerAff . $sep . $emaList . $sep . $emaSpreadAff . $gainAff . $checkWait . chr(10);
        }

        // Version light
        if ($this->_logAff == 'light') {
            if ($this->_status == 'wait') {
                $gainAff = $sep . $this->_colorCli->getColor('wait', 'light_cyan');
            }

            echo $userAff . $sep . $date . $sep . $marketNameAff . $trendAff . $sep . $rsiAff . $sep . $tickerAff . $gainAff . chr(10);
        }

        // Log des résultats de cette seconde;
        $this->majViewLog($trend, $this->_rsi, $this->_ticker, $emaSlowMax, $emaMedMax, $emaFastMax, $emaSpread, $resultLog);

        // Vérification des stratégies et execution d'ordres si nécessaire
        $this->tradeStatus();

        // Fin de la boucle
        $this->_timeEnd = microtime(true);
    }


    /**
     * Statut du bot, vérification de la nécessité de lancer ou fermer un trade
     */
    private function tradeStatus()
    {
        // Si un ordre manuel est passé depuis Viewing -------------------------
        $manualTrade = $this->manualTrade();

        if ($manualTrade) {
            $this->newTrade($manualTrade);
            return;
        }

        // Vérification des alertes depuis une stratégie tradingview -----------
        $checkTradingview = $this->checkTradingview();

        if ($checkTradingview != 'false') {
            $this->newTrade($checkTradingview);
            return;
        }

        // Un trade vient de se terminer, on attend un cycle avant d'écouter de nouvelles opportunités
        if (($this->_status == 'wait' && isset($this->_timestampLastTrade) && (time() - $this->_timestampLastTrade) < $this->_deltaSecond)) {
            return;
        }

        // Vérification du RSI sur une echelle de temps courte -----------------
        $checkRSI = $this->checkRSI();

        if ($checkRSI != 'false') {
            $this->newTrade($checkRSI);
            return;
        }

        // Vérification du Stochastic RSI --------------------------------------
        $checkStochRSI = $this->checkStochRSI();

        if ($checkStochRSI != 'false') {
            $this->newTrade($checkStochRSI);
            return;
        }

        // Vérification des volumes --------------------------------------------
        $checkVolume = $this->checkVolume();

        if ($checkVolume != 'false') {
            $this->newTrade($checkVolume);
            return;
        }

        // Stratégie Stop Loss > sécurisation des gains ------------------------
        $checkStopLossDyn = $this->checkStopLossDyn();

        if ($checkStopLossDyn == 'true') {
            $this->newTrade('SELL');
            return;
        }

        // Vérification des strategies EMA  ------------------------------------
        $strategie = $this->strategie();

        // Position d'achat
        if ($strategie == 'BUY') {
            $this->newTrade('BUY');
            return;
        }

        // Position de vente
        if ($strategie == 'SELL') {
            $this->newTrade('SELL');
            return;
        }
    }


    /**
     * Vérification des stratégie
     */
    private function strategie()
    {
        $emaSpread = array();

        $emaSlow   = $this->_emaSlow;
        $emaMed    = $this->_emaMed;
        $emaFast   = $this->_emaFast;

        // Stokage du Ema Spread des X derniers cycles dans un tableau
        foreach (range(0,6) as $i) {
            $emaSpread[$i] = ((100 / $emaSlow[$i]) * $emaFast[$i]) - 100;
        }

        /**
         * Recherche de stratégies d'achat :
         *
         * 'wait' : en position d'attente d'achat
         *
         * 'changeTrend   = 'bullish' => période autorisant les achats automatiques
         * 'confBuyStrat' = 'auto'    => achat automatique autorisé
         *
         */
        if (
                $this->_status == 'wait'
                &&
                $this->_confBuyStrat == 'auto'
                &&
                $emaSpread[6] < $this->_emaSpreadTriggerMax
                &&
                $this->_rsi != -1
                &&
                $this->_rsi < $this->_rsiMaxBuyLimit
        ) {

            // On n'achète pas si le prix d'achat dépasse l'Ema Fast de plus de 1.5 fois la valeur du Stop Loss
            if (
                    (((100 / end($emaFast)) * $this->_ticker) - 100) > ($this->_stopLoss * -1.5)
                    &&
                    (end($emaFast) > end($emaMed))
                    &&
                    (end($emaFast) > end($emaSlow))
            ) {
                return;
            }

            // Permet d'entrer au début d'une explosion du prix
            // if (
            //         (((100 / end($emaFast)) * $this->_ticker) - 100) > ($this->_stopLoss * -1)
            //         &&
            //         (((100 / end($emaFast)) * $this->_ticker) - 100) < ($this->_stopLoss * -1.5)
            //         &&
            //         (((($this->_Alltickers[6] - $this->_Alltickers[5]) / $this->_Alltickers[5]) * 100) > ($this->_stopLoss * -1))
            // ) {
            //     $this->_buyStrat  = 'agressivePrice';
            //     $this->_sellStrat = $this->_confSellStrat;
            //     return 'BUY';
            // }

            // Toutes les EMA sont dans le bon ordre
            if (end($emaFast) > end($emaMed) && end($emaMed) > end($emaSlow)) {

                // EmaSpread Trigger + 3 cycles de hausse ----------------------
                if (
                        $this->_emaSpreadBuyOnOff == 'on'
                        ||
                        ($this->_emaSpreadBuyOnOff == 'bullish' && $this->_changeTrend == 'bullish')
                ) {
                    if (
                        ($emaSpread[6] >= $this->_emaSpreadTrigger)
                        &&
                        (($emaSpread[6] > $emaSpread[5]) && ($emaSpread[5] > $emaSpread[4]) && ($emaSpread[4] > $emaSpread[3]))
                        &&
                        (($emaSpread[6] - $emaSpread[5]) >= 0.20)
                        &&
                        ($emaSpread[4] < $this->_emaSpreadTrigger)
                    ) {
                        $this->_buyStrat  = 'emaSpreadTrigger';
                        $this->_sellStrat = $this->_confSellStrat;
                        return 'BUY';
                    }
                }


                // Statégies EMA Cross -----------------------------------------
                if (
                        $this->_emaCrossBuyOnOff == 'on'
                        ||
                        ($this->_emaCrossBuyOnOff == 'bullish' && $this->_changeTrend == 'bullish')
                ) {

                    // EmaCross Fast > Med : L'EMA Fast croise la EMA Med et les 7 derniers cycles, l'EMA Fast n'est pas passée sous la EMA Slow
                    if ((end($emaFast) > end($emaMed)) && ($emaFast[5] < $emaMed[5])) {
                        if ($emaFast[0]>$emaSlow[0] && $emaFast[1]>$emaSlow[1] && $emaFast[2]>$emaSlow[2] && $emaFast[3]>$emaSlow[3] && $emaFast[4]>$emaSlow[4] && $emaFast[5]>$emaSlow[5]) {
                            $this->_buyStrat  = 'emaCrossFastMed';
                            $this->_sellStrat = $this->_confSellStrat;
                            return 'BUY';
                        }
                    }
                }


                // Statégies EMA agrssives -------------------------------------
                if (
                        $this->_emaAgressivBuyOnOff == 'on'
                        ||
                        ($this->_emaAgressivBuyOnOff == 'bullish' && $this->_changeTrend == 'bullish')
                ) {

                    // Reprise agressive : Check 1 cycle de hausse -----------------
                    if (($emaSpread[6] - $emaSpread[5]) >= 0.30) {
                        $this->_buyStrat  = 'ordre_agressive_1';
                        $this->_sellStrat = $this->_confSellStrat;
                        return 'BUY';
                    }

                    // Reprise agressive : Check 2 cycles de hausse ----------------
                    if (
                        (($emaSpread[6] - $emaSpread[4]) >= 0.35)
                        &&
                        (($emaSpread[6] > $emaSpread[5]) && ($emaSpread[5] > $emaSpread[4]))
                    ) {
                        $this->_buyStrat  = 'ordre_agressive_2';
                        $this->_sellStrat = $this->_confSellStrat;
                        return 'BUY';
                    }

                    // Reprise agressive : Check 3 cycles de hausse ----------------
                    if (
                        (($emaSpread[6] - $emaSpread[3]) >= 0.40)
                        &&
                        (($emaSpread[6] > $emaSpread[5]) && ($emaSpread[5] > $emaSpread[4]) && ($emaSpread[4] > $emaSpread[3]))
                    ) {
                        $this->_buyStrat  = 'ordre_agressive_3';
                        $this->_sellStrat = $this->_confSellStrat;
                        return 'BUY';
                    }

                    // Reprise agressive : Check 4 cycles de hausse ----------------
                    if (
                        (($emaSpread[6] - $emaSpread[2]) >= 0.45)
                        &&
                        (($emaSpread[6] > $emaSpread[5]) && ($emaSpread[5] > $emaSpread[4]) && ($emaSpread[4] > $emaSpread[3]))
                    ) {
                        $this->_buyStrat  = 'ordre_agressive_4';
                        $this->_sellStrat = $this->_confSellStrat;
                        return 'BUY';
                    }
                }
            }
        }

        ////////////////////////////////////////////////////////////////////////
        // Recherche de stratégies de vente
        if ($this->_status == 'trade' &&  $this->_rsi != -1  &&  $this->_rsi > $this->_rsiMinSellLimit) {

            // Vente au repli de EMA Fast --------------------------------------
            if ($this->_sellStrat == 'emaFast') {
                if ($emaFast[5] > $emaFast[6] && ($emaSpread[5] - $emaSpread[6]) > 0.08) {
                    return 'SELL';
                }
            }

            // Vente au croisement de EMA Fast et EMA Med ----------------------
            if ($this->_sellStrat == 'emaCross') {
                if ($emaFast[6] < $emaMed[6]) {
                    return 'SELL';
                }
            }
        }
    }


    /**
     * Ouverture ou fermeture d'un trade
     */
    private function newTrade($action)
    {
        // Tempo primeur
        if (substr($this->_user, 0, 3) != 'pym' && substr($this->_user, 0, 3) != 'dan') {
            usleep(1000000);
        }

        // Option pour récupérer le prix moyen d'achat ou de vente et les frais d'un ordre passé
        $orderOpt = array('newOrderRespType' => 'FULL');

        // Ordre d'achat
        if ($action == 'BUY' && $this->_status == 'wait') {

            if (strstr($this->_marketTable, 'virtual_')) {
                $buy = $this->buyVirtual($orderOpt);
            } else {
                $buy = $this->buy($orderOpt);
            }

            $order   = $buy['order'];
            $message = $buy['message'];
        }

        // Ordre de vente
        if ($action == 'SELL' && $this->_status == 'trade') {

            if (strstr($this->_marketTable, 'virtual_')) {
                $sell = $this->sellVirtual($orderOpt);
            } else {
                $sell = $this->sell($orderOpt);
            }

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

            // Mise à jour du statut
            if ($this->_status == 'wait') {
                $this->_status = 'trade';
            } else {
                $this->_status = 'wait';
            }

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

            /**
             * on reinitialise les top et bottom RSI à chaque BUY ou SELL pour démarrer un nouveau suivi
             * Si BUY (rsi repasse au dessus de 20), il faut remettre le bottom à 20
             * Si SELL (rsi repasse en dessous de 80), il faut remettre le top à 80
             */
            $this->initRSI();
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
     * Achat au market en deux temps pour les markets virtuels
     * USDT > BTC  &  BTC > altcoin
     */
    private function buyVirtual($orderOpt)
    {
        // Table de niveau pour le passage de l'USDT en BTC
        $tableMarketUsdtBtc = 'market_usdt_btc';

        // Récupération du stepSize (nombre de décimales max pour la quantité dans ce market)
        $marketNameBtc  = $this->marketName($tableMarketUsdtBtc);
        $stepSizeBtc    = $this->stepSize($marketNameBtc);

        // Calcul de la quantité à acheter en fonction de la configuration (PCT/Price) dans viewing
        $quantityBtc    = $this->buyQuantity($tableMarketUsdtBtc);
        $quantityBtc    = round($quantityBtc['quantity'], $stepSizeBtc, PHP_ROUND_HALF_DOWN);

        // API REST : Ordre d'achat au market de la quantité de Bitcoin pour ce trade
        $orderBtc = $this->_apiBinance->marketBuy($marketNameBtc, $quantityBtc, $orderOpt);

        // Requête REST : l'ordre d'achat de BTC retourne une erreur
        if (isset($orderBtc['code'])) {

            $message  = '___ Virtual Market : Buy BTC ERROR ___' . chr(10) . chr(10);

            return array(
                'order'     => $orderBtc,
                'message'   => $message,
            );

        // Achat de l'AltCoin
        } else {

            // Calcul du prix moyen et des frais du 1er trade USDT > BTC
            $sumPrice = 0;
            $sumQty   = 0;
            $sumFees  = 0;
            foreach($orderBtc['fills'] as $val) {
                $sumPrice += $val['price'] * $val['qty'];
                $sumQty   += $val['qty'];
                $sumFees  += $val['commission'];
                $asset     = $val['commissionAsset'];
            }

            $orderBtc['price']             = $sumPrice / $sumQty;
            $orderBtc['commission']        = $sumFees;
            $orderBtc['commissionAsset']   = $asset;

            // Table du market ayant pour référence le BTC
            $tableMarketBtcAltcoin = \cryptos\trading\tickerData::virtualBddTable($this->_marketTable);

            // Récupération du stepSize (nombre de décimales max pour la quantité dans ce market)
            $marketNameAlt  = $this->marketName($tableMarketBtcAltcoin);
            $stepSizeAlt    = $this->stepSize($marketNameAlt);

            // Récupération du ticker de l'altcoin
            $tickerBtcCoin  = \cryptos\trading\tickerData::ticker($this->_nameExBDD, $tableMarketBtcAltcoin);

            // Calcul de la quantité à acheter dans l'altcoin
            $quantityAlt    = ($quantityBtc / $tickerBtcCoin / 100) * 99;
            $this->_quantityCryptoTde = round($quantityAlt, $stepSizeAlt, PHP_ROUND_HALF_DOWN);

            // On stock la quantité de BTC qui ne pourront pas être transformé en Altcoin pour pouvoir ensuite calculer le retour de BTC > USDT
            $altNotSend = ($quantityBtc / $tickerBtcCoin) - $this->_quantityCryptoTde;
            $this->_btcNotSent = $altNotSend * $tickerBtcCoin;

            // API REST : Ordre d'achat au market de la quantité d'Altcoin avec les Bitcoin précédemment achetés pour ce trade
            $order = $this->_apiBinance->marketBuy($marketNameAlt, $this->_quantityCryptoTde, $orderOpt);

            echo chr(10) . chr(10) . ' Trade : BUY -> ' . $this->_quantityCryptoTde . chr(10) . chr(10);

            // Compilation des informations des 2 trades
            $order['symbol']                    = str_replace('BTC', 'USDT', $marketNameAlt);

            $order['virtualMarket']             = 1;
            $order['stepBtc_price']             = $orderBtc['price'];
            $order['stepBtc_origQty']           = $orderBtc['origQty'];
            $order['stepBtc_notSent']           = $this->_btcNotSent;
            $order['stepBtc_commission']        = $orderBtc['commission'];
            $order['stepBtc_commissionAsset']   = $orderBtc['commissionAsset'];

            $message  = '___ Virtual Market : Trade order BUY ___'  . chr(10) . chr(10);
            $message .= 'Market : '    .  $order['symbol']              . chr(10);
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
     * Vente au market en deux temps pour les markets virtuels
     * altcoin > BTC  &  BTC > USDT
     */
    private function sellVirtual($orderOpt)
    {
        // Table du market ayant pour référence le BTC
        $tableMarketBtcAltcoin = \cryptos\trading\tickerData::virtualBddTable($this->_marketTable);

        // Récupération du stepSize (nombre de décimales max pour la quantité dans ce market)
        $marketNameAlt = $this->marketName($tableMarketBtcAltcoin);

        // API REST : Ordre de vente au market de l'Altcoin > BTC
        $orderAlt = $this->_apiBinance->marketSell($marketNameAlt, $this->_quantityCryptoTde, $orderOpt);

        // Requête REST : l'ordre de vente de ALT > BTC retourne une erreur
        if (isset($orderAlt['code'])) {

            $message  = '___ Virtual Market : SELL ALT > BTC ERROR ___' . chr(10) . chr(10);

            return array(
                'order'     => $orderAlt,
                'message'   => $message,
            );

        // Vente des BTC et retour en USDT
        } else {

            // Calcul du prix moyen et des frais du 1er trade USDT > BTC
            $sumPrice = 0;
            $sumQty   = 0;
            $sumFees  = 0;
            foreach($orderAlt['fills'] as $val) {
                $sumPrice += $val['price'] * $val['qty'];
                $sumQty   += $val['qty'];
                $sumFees  += $val['commission'];
                $asset     = $val['commissionAsset'];
            }

            $orderAlt['price']             = $sumPrice / $sumQty;
            $orderAlt['commission']        = $sumFees;
            $orderAlt['commissionAsset']   = $asset;

            // Table de niveau pour le passage de l'USDT en BTC
            $tableMarketUsdtBtc = 'market_usdt_btc';

            // Récupération du stepSize (nombre de décimales max pour la quantité dans ce market)
            $marketNameBtc  = $this->marketName($tableMarketUsdtBtc);
            $stepSizeBtc    = $this->stepSize($marketNameBtc);

            // Calcul de la quantité de BTC à renvendre en USDT
            $btcReturned = $orderAlt['price'] * $orderAlt['origQty'];
            $btcQty = $this->_btcNotSent + $btcReturned;

            // On vérifie la balance BTC pour être sur de ne pas avoir moins que le montant calculé
            $balanceBtc = $this->balanceCrypto('BTC');

            if ($balanceBtc < $btcQty) {
                $btcQty = $balanceBtc;
            }

            $btcQty = round($btcQty, $stepSizeBtc, PHP_ROUND_HALF_DOWN);

            // On peut essayer jusqu'à 5 fois la revente des BTC en dimunuant le montant de 100 Satoshi pour être sur de passer

            // API REST : Ordre de vente au market des BTC > USDT
            for ($i=0; $i<5; $i++) {

                $order = $this->_apiBinance->marketSell($marketNameBtc, $btcQty, $orderOpt);

                if (isset($order['code'])) {
                    $btcQty -= 0.000001;
                } else {
                    break;
                }
            }

            echo chr(10) . chr(10) . ' Trade : SELL -> Alt : ' . $this->_quantityCryptoTde . ' - BTC : ' . $btcQty . chr(10) . chr(10);

            // Compilation des informations des 2 trades
            $order['symbol']                    = str_replace('BTC', 'USDT', $orderAlt['symbol']);

            $order['virtualMarket']             = 1;
            $order['stepAlt_price']             = $orderAlt['price'];
            $order['stepAlt_origQty']           = $orderAlt['origQty'];
            $order['stepAlt_commission']        = $orderAlt['commission'];
            $order['stepAlt_commissionAsset']   = $orderAlt['commissionAsset'];

            $message  = '___ Virtual Market : Trade order SELL ___'     . chr(10) . chr(10);
            $message .= 'Market : '    .  $order['symbol']              . chr(10);
            $message .= 'DateTime : '       .  date('Y:m:d H:i:s')      . chr(10);
            $message .= 'Quantity Alt : '   . $this->_quantityCryptoTde . chr(10);
            $message .= 'Quantity BTC : '   . $btcQty                   . chr(10);
            $message .= 'sellStrat : '      . $this->_sellStrat         . chr(10);

            return array(
                'order'     => $order,
                'message'   => $message,
            );
        }
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

            if (isset($order['virtualMarket']) && $order['virtualMarket'] == 1) {

                $saveOrder[':virtualMarket'] = 1;

                if ($order['side'] == 'BUY') {

                    $saveOrder[':stepBtc_price']            = $order['stepBtc_price'];
                    $saveOrder[':stepBtc_origQty']          = $order['stepBtc_origQty'];
                    $saveOrder[':stepBtc_notSent']          = $order['stepBtc_notSent'];
                    $saveOrder[':stepBtc_commission']       = $order['stepBtc_commission'];
                    $saveOrder[':stepBtc_commissionAsset']  = $order['stepBtc_commissionAsset'];

                    $addReqChp = ", virtualMarket, stepBtc_price, stepBtc_origQty, stepBtc_notSent, stepBtc_commission, stepBtc_commissionAsset";
                    $addReqVal = ", :virtualMarket, :stepBtc_price, :stepBtc_origQty, :stepBtc_notSent, :stepBtc_commission, :stepBtc_commissionAsset";

                } else {

                    $saveOrder[':stepAlt_price']            = $order['stepAlt_price'];
                    $saveOrder[':stepAlt_origQty']          = $order['stepAlt_origQty'];
                    $saveOrder[':stepAlt_commission']       = $order['stepAlt_commission'];
                    $saveOrder[':stepAlt_commissionAsset']  = $order['stepAlt_commissionAsset'];

                    $addReqChp = ", virtualMarket, stepAlt_price, stepAlt_origQty, stepAlt_commission, stepAlt_commissionAsset";
                    $addReqVal = ", :virtualMarket, :stepAlt_price, :stepAlt_origQty, :stepAlt_commission, :stepAlt_commissionAsset";
                }
            }

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
     * Récupération de l'état du profil en cas de redémarrage du bot
     */
    private function checkBDDStatus()
    {
        // Les informations ne seront récupérées qu'au démarage et à chaque changement de market
        if (!empty($this->_checkBDDStatusTable) && $this->_checkBDDStatusTable == $this->_marketTable) {
            return;
        } else {
            $this->_checkBDDStatusTable = $this->_marketTable;
        }

        try {
            $req = "SELECT          origQty, side, price, buyStrat, sellStrat, stepBtc_notSent, date_crea, UNIX_TIMESTAMP(date_crea) as tradeTimestamp

                    FROM            bin_ema_spread_trade

                    WHERE           user   = :user
                    AND             symbol = :symbol

                    ORDER BY        id DESC LIMIT 1";

            $sql = $this->_dbh->prepare($req);
            $sql->execute(array(
                'user'   => $this->_user,
                'symbol' => $this->_marketName,
            ));

            if ($sql->rowCount() > 0) {

                $res = $sql->fetch();

                $this->_quantityCryptoTde  = $res->origQty;
                $this->_timestampLastTrade = $res->tradeTimestamp;

                $expMarket = explode('_', $this->_marketTable);

                if ($res->side == 'BUY') {


                    $this->_status      = 'trade';
                    $this->_buyPrice    = $res->price;
                    $this->_buyStrat    = $res->buyStrat;
                    $this->_sellStrat   = $res->sellStrat;

                    // Market Virtuel : Quantité de BTC n'ayant pu être envoyé en Altcoin
                    // Cette quantité servira lors du retour de BTC > USDT
                    $this->_btcNotSent  = $res->stepBtc_notSent;

                    $message  = '___ Check BDD Status ___' . chr(10) . chr(10);
                    $message .= 'User : ' . $this->_user . chr(10);
                    $message .= 'Init capital : ' . $this->_capitalCryptoRef . ' ' . $expMarket[1] . chr(10);
                    $message .= 'Trade quantity : ' . $this->_quantityCryptoTde . ' ' . $expMarket[2] . chr(10);
                    $message .= 'Date  : ' . $res->date_crea . chr(10);
                    $message .= 'Status : trade' . chr(10);
                    $message .= 'Buy Price : ' . $this->_buyPrice . chr(10);
                    $message .= 'Buy Strat : ' . $this->_buyStrat . chr(10);
                    $message .= 'Sell Strat : ' . $this->_sellStrat . chr(10);

                    if (!empty($this->_btcNotSent)) {
                        $message .= 'virtualMarket BTC not sent : ' . $this->_btcNotSent . chr(10);
                    }

                    $message .= chr(10);

                    error_log($message, 3, $this->_logFileName);
                    $this->telegramMsg($message);

                } else {

                    $this->_status = 'wait';

                    $message  = '___ Check BDD Status ___' . chr(10) . chr(10);
                    $message .= 'User : ' . chr(9) . chr(9) . $this->_user . chr(10);
                    $message .= 'Init capital : ' . chr(9) . $this->_capitalCryptoRef . ' ' . $expMarket[1] . chr(10);
                    // On ne sait pas encore combien on va trader d'unités puisqu'on attend
                    //$message .= 'Trade quantity : ' . $this->_quantityCryptoTde . ' ' . $expMarket[2] . chr(10);
                    //$message .= 'Date : ' . chr(9) . chr(9) . $res->date_crea . chr(10);
                    $message .= 'Status : ' . chr(9) . 'wait' . chr(10) . chr(10);

                    error_log($message, 3, $this->_logFileName);
                    $this->telegramMsg($message);
                }
            } else {

                $message  = '___ Check BDD Status ___' . chr(10) . chr(10);
                $message .= 'user : ' . $this->_user . chr(10);
                $message .= 'status : wait' . chr(10) . chr(10);

                error_log($message, 3, $this->_logFileName);
                $this->telegramMsg($message);
            }

        } catch (\Exception $e) {

            $message  = '___ Error BDD : method checkBDDStatus  ___' . chr(10) . chr(10);
            $message .= 'Error Check BDD Status : ' . $this->_user . chr(10);
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
     * Création et récupération du nom du fichier de log de l'utilisateur
     */
    private function logFile()
    {
        $this->_logFileName = '/root/bot-ema-spread-' . strtolower( $this->_user ) . '.log';

        if (! file_exists($this->_logFileName)) {
            fopen(  $this->_logFileName, "w" );
        }
    }


    /**
     * Stop Loss dynamique > stratégie de gain lié au topPrice pendant le trade
     */
    private function checkStopLossDyn()
    {
        $res = 'false';

        if ($this->_status == 'trade' && $this->_stopLossType != 'off') {

            // Stop Loss Dynamic -----------------------------------------------
            if ($this->_stopLossType == 'dynamic') {

                // Evolution du stopLoss en fonction de l'évolution du trade
                $this->_stopLossDyn = $this->_stopLoss;

                if ($this->_topPrice > $this->_buyPrice) {

                    $gainMax = ((100 / $this->_buyPrice) * $this->_topPrice) - 100;

                    // Le premier coefficient de Stop Loss dynamique permet de créer un canal de volatilité fermant jusqu'en à 2%
                    if ($gainMax <= 2) {

                        $this->_stopLossDyn = ($gainMax * $this->_stopLossDynCoeff) + $this->_stopLoss;

                        // Le second coefficient de Stop Loss dynamique permet de créer un canal de volatilité ouvrant au delà de 2%
                    } else {

                        // calcul du Stop Loss à partir de 2%
                        $stopLoss2 = (2 - ((2 * $this->_stopLossDynCoeff) + $this->_stopLoss)) * -1;

                        // A partir de 2%, le stop loss dynamique est calculé avec le 2ème coefficient comme si on démarrait de 0%
                        $this->_stopLossDyn = ((($gainMax - 2) * $this->_stopLossDynCoeff2) + $stopLoss2) + 2;
                    }
                }

                // Calcul du gain actuel
                $gain = ((100 / $this->_buyPrice) * $this->_ticker) - 100;

                // Stratégies de sortie stopLoss > Gain
                // if ((time() - $this->_timestampLastTrade) < ($this->_deltaSecond * 1)) {
                //
                //     if ($gain < $this->_stopLossDyn  &&  $this->_ticker < end($this->_emaFast)) {
                //         $this->_sellStrat = 'stopLossInit' . round($this->_stopLossDyn, 2);
                //
                //         $res = 'true';
                //     }
                //
                // } else {
                //
                //     if ($gain < $this->_stopLossDyn) {
                //
                //         if ($this->_stopLossDyn < 0) {
                //             $this->_sellStrat = 'stopLossDyn' . round($this->_stopLossDyn, 2);
                //         } else {
                //             $this->_sellStrat = 'gainDyn' . round($this->_stopLossDyn, 2);
                //         }
                //
                //         $res = 'true';
                //     }
                // }

                // Gestion du Stop Loss Dynamic Timer
                if ($this->_stopLossTimer == 'on') {

                    echo 'Stop Loss Timer : ON' . chr(10);

                    if ($this->_stopLossDyn > $this->_stopLossDynTop) {

                        $this->_stopLossDynTop = $this->_stopLossDyn;
                        $this->_stopLossTimerInactivity = 0;

                        echo 'Maj stopLossDynTop : ' . $this->_stopLossDynTop . chr(10);

                    } else {

                        $this->_stopLossDyn = $this->_stopLossDynTop;
                        $this->_stopLossTimerInactivity++;

                        echo 'Maj stopLossTimerInactivity : ' . $this->_stopLossTimerInactivity . chr(10);

                        if ($this->_stopLossTimerInactivity >= $this->_stopLossTimerWait  &&  $this->_stopLossDyn < $gain) {

                            $this->_stopLossDyn     += $this->_stopLossTimerStep;
                            $this->_stopLossDynTop  += $this->_stopLossTimerStep;
                            $this->_stopLossTimerInactivity = 0;

                            echo 'Maj Step : ' . $this->_stopLossDynTop . chr(10);
                        }
                    }
                } else {

                    $this->_stopLossTimerInactivity = 0;
                }

                // Stratégies de sortie stopLoss > Gain
                if ($gain < $this->_stopLossDyn) {

                    if ($this->_stopLossDyn < 0) {
                        $this->_sellStrat = 'stopLossDyn' . round($this->_stopLossDyn, 2);
                    } else {
                        $this->_sellStrat = 'gainDyn' . round($this->_stopLossDyn, 2);
                    }

                    $res = 'true';
                }

                // echo 'sellStrat : ' . $this->_sellStrat . chr(10);
                // echo 'res : ' . $res . chr(10);
            }

            // Stop Loss Static ------------------------------------------------
            if ($this->_stopLossType == 'static') {

                $this->_stopLossDyn = $this->_stopLoss;

                // Calcul du gain actuel
                $gain = ((100 / $this->_buyPrice) * $this->_ticker) - 100;

                if ($gain < $this->_stopLoss) {
                    $this->_sellStrat = 'stopLossStatic';

                    $res = 'true';
                }
            }

        } else {

            $this->_stopLossDyn = null;
        }

        return $res;
    }


    /**
     * Permet de réaliser un achat ou une vente en manuel depuis Viewing
     */
    private function manualTrade()
    {
        // Requête préparée pour que l'ordre d'achat ou de vente ne s'exécute qu'une seule fois
        $req = "UPDATE bin_ema_spread_conf SET bin_manual_trade = '' WHERE profilName = :profilName";
        $sql = $this->_dbh->prepare($req);

        if (($this->_manualTrade == 'buy' && $this->_status == 'trade') || ($this->_manualTrade == 'sell' && $this->_status == 'wait')) {
            $sql->execute(array( ':profilName' => $this->_user ));
            return false;
        }

        switch ($this->_manualTrade)
        {
            case 'buy' :
                $this->_buyStrat  = 'manualTradeBuy';
                $this->_sellStrat = $this->_confSellStrat;
                $sql->execute(array( ':profilName' => $this->_user ));
                return 'BUY';
                break;

            case 'sell' :
                $this->_sellStrat = 'manualTradeSell';
                $sql->execute(array( ':profilName' => $this->_user ));
                return 'SELL';
                break;

            default :
                return false;
        }
    }


    /**
     * Permet de réaliser un achat en écoutant une stratégie Tradingviex (alerte email)
     */
    private function checkTradingview()
    {
        // Récupération des configurations d'achat et de vente
        $confBuy  = $this->checkTradingviewConf('buy');
        $confSell = $this->checkTradingviewConf('sell');

        // Récupération des ordres d'achat ou de vente pour ce market (durée de vie d'une alerte : 3 minutes)
        $gmdate = gmdate('Y-m-d H:i:s');

        $req = "SELECT      *

                FROM        tradingview_alert

                WHERE       market      =    :market
                AND         strat       =    :strat
                AND         side        LIKE :side
                AND         date_email  >     DATE_ADD('$gmdate', INTERVAL -3 MINUTE)

                ORDER BY    id DESC LIMIT 1";

        $sql = $this->_dbh->prepare($req);

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

    /**
     * Récupération des configurations
     *
     * @param   string  $side     Buy | Sell
     * @return  array
     */
    private function checkTradingviewConf($side)
    {
        $conf = array();

        if ($side == 'buy') {
            if ($this->_confBuyTradingview1 != 'off')   $conf['strat1'] = $this->_confBuyTradingview1;
            if ($this->_confBuyTradingview2 != 'off')   $conf['strat2'] = $this->_confBuyTradingview2;
            if ($this->_confBuyTradingview3 != 'off')   $conf['strat3'] = $this->_confBuyTradingview3;
            if ($this->_confBuyTradingview4 != 'off')   $conf['strat4'] = $this->_confBuyTradingview4;
            if ($this->_confBuyTradingview5 != 'off')   $conf['strat5'] = $this->_confBuyTradingview5;
        }

        if ($side == 'sell') {
            if ($this->_confSellTradingview1 != 'off')  $conf['strat1'] = $this->_confBuyTradingview1;
            if ($this->_confSellTradingview2 != 'off')  $conf['strat2'] = $this->_confBuyTradingview2;
            if ($this->_confSellTradingview3 != 'off')  $conf['strat3'] = $this->_confBuyTradingview3;
            if ($this->_confSellTradingview4 != 'off')  $conf['strat4'] = $this->_confBuyTradingview4;
            if ($this->_confSellTradingview5 != 'off')  $conf['strat5'] = $this->_confBuyTradingview5;
        }

        return $conf;
    }


    /**
     * Réinitialisation du suivi du RSI à la revente
     */
    private function initRSI()
    {
        $this->_rsiTop    = $this->_rsiMaxSellWatch;
        $this->_rsiBottom = $this->_rsiMinBuyWatch;
    }


    /**
     * Suivi du RSI - Zone d'achat et de vente
     */
    private function checkRSI()
    {
        // On vérifie que l'on récupère bien la valeur du RSI
        if ($this->_rsi != -1) {

            // Achat par RSI : zone de surveillance des valeurs basses
            if (
                $this->_status == 'wait'
                &&
                $this->_confBuyStrat == 'auto'
                &&
                $this->_rsi != -1
                &&
                $this->_rsi < $this->_rsiMaxBuyLimit
                &&
                (
                    $this->_rsiBuyOnOff == 'on'
                    ||
                    ($this->_rsiBuyOnOff == 'bullish' && $this->_changeTrend == 'bullish')
                )
            ) {

                if ($this->_rsi < $this->_rsiBottom) {
                    $this->_rsiBottom = $this->_rsi;

                } else {

                    if ($this->_rsiBottom < $this->_rsiMinBuyWatch && $this->_rsi >= ($this->_rsiBottom + $this->_rsiDiffBuy)) {
                        $this->_buyStrat  = 'buyRSI' . round($this->_rsi);
                        $this->_sellStrat = $this->_confSellStrat;

                        return 'BUY';
                    }
                }
            }

            // Vente par RSI : zone de surveillance des valeurs hautes
            if ($this->_status == 'trade' && $this->_rsiSellOnOff == 'on') {

                if ($this->_rsi > $this->_rsiTop) {
                    $this->_rsiTop = $this->_rsi;

                } else {

                    if ($this->_rsiTop > $this->_rsiMaxSellWatch && $this->_rsi <= ($this->_rsiTop - $this->_rsiDiffSell)) {
                        $this->_sellStrat = 'sellRSI' . round($this->_rsi);

                        return 'SELL';
                    }
                }
            }
        }

        return 'false';
    }


    /**
     * Suivi du Stochastic RSI - Zone d'achat et de vente
     */
    private function checkStochRSI()
    {
        // Achat par Stochastic RSI --------------------------------------------
        if (
            $this->_status == 'wait'
            &&
            $this->_confBuyStrat == 'auto'
            &&
            $this->_rsi != -1
            &&
            $this->_rsi < $this->_rsiMaxBuyLimit
            &&
            (
                $this->_stochRsiBuyOnOff == 'on'
                ||
                ($this->_stochRsiBuyOnOff == 'bullish' && $this->_changeTrend == 'bullish')
            )
        ) {

            $stochRsiFast = \cryptos\trading\stochRsi::getStochRSI($this->_nameExBDD, $this->_marketTable, $this->_stochRsiUnit, $this->_stochRsiIntFast, 14, 3);
            $stochRsiSlow = \cryptos\trading\stochRsi::getStochRSI($this->_nameExBDD, $this->_marketTable, $this->_stochRsiUnit, $this->_stochRsiIntSlow, 14, 3);

            /**
             * Check de la Stochastic RSI à intervalle court (1 minute)
             *
             * Règle 1 : Stochastic K doit être inférieur à 50%
             * Règle 2 : Stochastic K doit être haussier depuis 2 cycles || Stochastic K a progresser 15% sur le dernier cycle || Stochastic K inférieur à 10%
             */
            $checkInterval1 = 'false';

            if (
                $stochRsiFast['stochRsiK'][2] < 50
                &&
                (
                    ($stochRsiFast['stochRsiK'][2] > $stochRsiFast['stochRsiK'][1] && $stochRsiFast['stochRsiK'][1] > $stochRsiFast['stochRsiK'][0])
                    ||
                    (($stochRsiFast['stochRsiK'][2] - $stochRsiFast['stochRsiK'][1]) >= 15)
                    ||
                    ($stochRsiFast['stochRsiK'][2] <= 15)
                )
            ) {
                $checkInterval1 = 'true';
            }

            /**
             * Check de la Stochastic RSI à intervalle long (5 minute)
             *
             * Règle 1 : Toutes les vérifications de le Stoch RSI courte sont OK
             * Règle 2 : Stochastic K doit être inférieur à 50%
             * Règle 3 : Stochastic K vient de croiser Stochastic D à la hausse (sur 1 cycle ou sur 2 en étant en dessous de 20%)
             */
            if (
                $checkInterval1 == 'true'
                &&
                $stochRsiSlow['stochRsiK'][2] < 50
                &&
                (
                    ($stochRsiSlow['stochRsiK'][1] < $stochRsiSlow['stochRsiD'][1] && $stochRsiSlow['stochRsiK'][2] > $stochRsiSlow['stochRsiD'][2])
                    ||
                    (
                        $stochRsiSlow['stochRsiK'][2] < 20
                        &&
                        $stochRsiSlow['stochRsiK'][2] > $stochRsiSlow['stochRsiK'][0]
                        &&
                        ($stochRsiSlow['stochRsiK'][0] < $stochRsiSlow['stochRsiD'][0] && $stochRsiSlow['stochRsiK'][2] > $stochRsiSlow['stochRsiD'][2])
                    )
                )
            ) {
                $this->_buyStrat  = 'buyStochRSI';
                $this->_sellStrat = $this->_confSellStrat;

                echo chr(10) . 'BUY' . chr(10);

                return 'BUY';
            }
        }

        // Vente par Stochastic RSI --------------------------------------------
        $this->_stochRsiSellOnOff = 'off';

        if ($this->_stochRsiSellOnOff == 'on' && $this->_status == 'trade') {

        }

        return 'false';
    }


    /**
     * Suivi du volume - Zone d'achat et de vente
     * Analyse basée sur le 'Trade History' et 'l'Order Book'
     */
    private function checkVolume()
    {
        if (
            $this->_status == 'wait'
            &&
            $this->_confBuyStrat == 'auto'
            &&
            $this->_rsi != -1
            &&
            $this->_rsi < $this->_rsiMaxBuyLimit
            &&
            (
                $this->_volBuyOnOff == 'on'
                ||
                ($this->_volBuyOnOff == 'bullish' && $this->_changeTrend == 'bullish')
            )
        ) {


            if (strstr($this->_marketTable, 'virtual_')) {

                // Table du market ayant pour référence le BTC
                $tableMarketBtcAltcoin = \cryptos\trading\tickerData::virtualBddTable($this->_marketTable);

                // Table 'Trade History' du market
                $mhTable = str_replace('market_', 'mh_', $tableMarketBtcAltcoin);

                // Table 'Order Book' du market
                $obTable = str_replace('market_', 'ob_', $tableMarketBtcAltcoin);

            } else {

                // Table 'Trade History' du market
                $mhTable = str_replace('market_', 'mh_', $this->_marketTable);

                // Table 'Order Book' du market
                $obTable = str_replace('market_', 'ob_', $this->_marketTable);
            }

            if ($this->_status == 'wait') {

                // Vérification de la fraicheur de la dernière entrée dans le Trade History
                $req = "SELECT UNIX_TIMESTAMP(date_modif) AS ts_date_modif FROM $mhTable ORDER BY date_modif DESC LIMIT 1";
                $sql = $this->_dbhEx->query($req);
                $res = $sql->fetch();

                // Si la dernière entrée a plus de 10 secondes,
                if ((time() - $res->ts_date_modif) > 10) {
                    return 'false';
                }

                // Récupération de la moyenne des achats et des ventes sur la denière heure
                $req = "SELECT AVG(total) AS moyenne FROM $mhTable WHERE orderType = :orderType AND date_modif >= DATE_ADD(date_modif, INTERVAL -15 MINUTE)";
                $sql = $this->_dbhEx->prepare($req);

                // Moyenne des BUY
                $sql->execute(array(':orderType' => 'BUY'));
                $res = $sql->fetch();
                $moyBUY = $res->moyenne;

                // Moyenne des SELL
                $sql->execute(array(':orderType' => 'SELL'));
                $res = $sql->fetch();
                $moySELL = $res->moyenne;

                // Récupération du colume de la denière minute des BUY et des SELL
                $req = "SELECT total FROM $mhTable WHERE orderType = :orderType ORDER BY id DESC LIMIT 1";
                $sql = $this->_dbhEx->prepare($req);

                // VOlume de la dernière minute des BUY
                $sql->execute(array(':orderType' => 'BUY'));
                $res = $sql->fetch();
                $lastBUY = $res->total;

                // VOlume de la dernière minute des SELL
                $sql->execute(array(':orderType' => 'SELL'));
                $res = $sql->fetch();
                $lastSELL = $res->total;

                echo 'Moyenne BUY : '  . $this->formatNbr($moyBUY, 2)  . ' | Dernière minute BUY : '  . $this->formatNbr($lastBUY, 2)  . ' => '. $this->formatNbr($this->diffPCT($moyBUY,  $lastBUY),  2) . '%' . chr(10);
                echo 'Moyenne SELL : ' . $this->formatNbr($moySELL, 2) . ' | Dernière minute SELL : ' . $this->formatNbr($lastSELL, 2) . ' => '. $this->formatNbr($this->diffPCT($moySELL, $lastSELL), 2) . '%' . chr(10);

                // Vérification d'une opportunité d'achat
                $moyDiff  = $moyBUY  - $moySELL;
                $lastDiff = $lastBUY - $lastSELL;
                echo 'Diff (BUY - SELL) heure (moyenne minute)  : ' . $this->formatNbr($moyDiff, 2) . chr(10);
                echo 'Diff (BUY - SELL) dernière minute  : ' . $this->formatNbr($lastDiff, 2) . chr(10);

                $checkOpportunity = $this->formatNbr($this->diffPCT($moyDiff, $lastDiff), 2);

                echo 'Check opportunité : ' . $checkOpportunity . '%' . chr(10);
                if ($lastDiff > 0 && $checkOpportunity > 3000) {

                    echo 'Ok pour check Order Book !' . chr(10);

                    // On vérifie l'Order Book pour voir s'il y a de la place pour une montée de prix
                    $req = "SELECT jsonOrderBook, UNIX_TIMESTAMP(date_crea) AS ts_date_crea FROM $obTable ORDER BY id DESC LIMIT 1";
                    $sql = $this->_dbhEx->query($req);
                    $res = $sql->fetch();

                    // Si la dernière entrée a plus de 10 secondes,
                    if ((time() - $res->ts_date_crea) > 20) {
                        return 'false';
                    }

                    $ob = json_decode($res->jsonOrderBook);

                    // Lecture des ventes dans l'Order Book
                    $asks = $ob->asks;

                    $cumulAsks = 0;
                    $i=0;
                    foreach ($asks as $val) {

                        $rate = $val[0];
                        $qty  = $val[1];

                        $cumulAsks += $rate * $qty;

                        // echo $i . ' - cumulAsks : ' . $cumulAsks . ' - Total : ' . $total . chr(10);

                        if ($cumulAsks > (2 * $lastDiff)) {
                            $gainPotentiel =  $this->formatNbr($this->diffPCT($this->_ticker, $rate), 2);
                            break;
                        }

                        $i++;
                    }

                    echo 'Gain potentiel : ' . $gainPotentiel . '%' . chr(10);

                    if ($gainPotentiel > 0.8) {

                        $message  = '___ Method volume opportunité ___' . chr(10) . chr(10);
                        $message .= 'Heure : ' . date('Y-m-d H:i:s') . chr(10);
                        $message .= 'Diff dernière minute : ' . $this->formatNbr($lastDiff, 2) . chr(10);
                        $message .= 'Diff moyenne dernière heure : ' . $this->formatNbr($moyDiff, 2) . chr(10);
                        $message .= 'Calcul opportunité : ' . $checkOpportunity . '%' . chr(10);
                        $message .= 'Prix de sortie potentiel : ' .  $this->formatNbr($rate, 4) . chr(10) . chr(10);
                        $message .= 'Gain potentiel : ' . $gainPotentiel . '%' . chr(10) . chr(10);

                        error_log($message, 3, $this->_logFileName);
                        $this->telegramMsg($message);

                        $this->_buyStrat  = 'buyVolume';
                        $this->_sellStrat = $this->_confSellStrat;

                        echo chr(10) . 'BUY' . chr(10);

                        return 'BUY';
                    }
                }
            }
        }

        return 'false';
    }


    /**
     * Calcul d'un diff en pourcentage
     */
    private function diffPCT($nbr1, $nbr2)
    {
        // return (((100 / $nbr1) * $nbr2) - 100);
        return ((($nbr2 - $nbr1) / abs($nbr1)) * 100);
    }


    /**
     * formatage de l'affichage d'un pourcentage
     */
    private function formatNbr($nbr, $nbDecimal)
    {
        return number_format($nbr, $nbDecimal, '.', '');
    }


    /**
     * Connexion à l'API de Binance et check de la configuration du Bot
     */
    private function getConf($type, $msg=0)
    {
        $req = "SELECT * FROM bin_ema_spread_conf WHERE profilName = :profilName";
        $sql = $this->_dbh->prepare($req);
        $sql->execute(array( ':profilName' => $this->_user ));

        if ($sql->rowCount() > 0) {

            $res = $sql->fetch();

            // Vérification de l'activation du compte utilisateur
            if ($res->activ == 0) {
                return 'User désactivé !';
            }

            if ($type == 'initBot') {

                // Id du profil du Bot
                $this->_idProfil = $res->id;

                // Connexion à l'API Binance
                $crypt = new \core\crypt();

                $apiKey    = $crypt->decrypt($res->bin_apiKey);
                $apiSecret = $crypt->decrypt($res->bin_apiSecret);
                $this->_apiBinance = new \Binance\API($apiKey, $apiSecret);

                // Récupération Activation et id de Telegram (@VwEmaBot est un canal privé => inviter le user sur le canal d'abord)
                $this->_telegram_activ      = $res->activ_telegram;
                $this->_telegram_id         = $res->telegram_id;
                $this->_telegram_token      = $res->telegram_token;

                // Configuration RSI au démarrage du bot
                $this->_rsiMaxSellWatch     = $res->bin_rsi_max_sell_watch;
                $this->_rsiMinBuyWatch      = $res->bin_rsi_min_buy_watch;
            }

            // Récupération de la configuration
            if ($type == 'conf') {

                $this->_activBot            = $res->bin_activ_bot;

                $this->_confBuyStrat        = $res->bin_buyStrat;
                $this->_confSellStrat       = $res->bin_sellStrat;

                $this->_confBuyTradingview1 = $res->bin_tradingview_1_buy;
                $this->_confBuyTradingview2 = $res->bin_tradingview_2_buy;
                $this->_confBuyTradingview3 = $res->bin_tradingview_3_buy;
                $this->_confBuyTradingview4 = $res->bin_tradingview_4_buy;
                $this->_confBuyTradingview5 = $res->bin_tradingview_5_buy;

                $this->_confSellTradingview1= $res->bin_tradingview_1_sell;
                $this->_confSellTradingview2= $res->bin_tradingview_2_sell;
                $this->_confSellTradingview3= $res->bin_tradingview_3_sell;
                $this->_confSellTradingview4= $res->bin_tradingview_4_sell;
                $this->_confSellTradingview5= $res->bin_tradingview_5_sell;

                $this->_commentsTradingview = $res->bin_tradingview_comments;

                $this->_marketTable         = $res->bin_market_table;

                $this->_buyOption           = $res->bin_buy_option;
                $this->_buyWithPct          = $res->bin_buy_pct;
                $this->_buyWithPrice        = $res->bin_buy_price;

                // EMA
                $this->_interval            = $res->bin_ema_interval;
                $this->_unit                = $res->bin_ema_unit;

                $this->_emaSlowCandles      = $res->bin_ema_slow;
                $this->_emaMedCandles       = $res->bin_ema_med;
                $this->_emaFastCandles      = $res->bin_ema_fast;

                $this->_emaSpreadTrigger    = $res->bin_ema_spread;
                $this->_emaSpreadTriggerMax = $res->bin_ema_spread_max;

                $this->_emaAgressivBuyOnOff = $res->bin_ema_agressive_activ_buy;
                $this->_emaCrossBuyOnOff    = $res->bin_ema_cross_activ_buy;
                $this->_emaSpreadBuyOnOff   = $res->bin_ema_spread_activ_buy;

                $this->_volBuyOnOff         = $res->bin_volume_activ_buy;

                // Stop Loss
                $this->_stopLossType        = $res->bin_stop_loss_type;
                $this->_stopLoss            = $res->bin_stop_loss;
                $this->_stopLossDynCoeff    = $res->bin_stopLossDynCoeff;
                $this->_stopLossDynCoeff2   = $res->bin_stopLossDynCoeff2;

                $this->_stopLossTimer       = $res->bin_stopLoss_timer;
                $this->_stopLossTimerWait   = $res->bin_stopLoss_timer_wait;
                $this->_stopLossTimerStep   = $res->bin_stopLoss_timer_step;

                // Trade Manuel
                $this->_manualTrade         = $res->bin_manual_trade;

                // RSI
                $this->_rsiBuyOnOff         = $res->bin_rsi_activ_buy;
                $this->_rsiSellOnOff        = $res->bin_rsi_activ_sell;

                $this->_rsiInterval         = $res->bin_rsi_interval;
                $this->_rsiUnit             = $res->bin_rsi_unit;

                $this->_rsiDiffBuy          = $res->bin_rsi_diff_buy;
                $this->_rsiDiffSell         = $res->bin_rsi_diff_sell;

                $this->_rsiMaxBuyLimit      = $res->bin_rsi_max_buy_limit;
                $this->_rsiMinSellLimit     = $res->bin_rsi_min_sell_limit;

                // Stoch RSI
                $this->_stochRsiIntFast     = $res->bin_stoch_rsi_intFast;
                $this->_stochRsiIntSlow     = $res->bin_stoch_rsi_intSlow;
                $this->_stochRsiUnit        = $res->bin_stoch_rsi_unit;

                $this->_stochRsiBuyOnOff    = $res->bin_stoch_rsi_activ_buy;


                // Changement de valeur pour le seuil de revente RSI
                if ($this->_rsiMaxSellWatch != $res->bin_rsi_max_sell_watch) {

                    if ($this->_rsi <= $res->bin_rsi_max_sell_watch) {
                        $this->_rsiTop = $res->bin_rsi_max_sell_watch;
                    }

                    $this->_rsiMaxSellWatch = $res->bin_rsi_max_sell_watch;
                }

                // Changement de valeur pour le seuil d'achat RSI
                if ($this->_rsiMinBuyWatch != $res->bin_rsi_min_buy_watch) {

                    if ($this->_rsi >= $res->bin_rsi_min_buy_watch) {
                        $this->_rsiBottom = $res->bin_rsi_min_buy_watch;
                    }

                    $this->_rsiMinBuyWatch = $res->bin_rsi_min_buy_watch;
                }

                // Changement de configuration
                if ($this->_changeConfDate != $res->date_modif && $msg == 1) {

                    // Stockage de la dernière date de modification de configuration
                    $this->_changeConfDate = $res->date_modif;

                    // Calcul du nombre de seconde d'une bougie
                    $this->deltaSecond();

                    // Récupération du nom du Market;
                    $this->marketName();

                    // Récupération du capital de crypto de référence
                    $this->capitalCryptoRef();

                    // Remise à zéro des variables de trade
                    $forceResetVar = 'false';
                    if (empty($this->_changeConfMarket) || $this->_changeConfMarket != $this->_marketName) {
                        $this->_changeConfMarket = $this->_marketName;
                        $forceResetVar = 'true';
                    }

                    if ($this->_status == 'wait' || $forceResetVar == 'true') {

                        $this->_topPrice    = 0;
                        $this->_buyPrice    = null;

                        $this->_stopLossDyn = null;
                        $this->_stopLossDynTop          = null;
                        $this->_stopLossTimerInactivity = 0;
                    }

                    // Vérification de l'activation du bot
                    if (empty($this->_activBotChange) || $this->_activBotChange != $this->_activBot) {
                        $this->_activBotChange = $this->_activBot;

                        if ($this->_activBot == 0) {
                            echo chr(10) . chr(10) . 'Bot inactif !' . chr(10) . chr(10);
                        }
                    }

                    // Récupération du nombre de décimales pour le market
                    $this->stepSize();

                    $message  = '___ Configuration ___' . chr(10) . chr(10);
                    $message .= 'Market table : '       . chr(9) . $this->_marketTable . chr(10);

                    if ($this->_buyOption == 'pct') {
                        $message .= 'Achat pct : '      . chr(9) . $this->_buyWithPct . '%' . chr(10);
                    } else {
                        $message .= 'Achat prix : '     . chr(9) . $this->_buyWithPrice . chr(10);
                    }

                    $message .= 'Interval : '           . chr(9) . $this->_interval . ' ' . $this->_unit . chr(10);
                    $message .= 'Ema Slow : '           . chr(9) . $this->_emaSlowCandles . chr(10);
                    $message .= 'Ema Med : '            . chr(9) . $this->_emaMedCandles . chr(10);
                    $message .= 'Ema Fast : '           . chr(9) . $this->_emaFastCandles . chr(10);
                    $message .= 'Ema Spread Trigger : ' .          $this->_emaSpreadTrigger . chr(10);
                    $message .= 'Ema Spread Trigger Max : ' .      $this->_emaSpreadTriggerMax . chr(10);
                    if ($this->_stopLossType != 'off') {
                        $message .= 'Stop Loss : '          . chr(9) . $this->_stopLoss . chr(10);
                        $message .= 'Stop Loss Coeff. 1 : ' . chr(9) . $this->_stopLossDynCoeff . chr(10);
                        $message .= 'Stop Loss Coeff. 2 : ' . chr(9) . $this->_stopLossDynCoeff2 . chr(10);
                        $message .= 'Step size : '          . chr(9) . $this->_stepSize . ' décimales' . chr(10) . chr(10);
                    }

                    error_log($message, 3, $this->_logFileName);
                    $this->telegramMsg($message);
                }
            }
        } else {
            return 'Utilisateur inconnu !';
        }
    }


    /**
     * Récupération du nombre de décimales pour le market
     */
    private function stepSize($marketName=null)
    {
        $marketNull = false;
        if (is_null($marketName)) {
            $marketName = $this->_marketName;
            $marketNull = true;
        }

        $exchangeInfo = $this->_apiBinance->exchangeInfo();

        if (isset($exchangeInfo['code'])) {

            $message  = '___ Method stepSize ERROR ___' . chr(10) . chr(10);
            $message .= 'Code : ' . $order['code'] . chr(10);
            $message .= 'Message : ' . $order['msg'] . chr(10) . chr(10);

            error_log($message, 3, $this->_logFileName);
            $this->telegramMsg($message);

        } else {

            $exchangeInfo = $exchangeInfo['symbols'];

            foreach ($exchangeInfo as $v) {

                if (isset($v['symbol']) && $v['symbol'] == $marketName) {
                    $stepSize = $v['filters'][1]['stepSize'];

                    if ($stepSize == 1) {
                        $nbDecimals = 0;
                    } else {
                        $nbDecimals = strpos($stepSize, '1') - 1;
                    }

                    if ($marketNull === true) {
                        $this->_stepSize = $nbDecimals;
                        break;
                    } else {
                        return $nbDecimals;
                    }
                }
            }
        }
    }


    /**
     * Durée d'une bougie en secondes
     */
    private function deltaSecond()
    {
        switch ($this->_unit) {
            case 'MINUTE'   : $this->_deltaSecond = $this->_interval * 60;     break;
            case 'HOUR'     : $this->_deltaSecond = $this->_interval * 3600;   break;
        }
    }


    /**
     * Tendance : détection des périodes baissières et haussières
     */
    private function detectTrend()
    {
        $trend = 'bullish';

        // Moyenne de l'évolution du dernier cycle d'Ema Slow ou Ema Med < Ema Slow
        if ($this->_emaSlow[6] < $this->_emaSlow[5] || $this->_emaMed[6] < $this->_emaSlow[6]) {
            $trend = 'bearish';
        }

        // Récupération de la liste des vérifications imposées de périodes si le market est bullish
        if ($trend == 'bullish') {

            $req = "SELECT          a.market_interval

                    FROM            bin_bullish_int     a

                    INNER JOIN      bin_bullish_link    b
                    ON              a.id = b.id_interval

                    WHERE           b.id_profil = :id_profil";

            $sql = $this->_dbh->prepare($req);
            $sql->execute(array( ':id_profil' => $this->_idProfil ));

            while ($res = $sql->fetch()) {

                $market_interval = $res->market_interval;

                switch ($market_interval)
                {
                    case 'M1'  : $interval = 1;  $unit = 'MINUTE'; break;
                    case 'M3'  : $interval = 3;  $unit = 'MINUTE'; break;
                    case 'M5'  : $interval = 5;  $unit = 'MINUTE'; break;
                    case 'M15' : $interval = 15; $unit = 'MINUTE'; break;
                    case 'M30' : $interval = 30; $unit = 'MINUTE'; break;
                    case 'M60' : $interval = 60; $unit = 'MINUTE'; break;
                }

                try {

                    // Vérification des EMA
                    $ema = \cryptos\trading\ema::get3Ema(
                        $this->_nameExBDD,                  // Nom de la base de données
                        $this->_marketTable,                // Nom de la table
                        $unit,                              // Unité de temps
                        $interval,                          // Interval de temps
                        $this->_emaSlowCandles,             // Nombre de bougies pour le calcul de la Ema Slow
                        $this->_emaMedCandles,              // Nombre de bougies pour le calcul de la Ema Med
                        $this->_emaFastCandles,             // Nombre de bougies pour le calcul de la Ema Fast
                        7,                                  // Nombre de résultats souhaités
                        'fixe',                             // Calcul des clôtures sur un tableau 'fixe' ou 'glissant'
                        0                                   // Pas de retour d'un dataSet des tickers
                    );

                    if (!isset($ema['ema'][$this->_emaSlowCandles]) || !isset($ema['ema'][$this->_emaMedCandles])) {

                        $trend = '-';
                        break;

                    } else {

                        $emaSlow = $ema['ema'][$this->_emaSlowCandles];
                        $emaMed  = $ema['ema'][$this->_emaMedCandles];

                        // Moyenne de l'évolution du dernier cycle d'Ema Slow ou Ema Med < Ema Slow
                        if ($emaSlow[6] < $emaSlow[5] || $emaMed[6] < $emaSlow[6]) {
                            $trend = 'bearish';
                            break;
                        }
                    }

                } catch (\Exception $e) {

                    $message = $e->getMessage();

                    error_log($message, 3, $this->_logFileName);
                    $this->telegramMsg($message);
                }
            }
        }

        // Mise à jour de la tendance en cas de changement
        if (empty($this->_changeTrend) || $this->_changeTrend != $trend) {
            $this->_changeTrend = $trend;
        }

        return $trend;
    }

    /**
     * Instance de l'API Telegram
     */
    private function BotApiTelegram()
    {
        try {
            set_time_limit(2);
            $this->_botTelegram = new \TelegramBot\Api\BotApi($this->_telegram_token);
        } catch (\Exception $e) {
            $message  = '___ API Telegram Offline ___' . chr(10) . chr(10);
            $message .= 'Erreur : ' . $e . chr(10);
            error_log($message, 3, $this->_logFileName);
        }

        set_time_limit(0);
    }


    /**
     * Envoyer des messages avec Telegram
     */
    private function telegramMsg($msg)
    {
        try {
            set_time_limit(2);
            if ($this->_telegram_activ == 1) {
                $this->_botTelegram->sendMessage($this->_telegram_id, $msg);
            }
        } catch (\Exception $e) {
            $message  = '___ Telegram Problem ___' . chr(10) . chr(10);
            $message .= 'Erreur : ' . $e . chr(10);
            error_log($message, 3, $this->_logFileName);
        }

        set_time_limit(0);
    }


    /**
     * Vérification de l'existence d'un profil de log pour cet utilisateur
     */
    private function checkExistViewLog()
    {
        $req = "SELECT id FROM bin_ema_spread_logs WHERE profilName = :profilName";
        $sql = $this->_dbh->prepare($req);
        $sql->execute(array( ':profilName' => $this->_user ));

        if ($sql->rowCount() == 0) {

            $req = "INSERT INTO bin_ema_spread_logs (profilName, marketName, date_crea, date_modif, activ) VALUES (:profilName, :marketName, NOW(), NOW(), 1)";
            $sql = $this->_dbh->prepare($req);
            $sql->execute(array(
                ':profilName' => $this->_user,
                ':marketName' => $this->_marketName,
            ));
        }
    }


    /**
     * Désactivation de l'affichage des logs pour ce user
     */
    private function stopViewLog()
    {
        $req = "UPDATE  bin_ema_spread_logs

                SET     activ       = 0,
                        marketName  = :marketName,
                        date_modif  = NOW()

                WHERE   profilName = :profilName";

        $sql = $this->_dbh->prepare($req);
        $sql->execute(array(
            ':profilName' => $this->_user,
            ':marketName' => $this->_marketName,
        ));
    }


    /**
     * Mise à jour de logs de cet utilisateur
     */
    private function majViewLog($trend, $rsi, $ticker, $emaSlow, $emaMed, $emaFast, $emaSpread, $result)
    {
        $req = "UPDATE      bin_ema_spread_logs

                SET         marketName  = :marketName,
                            trend       = :trend,
                            rsi         = :rsi,
                            ticker      = :ticker,
                            ema_slow    = :emaSlow,
                            ema_med     = :emaMed,
                            ema_fast    = :emaFast,
                            ema_spread  = :emaSpread,
                            result      = :result,
                            date_modif  = NOW(),
                            activ       = 1

                WHERE       profilName  = :profilName";

                $sql = $this->_dbh->prepare($req);
                $sql->execute(array(
                    ':marketName'   => $this->_marketName,
                    ':trend'        => $trend,
                    ':rsi'          => $rsi,
                    ':ticker'       => $ticker,
                    ':emaSlow'      => $emaSlow,
                    ':emaMed'       => $emaMed,
                    ':emaFast'      => $emaFast,
                    ':emaSpread'    => $emaSpread,
                    ':result'       => $result,
                    ':profilName'   => $this->_user,
                ));
    }
}
