<?php
namespace cryptos\cli\trading\bittrex;

/**
 * Bot de trading se basant sur l'analyse de la RSI
 *
 * @author Daniel Gomes
 */
class botRSI
{
    /**
	 * Attributs
	 */
    private $_exchange = "bittrex";             // Nom de l'exchange
    private $_profilName;                       // Nom du profil utilisateur

    private $_profitTrailerPath;                // Chemin vers la configuration de Profit Trailer

    private $_dbh;                              // Instance PDO
    private $_dbhCryptos;                       // Instance PDO de la base de données contenant les configurations utilisateur des Bot

    private $_nameExBDD;                        // Nom de la base de données de l'Exchange
    private $_tablesList;                       // Liste des tables de market en BDD

    private $_bddBot = 'cryptos_bots';          // Nom de la BDD pour le bot
    private $_tradingAPI;                       // Instance Trading API
    private $_openOrderList;                    // Liste des ordres ouverts

    private $_balanceBTC;                       // Balances du portefeuille BTC
    private $_balances;                         // Balances de portefeuilles
    private $_countDCA;                         // Nombre de DCA en cours
    private $_tableMarketDCA;                   // Liste des tableMarket du DCA

    private $_prefixeTable = 'market_';         // Préfixe des tables de market
    private $_botCryptoRef = 'BTC';             // Crypto de référence utilisée pour trader

    private $_colorCli;                         // Gestion des couleurs en interface CLI

    private $_timeInit;                         // Permet de stocker le démarrage d'un tour pour en calculer le temps
    private $_timeEnd;                          // Permet de stocker la fin d'un tour pour en calculer le temps

    private $_infosBot;                         // Renvoie les informations de suivi en mode CLI

    private $_crypt;                            // Chiffrement

    /**
     * Configuration du Bot
     */
    private $_activBotRSI;                      // Activation / Désactivation du bot RSI
    private $_maxCost;                          // Mise max pour chaque trade
    private $_maxNbDCA;                         // Nombre maximum de trades dans le DCA

    /**
     * Déclencheurs RSI
     */
    private $_rsiDelta;                         // Delta : durée de la chandelle pour le calcul de la RSI
    private $_rsiUnite;                         // Unité : durée de la chandelle pour le calcul de la RSI

    private $_rsiPeriod;                        // RSI : Nombre de période pour le caclul
    private $_rsiNbRes;                         // Nombre de retours RSI (Bougies analysées)

    private $_rsi_marketHistory;                // Gestion de la RSI couplée aux informations du marketHistory

    private $_rsiPalierPct_1;                   // 2ème palier RSI
    private $_rsiPalierDiff_1;                  // Diff entre le palier de 30% et la bougie n-2

    private $_rsiPalierPct_2;                   // 2ème palier RSI
    private $_rsiPalierDiff_2;                  // Diff entre le 2ème palier et la bougie n-2

    private $_rsiPalierPct_3;                   // 3ème palier RSI
    private $_rsiPalierDiff_3;                  // Diff entre le 3ème palier et la bougie n-2

    private $_rsiPalierPct_4;                   // 4ème palier RSI
    private $_rsiPalierDiff_4;                  // Diff entre le 4ème palier et la bougie n-2

    private $_rsiPalierPct_5;                   // 5ème palier RSI
    private $_rsiPalierDiff_5;                  // Diff entre le 5ème palier et la bougie n-2

    /**
     * Déclencheurs de Volume du marketHistory
     */
    private $_analysedUniteTime;                // Volume analysé sur x MINUTE|HOUR (max 2 HOUR)
    private $_analysedDeltaTime;                // Nombre d'unités analysés
    private $_analysedVolBTC;                   // Nombre de Bitcoin échangés attendus dans la période analysée
    private $_volBTC24h;                        // Volume du marché sur 24 heures

    /**
     * Analyse de l'orderBook
     */
    private $_maxBuySpread;                     // Ecart en pourcentage Max entre le bid le plus haut et l'ask le plus haut

    /**
     * White list
     */
    private $_activ_whiteList;
    private $_whiteList;

    /**
     * Black List
     */
    private $_activ_blackList;
    private $_blackList;

    /**
     * Clé API de connexion à l'exchange - Attention, il est important de désactiver l'option 'Withdraw'
     */
    private $_trading_apiKey;
    private $_trading_apiSecret;


    /**
	 * Constructeur
	 */
	public function __construct()
	{
        // Nom de la base de données de l'Exchange
        $this->_nameExBDD = 'cryptos_ex_' . $this->_exchange;

        // Instance PDO
        $this->_dbh = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Instance PDO de la base de données contenant les configurations utilisateur des Bot
        $this->_dbhCryptos = \core\dbSingleton::getInstance($this->_bddBot);

        // Chiffrement
        $this->_crypt = new \core\crypt();

        // Gestion des couleurs en interface CLI
        $this->_colorCli = new \core\cliColorText();
    }


    /**
     * Lancement de la boucle sans fin
     */
    public function run()
    {
        for ($i=0; $i==$i; $i++) {
        //for ($i=0; $i<1; $i++) {
            $this->actions();

            // Attente souhaitée : 1 secondes
            $timeExec = ($this->_timeEnd - $this->_timeInit) * 1000000;
            $uSleep = 2000000 - $timeExec;

            if ($uSleep > 0) {
                usleep($uSleep);
            }
        }
    }


    /**
     * Classement des markets d'un exchange par évolution des prix dans un interval de temps défini
     *
     * @param       integer     $interval       Interval de temps
     *
     * @return      array
     */
    public function actions()
    {
        // Chargement de la configuration utilisateur
        $this->loadConf();

        // Information de suivi du bot en CLI
        $this->infosBot();

        // Vérification de l'activation du bot RSI
        $switchRSI = $this->switchRSI();

        if ($switchRSI === true) {

            // Récupération de la liste des tables de market en BDD
            $this->tableList();

            // Instance Trading API
            $this->_tradingAPI = new \cryptos\api\bittrex\tradingAPI($this->_trading_apiKey, $this->_trading_apiSecret, $this->_bddBot);

            $checkCurrentTrades = $this->currentTrades();

            $checkOpenOrder = $this->getOpenOrders();

            /*
            foreach($this->_balances as $balance) {
            $this->_infosBot .= json_encode($balance) . chr(10);
            }
            */

            $this->_infosBot .= chr(10) . chr(10) . ' marketName in DCA : ' . chr(10) . chr(10);
            $i=1;
            foreach($this->_tableMarketDCA as $tableMarket) {
                $this->_infosBot .= ' ' . $i . ' : ' . $this->recupMarketName($tableMarket) . chr(10);
                $i++;
            }

            // La vérification des trades dans le DCA s'est correctement déroulée
            if ($checkCurrentTrades !== false) {

                // Le nombre maximum de trades dans le DCA n'est pas atteint
                if ($this->_countDCA < $this->_maxNbDCA) {

                    // Boucle sur les tables de market de l'exchange
                    foreach ($this->_tablesList as $tableMarket) {

                        // On vérifie s'il y a déjà un trade avec ce market dans le DCA
                        if (! in_array($tableMarket, $this->_tableMarketDCA)) {

                            // Récupération des résultats RSI
                            $rsi = \cryptos\trading\rsi::getRSI($this->_nameExBDD, $tableMarket, $this->_rsiUnite, $this->_rsiDelta, $this->_rsiPeriod, $this->_rsiNbRes);

                            if ($rsi['result'] === true && end($rsi['rsi']) < ($this->_rsiPalierPct_1 + 10) && end($rsi['rsi']) != 0) {

                                $infosMarket = $rsi['table'] . ' -> ' . round(end($rsi['rsi']), 2) . '%';

                                if  (end($rsi['rsi']) >= $this->_rsiPalierPct_1 && end($rsi['rsi']) < ($this->_rsiPalierPct_1 + 10)) {

                                    $this->_infosBot .= chr(10) . $this->_colorCli->getColor(' Market : ' . $infosMarket, 'light_gray');

                                } else {

                                    $this->_infosBot .= chr(10);
                                    $this->_infosBot .= chr(10) . $this->_colorCli->getColor(' Market : ' . $infosMarket, 'light_green');

                                    // Nous sommes sous les 30% : analyse d'un possible d'achat
                                    $this->checkPosition($rsi['rsi'], $rsi['table']);
                                    $this->_infosBot .= chr(10);
                                }
                            }
                        }
                    }

                } else {

                    $this->_infosBot .= chr(10) . $this->_colorCli->getColor(' Il y a déjà au moins ' .$this->_maxNbDCA . ' trades en cours d\'exécution dans le DCA !' , 'light_red');
                }

            } else {

                $this->_infosBot .= chr(10) . $this->_colorCli->getColor(' Impossible de récupérer les trades en cours d\'exécution !' , 'light_red');
            }
        }

        if (PHP_SAPI === 'cli') {
            system('clear');
        }

        // Affichage des information du cycle
        echo $this->_infosBot;
    }


    /**
     * Chargement de la configuration utilisateur
     */
    private function loadConf()
    {
        if (! isset($_SERVER['argv'][1])) {

            $msg = chr(10) . 'Merci de préciser le profil utilisateur à charger !' . chr(10) . chr(10);
            die($msg);

        } else {

            $argv = $_SERVER['argv'];
            $this->_profilName = $argv[1];

            $req = "SELECT * FROM bot_buy_rsi WHERE profilName = :profilName";
            $sql = $this->_dbhCryptos->prepare($req);
            $sql->execute(array(
                ':profilName' => $this->_profilName,
            ));

            if ($sql->rowCount() == 0) {

                $msg = chr(10) . 'Le profil ' . $this->_profilName . ' n\'existe pas !' . chr(10) . chr(10);
                die($msg);

            } else {

                $res = $sql->fetch();

                // API
                $this->_trading_apiKey      = $this->_crypt->decrypt($res->trading_apiKey);
                $this->_trading_apiSecret   = $this->_crypt->decrypt($res->trading_apiSecret);

                $this->_profitTrailerPath   = $res->profitTrailerPath;

                // Global
                $this->_activBotRSI         = $res->activ_bot_RSI;
                $this->_maxCost             = $res->maxCost;
                $this->_maxNbDCA            = $res->maxNbDCA;

                // Check RSI
                $this->_rsiDelta            = $res->rsiDelta;
                $this->_rsiUnite            = $res->rsiUnite;
                $this->_rsiPeriod           = $res->rsiPeriod;
                $this->_rsiNbRes            = $res->rsiNbRes;

                $this->_rsi_marketHistory   = $res->rsi_marketHistory;

                $this->_rsiPalierPct_1      = $res->rsiPalierPct_1;
                $this->_rsiPalierDiff_1     = $res->rsiPalierDiff_1;

                $this->_rsiPalierPct_2      = $res->rsiPalierPct_2;
                $this->_rsiPalierDiff_2     = $res->rsiPalierDiff_2;

                $this->_rsiPalierPct_3      = $res->rsiPalierPct_3;
                $this->_rsiPalierDiff_3     = $res->rsiPalierDiff_3;

                $this->_rsiPalierPct_4      = $res->rsiPalierPct_4;
                $this->_rsiPalierDiff_4     = $res->rsiPalierDiff_4;

                $this->_rsiPalierPct_5      = $res->rsiPalierPct_5;
                $this->_rsiPalierDiff_5     = $res->rsiPalierDiff_5;

                // Check Trade History
                $this->_analysedUniteTime   = $res->analysedUniteTime;
                $this->_analysedDeltaTime   = $res->analysedDeltaTime;
                $this->_analysedVolBTC      = $res->analysedVolBTC;
                $this->_volBTC24h           = $res->volBTC24h;

                // Check Order Book
                $this->_maxBuySpread        = $res->maxBuySpread;

                // White list
                $this->_activ_whiteList     = $res->activ_whiteList;
                $this->_whiteList           = $res->whiteList;

                // Black List
                $this->_activ_blackList     = $res->activ_blackList ;
                $this->_blackList           = $res->blackList;
            }
        }
    }


    /**
     * Test de la viabilité d'une position avant de procéder à un achat
     */
    private function checkPosition($rsi, $tableMarket)
    {
        // Vérification de la force de la baisse de la RSI en comparant avec le cycle n-2
        if ($this->_rsi_marketHistory == 0) {
            $check = $this->analyseRSI($rsi);

        // Suivi de la RSI avec un achat conditionné par le marketHistory
        } else {
            $check = $this->analyseRSI_marketHistory($rsi, $tableMarket);
        }

        // Exclusion des marchés blacklistés
        if ($check == true) {
            $check = $this->blackList($tableMarket);
        }

        // Vérification des volumes échangés ces X dernières minutes
        if ($check == true) {
            $check = $this->analyseVolMh($tableMarket);
        }

        // Vérification d l'écart entre le 'bid' le plus haut et le 'ask' le plus bas dans l'orderBook
        if ($check == true) {
            $check = $this->analyseVolOb($tableMarket);
        }

        // Si tous les tests sont positifs, on achète
        if ($check === true) {
            $this->buy($tableMarket);
        }
    }


    /**
     * Activation / Désactivation du bot RSI
     */
    private function switchRSI()
    {
        $check = true;

        if ($this->_activBotRSI == 0) {
            $this->_infosBot .= chr(10) . $this->_colorCli->getColor(' Le bot RSI est désactivé !', 'light_cyan');
            $check = false;
        }

        return $check;
    }

    /**h
     * On test si le market n'est pas balcklisté
     */
    private function blackList($tableMarket)
    {
        $check = true;

        if ($this->_activ_blackList == 1) {

            $blackList = str_replace(' ',  '',  $this->_blackList);

            $blackList = str_replace(',',  chr(10), $blackList);
            $blackList = str_replace(';',  chr(10), $blackList);
            $blackList = str_replace('|',  chr(10), $blackList);

            $blackList = str_replace('-',  '_', $blackList);

            $blackList = explode(chr(10), $blackList);

            $tableBlackList = array();
            foreach($blackList as $marketName) {
                $tableBlackList[] = 'market_' . strtolower($marketName);
            }

            if (in_array($tableMarket, $tableBlackList)) {
                $infosBlackList = ' Market blacklisté !';
                $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosBlackList, 'red');
                $check = false;
            }
        }

        return $check;
    }


    /**
     * Récupération des noms des tables de market de la White List
     */
    private function tableInWhiteList()
    {
        $tableInWhiteList = array();

        $req = "SELECT whitelist FROM bot_buy_rsi WHERE profilName = :profilName";
        $sql = $this->_dbhCryptos->prepare($req);
        $sql->execute(array(
            ':profilName' => $this->_profilName,
        ));

        if ($sql->rowCount() > 0) {
            $res = $sql->fetch();

            $whitelist = str_replace(' ',  '',  $res->whitelist);

            $whitelist = str_replace(',',  chr(10), $whitelist);
            $whitelist = str_replace(';',  chr(10), $whitelist);
            $whitelist = str_replace('|',  chr(10), $whitelist);

            $whitelist = str_replace('-',  '_', $whitelist);

            $whitelist = explode(chr(10), $whitelist);

            foreach($whitelist as $marketName) {
                $tableInWhiteList[] = 'market_' . strtolower($marketName);
            }
        }

        return $tableInWhiteList;
    }


    /**
     * Vérification de la force de la baisse de la RSI par palier en comparant avec le cycle n-2
     */
    private function analyseRSI($rsi)
    {
        $check = true;

        if (($rsi[0] > (end($rsi) + $this->_rsiPalierDiff_1))
        || ((end($rsi) < $this->_rsiPalierPct_2) && ($rsi[0] > (end($rsi) + $this->_rsiPalierDiff_2)))
        || ((end($rsi) < $this->_rsiPalierPct_3) && ($rsi[0] > (end($rsi) + $this->_rsiPalierDiff_3)))
        || ((end($rsi) < $this->_rsiPalierPct_4) && ($rsi[0] > (end($rsi) + $this->_rsiPalierDiff_4)))
        || ((end($rsi) < $this->_rsiPalierPct_5) && ($rsi[0] > (end($rsi) + $this->_rsiPalierDiff_5)))) {

            $infosRSI = ' Check RSI : OK (n-2 = ' . $rsi[0] . '%)';
            $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosRSI, 'light_blue');

        } else {
            $infosRSI = ' Check RSI : NOK (n-2 = ' . $rsi[0] . '%)';
            $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosRSI, 'red');
            $check = false;
        }

        return $check;
    }


    /**
     * Vérification de la baisse de la RSI
     * Signal d'achat avec le marketHistory
     */
    private function analyseRSI_marketHistory($rsi, $tableMarket)
    {
        if (end($rsi) > 30) {

            $check = false;

        } else {

            $table_mh = str_replace('market_', 'mh_', $tableMarket);

            try {

                // Récupération du volume échangé les 30 dernières minutes
                $req = "SELECT COUNT(total) AS volBTC FROM $table_mh WHERE date_crea >= DATE_ADD(NOW(), INTERVAL -30 MINUTE)";
                $sql = $this->_dbh->query($req);
                $res = $sql->fetch();
                $volBTC_30min = $res->volBTC;

                // Récupération du volume d'achat les 15 dernières secondes
                $req = "SELECT COUNT(total) AS volBTC FROM $table_mh WHERE orderType = 'BUY'  AND date_crea >= DATE_ADD(NOW(), INTERVAL -30 SECOND)";
                $sql = $this->_dbh->query($req);
                $res = $sql->fetch();
                $volBuyBTC_30s = $res->volBTC;

                // Récupération du volume de ventes les 15 dernières secondes
                $req = "SELECT COUNT(total) AS volBTC FROM $table_mh WHERE orderType = 'SELL' AND date_crea >= DATE_ADD(NOW(), INTERVAL -30 SECOND)";
                $sql = $this->_dbh->query($req);
                $res = $sql->fetch();
                $volSellBTC_30s = $res->volBTC;

                // Le volume d'achat doit au moins être à 1 / 240 du volume échangé les 30 dernières minutes
                // 240 : 30s * 2 = 1min -> vol 30 minutes / 60 pour equiv. 30 secondes - 60 * 2 = 120 : cumum Sell et Buy
                if ( ($volBuyBTC_30s >= ($volBTC_30min / 120))  &&  ($volBuyBTC_30s >= ($volSellBTC_30s * 2)) ) {

                    $infosRSI = ' Check RSI / marketHistory - volume Buy 30s OK : Buy ' . $volBuyBTC_30s . ' BTC | Sell ' . $volSellBTC_30s . ' BTC';
                    $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosRSI, 'light_blue');
                    $check = true;

                } else {

                    $infosRSI = ' Check RSI / marketHistory - volume Buy 30s trop faible : Buy ' . $volBuyBTC_30s . ' BTC | Sell ' . $volSellBTC_30s . ' BTC';
                    $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosRSI, 'red');
                    $check = false;
                }

            } catch (\Exception $e) {

                $infosVolBTC = " La table '$table_mh' n'existe plus (volume insuffisant)";
                $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosVolBTC, 'red');
                $check = false;
            }
        }

        return $check;
    }


    /**
     * Vérification des volumes échangés ces X dernières minutes
     */
    private function analyseVolMh($tableMarket)
    {
        $table_mh = str_replace('market_', 'mh_', $tableMarket);

        // On vérifie si nous avons bien le volume souhaité sur une courte durée précédant l'achat
        $analysedUniteTime = $this->_analysedUniteTime;
        $analysedDeltaTime = $this->_analysedDeltaTime;
        $analysedVolBTC    = $this->_analysedVolBTC;

        try {

            $req = "SELECT COUNT(total) AS volBTC FROM $table_mh WHERE date_crea >= DATE_ADD(NOW(), INTERVAL -$analysedDeltaTime $analysedUniteTime)";
            $sql = $this->_dbh->query($req);

            if ($sql->rowCount() > 0) {
                $res = $sql->fetch();
                $volBTC = $res->volBTC;

                if ($volBTC >= $analysedVolBTC) {
                    $infosVolBTC = " Check Volume last $analysedDeltaTime $analysedUniteTime : OK ($volBTC BTC)";
                    $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosVolBTC, 'white');
                } else {
                    $infosVolBTC = " Check Volume last  $analysedDeltaTime $analysedUniteTime : NOK ($volBTC BTC)";
                    $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosVolBTC, 'red');
                    return false;
                }
            } else {
                return false;
            }

        } catch (\Exception $e) {

            $infosVolBTC = " La table '$table_mh' n'existe plus (volume insuffisant)";
            $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosVolBTC, 'red');
            return false;
        }

        return true;
    }


    /**
     * Vérification d l'écart entre le 'bid' le plus haut et le 'ask' le plus bas dans l'orderBook
     */
    private function analyseVolOb($tableMarket)
    {
        $check = true;

        $table_ob = str_replace('market_', 'ob_', $tableMarket);

        $req = "SELECT jsonOrderBook FROM $table_ob ORDER BY id DESC LIMIT 1";
        $sql = $this->_dbh->query($req);

        if ($sql->rowCount() > 0) {

            $res = $sql->fetch();
            $jsonOrderBook = $res->jsonOrderBook;

            $range = $this->_maxBuySpread / 2;
            $resOrderBook = $this->analyseOrderBookRange($jsonOrderBook, $range);

            if ($resOrderBook['bids'] > 0 || $resOrderBook['asks'] > 0) {
                $infosMaxBuySpread = " Check Max Buy Spread : OK";
                $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosMaxBuySpread, 'light_cyan');
            } else {
                $infosMaxBuySpread = " Check Max Buy Spread : NOK";
                $this->_infosBot .= chr(10) . $this->_colorCli->getColor($infosMaxBuySpread, 'red');
                $check = false;
            }
        } else {
            $check = false;
        }

        return $check;
    }


    /**
     * Analyse des volumes d'un orderBook dans le range défini
     *
     * @param       string      $jsonOrderBookOld
     * @param       integer     $range
     *
     * @return      array
     */
    private function analyseOrderBookRange($jsonOrderBook, $range)
    {
        $orderBook = json_decode($jsonOrderBook);

        if (is_object($orderBook)) {
            // Bid le plus haut
            $bids = $orderBook->bids;

            // Ask le plus bas
            $asks = $orderBook->asks;

            $bidsCheck = true;
            $asksCheck = true;

            if (isset($bids[0][0])) {
                $bidRateMax = floatval($bids[0][0]);
            } else {
                $bidsCumul = 0;
                $bidsCheck = false;
            }

            if (isset($asks[0][0])) {
                $askRateMin = floatval($asks[0][0]);
            } else {
                $asksCumul = 0;
                $asksCheck = false;
            }

            if ($bidsCheck === true && $asksCheck === true) {

                // Calcul du middle entre le le bid le plus haut et l'ask le plus bas
                $middle = ($bidRateMax + $askRateMin) / 2;

                // Bid le plus bas en fonction du range choisi
                $bidRateMin = $middle - ($middle / 100) * $range;

                // Ask le plus haut en fonction du range choisi
                $askRateMax = $middle + ($middle / 100) * $range;

                // Cumul des bids en respectant le range
                $bidsCumul=0;
                foreach ($bids as $bid) {
                    $bidRate     = floatval($bid[0]);
                    $bidQuantity = floatval($bid[1]);

                    if ($bidRate > $bidRateMin) {
                        $bidsCumul += $bidQuantity * $bidRate;
                    }
                }

                // Cumul des asks en respectant le range
                $asksCumul=0;
                foreach ($asks as $ask) {
                    $askRate     = floatval($ask[0]);
                    $askQuantity = floatval($ask[1]);

                    if ($askRate < $askRateMax) {
                        $asksCumul += $askQuantity * $askRate;
                    }
                }
            }
        } else {
            $bidsCumul = 0;
            $asksCumul = 0;
        }

        return array(
            'bids' => $bidsCumul,
            'asks' => $asksCumul,
            'all'  => $bidsCumul + $asksCumul,
        );
    }


    /**
     * Tous les tests sur le marché son positifs : achat !
     *
     * @param       string      $tableMarket        Nom de la table du marché
     */
    private function buy($tableMarket)
    {
        // Récupération des ordres d'achat en répondant aux ordres de ventes
        $newOrderList = $this->prepareOrderList($tableMarket);

        // Exécution des ordres d'achat
        foreach ($newOrderList as $newOrder) {

            $marketName = $newOrder['marketName'];
            $qte        = $newOrder['qte'];
            $rate       = $newOrder['rate'];

            // L'ordre est déjà ouvert, on ne le relance pas
            if ( isset($this->_openOrderList[$marketName])
            && $this->_openOrderList[$marketName]['qte']  == $newOrder['qte']
            && $this->_openOrderList[$marketName]['rate'] == $newOrder['rate']) {
                continue;
            }

            $buyLimit = $this->_tradingAPI->buyLimit($marketName, $qte, $rate);

            // echo chr(10) . ' openORder ' . $marketName . ' : ' . json_encode($this->_openOrderList[$marketName]);
            // echo chr(10) . ' order : ' . json_encode($newOrder);
            // echo chr(10) . ' buyLimit : ' . json_encode($buyLimit);
        }

        // Réinitialisation de la martingale dans Profit Trailer
        $url = 'https://trading.dpx.ovh/curl/initMarketMartingale.php';

        $postfieldsPT = array(
            'profilName'        => $this->_profilName,
            'profitTrailerPath' => $this->_profitTrailerPath,
            'marketName'        => $marketName,
        );

        \core\curl::curlPost($url, $postfieldsPT);
    }


    /**
     * Préparation des ordres d'achat en répondant aux ordres de ventes
     *
     * @param       string      $tableMarket        Nom de la table du marché
     * @return      array                           Liste des ordres à passer
     */
    private function prepareOrderList($tableMarket)
    {
        // Nom du market
        $marketName = $this->recupMarketName($tableMarket);

        // Récupération de l'orderBook le plus récent
        $getOrderBook = \cryptos\api\bittrex\getOrderBook::getOrderBook($marketName);
        $orderBook    = $this->JsonOrderBookModel($getOrderBook);

        // Récupération des asks
        $asks = $orderBook['asks'];

        // On achète en répondant aux ordres de ventes les plus avantageux
        $orders = array();

        foreach ($asks as $ask) {

            $askRate = floatval($ask[0]);
            $askQte  = floatval($ask[1]);

            $askTotal = $askRate * $askQte;

            $mise = $this->_maxCost;

            // L'ordre permet d'absorber la mise (restante)
            if ($askTotal >= $mise) {

                $orderList[] = array(
                    'marketName' => $marketName,
                    'qte'        => ($mise / $askRate),
                    'rate'       => $askRate,
                );

                return $orderList;

            // Cet ordre ne permettra pas d'absorber la mise à lui seul, l'intégralité de son capital sera utilisé
            } else {

                $orderList[] = array(
                    'marketName' => $marketName,
                    'qte'        => $askQte,
                    'rate'       => $askRate,
                );

                $mise -= $askTotal;
            }
        }

        return $orderList;
    }


    /**
     * Récupération des ordres ouverts
     */
    private function getOpenOrders()
    {
        $this->_openOrderList = array();
        $getOpenOrders = $this->_tradingAPI->getOpenOrders();

        if (count($getOpenOrders->result) > 0) {
            $this->_infosBot .= chr(10) . ' Liste des ordres ouverts : ' . chr(10);

            foreach($getOpenOrders->result as $openOrder) {

                $this->_openOrderList[$openOrder->Exchange] = array(
                    'qte'  => $openOrder->Quantity,
                    'rate' => $openOrder->Limit,
                );

                $this->_infosBot .= chr(10) . ' ' . $openOrder->Exchange . ' | Qte : ' . $openOrder->Quantity . ' - Rate : ' . $openOrder->Limit;
            }
        }
    }


    /**
     * Mise en forme du json pour qu'il respecte un standard multiplateformes
     *
     * {"bids":[["4410.00000000", 0.49794082], == {"Bids":[[rate, quantity],
     * {"asks":[["4410.00000000", 0.49794082], == {"asks":[[rate, quantity],
     *
     * @param       string      $getOrderBook       OrderBook retournée par l'API
     */
    private function JsonOrderBookModel($getOrderBook)
    {
        $orderBookStd = array();

        if ($this->_exchange == 'bittrex') {

            foreach ($getOrderBook->buy as $key => $val) {
                $orderBookStd['bids'][$key][0] = $val->Rate;
                $orderBookStd['bids'][$key][1] = $val->Quantity;
            }

            foreach ($getOrderBook->sell as $key => $val) {
                $orderBookStd['asks'][$key][0] = $val->Rate;
                $orderBookStd['asks'][$key][1] = $val->Quantity;
            }

            return $orderBookStd;
        }
    }


    /**
     * Récupération de la liste des tables des markets 'market_%' en BDD
     * ayant pour monnaie de référence '$this->_botCryptoRef'
     */
    private function tableList()
    {
        $vol24h = $this->_volBTC24h;

        $prefixeTable = $this->_prefixeTable . strtolower($this->_botCryptoRef);
        $bddExchange  = $this->_nameExBDD;

        $this->_tablesList = array();

        $req = "SELECT  table_name AS exTable

                FROM    information_schema.tables

                WHERE   table_name LIKE ('$prefixeTable%')
                AND     table_schema = '$bddExchange'";

        $sql = $this->_dbh->query($req);

        $tableInWhiteList = $this->tableInWhiteList();

        while ($res = $sql->fetch()) {

            // Nom de la table
            $tableMarket = $res->exTable;

            // Récupération du marketName
            $marketName = $this->recupMarketName($tableMarket);

            // On utilise ou recalcul le volume 24h pour filtrer la recherche des markets
            $expCryptos = explode('-', $marketName);
            $cryptoREF  = $expCryptos[0];

            if ($cryptoREF != 'BTC') {

                $fiatCurrencies = array('USD', 'USDT', 'EUR');

                if (in_array($cryptoREF, $fiatCurrencies)) {

                    $table = 'market_' . strtolower($cryptoREF) . '_btc';

                    $req1 = "SELECT last FROM $table ORDER BY id DESC LIMIT 1";
                    $sql1 = $this->_dbh->query($req1);
                    $res1 = $sql1->fetch();

                    $checkVol24h = $vol24h * $res1->last;

                } else {

                    $table = 'market_btc_' . strtolower($cryptoREF);

                    $req1 = "SELECT last FROM $table ORDER BY id DESC LIMIT 1";
                    $sql1 = $this->_dbh->query($req1);
                    $res1 = $sql1->fetch();

                    $checkVol24h = $vol24h / $res1->last;
                }
            } else {
                $checkVol24h = $vol24h;
            }

            $reqVol = "SELECT baseVolume FROM $tableMarket ORDER BY id DESC LIMIT 1";
            $sqlVol = $this->_dbh->query($reqVol);
            $resVol = $sqlVol->fetch();

            $baseVolume = $resVol->baseVolume;

            if ($baseVolume >= $checkVol24h) {

                // Whitelist désactivée
                if ($this->_activ_whiteList == 0) {
                    $this->_tablesList[] = $tableMarket;

                // La Whitelist est activée, on vérifie si la table en fait partie
                } else {
                    if (in_array($tableMarket, $tableInWhiteList)) {
                        $this->_tablesList[] = $tableMarket;
                    }
                }
            }
        }
    }


    /**
     * Récupération de la liste des trades en cours
     */
    private function currentTrades()
    {
        $check = false;

        $getBalances = $this->_tradingAPI->getBalances();

        $this->_balances = array();

        if ($this->_tradingAPI !== false) {

            $getMarket = new \cryptos\api\bittrex\getMarket();
            $getMarketSummaries = $getMarket->getMarketSummaries();

            $allLast = array();

            foreach($getMarketSummaries as $marketSummary) {
                $allLast[$marketSummary->MarketName] = $marketSummary->Last;
            }

            $this->_countDCA  = 0;
            $this->_tableMarketDCA = array();

            foreach($getBalances as $balance) {

                $currency = $balance->Currency;
                $balance  = $balance->Balance;

                if ($currency != 'USDT') {
                    if ($currency == 'BTC') {

                        $this->_balanceBTC = $balance;

                    } else {


                        // Calcul de la balance en Bitcoin
                        $marketName = $this->_botCryptoRef . '-' . $currency;
                        $balanceBTC = $balance * $allLast[$marketName];

                        if ($balanceBTC > 0.0005) {

                            // Nom de la table du market
                            $this->_tableMarketDCA[] = 'market_' . strtolower($this->_botCryptoRef . '_' . $currency);
                            $this->_countDCA++;

                            $this->_balances[] = array(
                                'Currency'      => $currency,
                                'Balance'       => $balance,
                                'BalanceBTC'    => $balanceBTC,
                            );
                        }
                    }
                }
            }

            $check = true;
        }

        return $check;
    }


    /**
     * Récupération du marketName avec le nom de la table
     */
    private function recupMarketName($tableMarket)
    {
        // Récupération du marketName
        $marketName = explode($this->_prefixeTable, $tableMarket);
        $marketName = str_replace('_', '-', $marketName[1]);
        $marketName = strtoupper($marketName);

        return $marketName;
    }


    /**
     * Infomation de suivi du bot
     */
    private function infosBot()
    {
        // Affichage de suivi
        $hr = chr(10) . chr(10) . '________________________________' . chr(10);
        $hr = $this->_colorCli->getColor($hr, 'dark_gray');

        // Titre ---------------------------------------------------------------
        $texte  = chr(10);
        $texte .= $this->_colorCli->getColor(' Exchange : ' . ucfirst($this->_exchange), 'white') . chr(10);
        $texte .= $this->_colorCli->getColor(' Buy RSI for Profit Trailer', 'white') . chr(10) . chr(10);
        $texte .= $this->_colorCli->getColor(' Profil : ' . $this->_profilName, 'white') . chr(10) . chr(10);
        $texte .= $this->_colorCli->getColor(' ' . date('Y-m-d H:i:s'), 'yellow');

        // Durées d'exécution --------------------------------------------------
        $texte .= $hr;
        //$texte .= $this->_colorCli->getColor(' Durée : ' . round(($this->_timeEnd - $this->_timeInit), 3) . 's', 'light_gray') . chr(10) . chr(10);

        $this->_infosBot = $texte;
    }
}
