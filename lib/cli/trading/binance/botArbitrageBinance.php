<?php
namespace cryptos\cli\trading\binance;

/**
 * Bot de trading par arbitrage sur la plateforme Binance
 *
 * @author Daniel Gomes
 */
class botArbitrage
{
    /**
	 * Attributs
	 */
    private $_purge             = true;             // Boolean - Permet de terminer les trades en cours sans en lancer de nouveaux

    private $_leadsQuantity     = 1;                // Nombre de trades acceptés en simultané
    private $_maxMise           = 0.006;            // Mise maxi par trade

    private $_feesBuy           = 0.05;             // Frais de la plateforme pour un ordre d'achat
    private $_feesSell          = 0.05;             // Frais de la plateforme pour un ordre de vente

    private $_addBidPct         = 0.0;            // Pourcentage ajouté au Bid le plus haut
    private $_addAskPct         = 0.0;            // Pourcentage retiré au Ask le plus bas

    private $_replaceOrderBook  = 1;                // Si le nombre de lignes au dessus de l'ordre dans l'orderBook dépasse ce chiffre, l'ordre est annulé

    private $_seuilMini         = -100.0;          // Seuil de gains à atteindre avant d'acheter une devise
    private $_btcMinVolume      = 100;              // Volume minimum avant de se lancer dans un market

    private $_replaceOrderTime1 = 1;                // Replace Order : temps a attendre entre deux replaceOrder
    private $_replaceOrderTime2 = 15;               // Replace Order : temps a attendre si rien ne se passe avant de remonter l'ordre

    private $_reduceCoef        = 5;                // Réducteur de coefficient pour les replaceOrder

    private $_pctOrderBookBTC   = 80;               // Capacité d'absorption d'un market basé sur la monnaie référence BTC
    private $_pctOrderBookETH   = 85;               // Capacité d'absorption d'un market basé sur la monnaie référence ETH
    private $_pctOrderBookUSDT  = 85;               // Capacité d'absorption d'un market basé sur la monnaie référence USDT

    private $_dbh;                                  // Instance PDO
    private $_dbh2;                                 // Instance PDO - BDD de collecte

    private $_nameBDD       = 'crypto';             // Nom de la base de données du bot
    private $_nameExBDD     = 'cryptos_ex_binance'; // Nom de la base de données de l'exchange

    private $_prefixeTable  = 'market_';            // Préfixe des tables de market

    private $_tablesList;                           // Liste des tables de market en BDD

    private $_getMarket;                            // Instance de la classe cryptos\bittrex\market\getMarket
    private $_tradingAPI;                           // Instance de la classe cryptos\bittrex\trading\tradingAPI

    private $_marketSummaries;                      // Stockage des informations sur tous les markets
    private $_leadsPossibilities;                   // Stockage des leads possibles à effectuer
    private $_leadsIgnored;                         // Leads ignorés pour information

    private $_balance;                              // Balance du compte en BTC
    private $_availableBTC;                         // Nombre de bitcoin disponible pour effectuer des trades

    private $_br;                                   // <br> ou chr(10)
    private $_hr;                                   // <hr>

    private $_colorCli;                             // Gestion des couleurs en interface CLI


    /**
	 * Constructeur
	 */
	public function __construct()
	{
		// Instances PDO
        //$this->_dbh     = \core\dbSingleton::getInstance($this->_nameBDD);
        $this->_dbh2    = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Instance de la classe cryptos\bittrex\market\getMarket
        // $this->_getMarket   = new \cryptos\api\bittrex\getMarket();

        // Instance de la classe cryptos\bittrex\trading\tradingAPI
        //$this->_tradingAPI  = new \cryptos\api\bittrex\tradingAPI();

        // Gestion des couleurs en interface CLI
        $this->_colorCli    = new \core\cliColorText();

        // Récupération de la liste des tables de market en BDD
        $this->tableList();

        // Affichage adapté en CLI ou CGI
        $this->affCLIorCGI();
	}


    /**
     * Lancement de la boucle sans fin
     */
    public function run()
    {
        for ($i=0; $i==$i; $i++) {
            $this->analyse();

            // 0.4 secondes
            sleep(1);
        }
    }


    /**
     * Actions à mener à chaque tour de boucle
     */
    public function analyse()
    {
        // Relance des leads qui se sont stoppés avant la fin
        // $this->relanceLeads();

        // Suivi et lancement des étapes des leads en cours d'execution
        // $this->currentLeads();

        // Récupération de marketSummaries
        $this->getMarketSummaries();

        // Récupération des information sur le compte
        // $this->infosCompte();

        // Lecture des stratégies
        $this->strategies();

        // Trie des résultats
        $this->resultSort();

        // Lancement des nouveaux leads à effectuer
        // $this->newLead();

        // Affichage des possibilités d'achat et des informations sur le compte
        $this->infosGenerales();

        // Mise ajout des informations sur un lead
        // $this->majInfosLead();
    }


    /**
     * Récupération du solde du compte
     */
    private function infosCompte()
    {
        $getBalances = $this->_tradingAPI->getBalances();

        $this->_balance = 0;

        foreach($getBalances as $val) {

            if ($val->Currency == 'USDT') {

                $last_BTC = $this->_marketSummaries['USDT-BTC']['last'];
                $this->_balance += (1 / $last_BTC) * $val->Balance;

            } else {

                if ($val->Currency == 'BTC') {
                    $this->_availableBTC = $val->Balance;
                }

                $this->_balance += $val->Balance;
            }
        }
    }


    /**
     * Affichage des possibilités d'achat et des informations sur le compte
     */
    private function infosGenerales()
    {
        if (PHP_SAPI === 'cli') {
            system('clear');
        }

        $heure  = date('Y-m-d H:i:s');
        $texte  = $this->colorUI($heure, 'heure');

        $texte .= '<hr>';
        $texte .= $this->colorUI('Possibilités d\'achat : ' . count($this->_leadsPossibilities), 'title') . '<br><br>';

        $i=0;
        foreach ($this->_leadsPossibilities as $k=>$v) {
            $line   = $v['strategie'] . ' : ' . number_format($v['gain'], 2, '.', '') . '%';
            $expLine = explode('XXX', $line);
            $texte .= $this->colorUI($expLine[0], 'texte') . $this->colorUI($v['crypto'], 'good') . $this->colorUI($expLine[1], 'texte') . '<br>';
            $i++;

            if ($i==40) {
                break;
            }
        }

        $texte .= '<hr>';
        $texte .= $this->colorUI('Leads ignorés : ' . count($this->_leadsIgnored), 'error') . '<br><br>';
        foreach ($this->_leadsIgnored as $k=>$v) {
            $line   = $v['strategie'] . ' : ' . number_format($v['gain'], 2, '.', '') . '%';
            $expLine = explode('XXX', $line);
            $texte .= $this->colorUI($expLine[0], 'texte') . $this->colorUI($v['crypto'], 'good') . $this->colorUI($expLine[1], 'texte') . '<br>';
        }

        $texte .= '<hr>';
        $texte .= $this->colorUI('Infos sur le compte : ', 'title') . '<br><br>';
        $texte .= $this->colorUI('BTC dispos : ' . $this->_availableBTC, 'texte') . '<br>';

        $texte = str_replace('<hr>', $this->_hr, $texte);
        $texte = str_replace('<br>', $this->_br, $texte);

        $this->affTextUI($texte);
    }


    /**
     *
     */
    private function colorUI($txt, $zone)
    {
        switch ($zone) {
            case 'heure' : $color = 'yellow';       $colorHEX = '#FCE94F';      break;
            case 'title' : $color = 'light_cyan';   $colorHEX = '#31E0D7';      break;
            case 'texte' : $color = 'white';        $colorHEX = '#ffffff';      break;
            case 'error' : $color = 'light_red';    $colorHEX = '#FF5555';      break;
            case 'good'  : $color = 'light_green';  $colorHEX = '#81E034';      break;
        }

        if (PHP_SAPI === 'cli') {
            return $this->_colorCli->getColor($txt, $foreground_color=$color, $background_color=null);
        } else {
            return '<span style="color:'.$colorHEX.';">' . $txt . '</span>';
        }
    }


    /**
     *
     */
    private function affTextUI($txt)
    {
        $txt = str_replace('<hr>', $this->_hr, $txt);
        $txt = str_replace('<br>', $this->_br, $txt);

        echo $txt;
    }


    /**
     * Gestion de l'affichage adapté pour une interface CLI ou un navigateur
     */
    private function affCLIorCGI()
    {
        if (PHP_SAPI === 'cli') {
            $this->_br  = chr(10);
            $this->_hr  = chr(10) . $this->_colorCli->getColor('______________________________', $foreground_color='dark_gray', $background_color=null) . chr(10) . chr(10);
        } else {
            $this->_br  = '<br>';
            $this->_hr  = '<hr>';
        }
    }


    /**
     * Récupération de l'état des markets
     * et remise en forme du tableau avec les couples de monnaies en clés
     */
    private function getMarketSummaries()
    {
        $this->_marketSummaries = array();

        foreach ($this->_tablesList as $marketTable) {

            // Récupération du marketName
            $expTable   = explode('_', $marketTable);
            $marketName = strtoupper($expTable[1] . '-' . $expTable[2]);

            $val = $this->getTicker($marketTable);

            $this->_marketSummaries[$marketName] = array(
                'last'          => $val->last,
                'baseVolume'    => $val->baseVolume,
                'bid'           => $val->bid,
                'ask'           => $val->ask,
            );
        }
    }


    /**
     * Récupération d'une table de type 'Market'
     */
    private function getTicker($marketTable)
    {
        $req = "SELECT * FROM $marketTable ORDER BY id DESC LIMIT 1";
        $sql = $this->_dbh2->query($req);

        return $sql->fetch();
    }


    /**
     * Récupération de la liste des tables des markets 'market_%' en BDD
     */
    private function tableList()
    {
        $prefixeTable = $this->_prefixeTable;
        $bddExchange  = $this->_nameExBDD;

        $this->_tablesList = array();

        $req = "SELECT  table_name AS exTable

                FROM    information_schema.tables

                WHERE   table_name LIKE ('$prefixeTable%')
                AND     table_schema = '$bddExchange'";

        $sql = $this->_dbh2->query($req);

        while ($res = $sql->fetch()) {
            $this->_tablesList[] = $res->exTable;
        }
    }


    /**
     * Convertisseur de devises
     *
     * @param       string      $crypto1            Crypto monnaie à convertir
     * @param       string      $crypto2            Crypto monnaie de destivation
     * @param       float       $qtyCrtypto1        Quantité de crypto1 à convertir
     *
     * @return      mixed
     */
    private function convertDevises($crypto1=null, $crypto2=null, $qtyCrtypto1=null)
    {
        // Liste des marketName
        $marketList = array_keys($this->_marketSummaries);

        if (in_array($crypto1.'-'.$crypto2, $marketList)) {

            $marketName  = $crypto1.'-'.$crypto2;
            $last        = $this->_marketSummaries[$marketName]['last'];
            return (1 / $last) * $qtyCrtypto1;

        } elseif (in_array($crypto2.'-'.$crypto1, $marketList)) {

            $marketName  = $crypto2.'-'.$crypto1;
            $last        = $this->_marketSummaries[$marketName]['last'];
            return $qtyCrtypto1 * $last;

        } else {

            $texte  = '<hr>';
            $texte .= $this->colorUI('Impossible d\'effectuer la conversion, le couple de monnaies n\existe pas', 'error') . '<br>';

            $this->affTextUI($texte);

            return false;
        }
    }


    /**
     * Liste des stratégies
     */
    private function strategies()
    {
        // Initialisation du tableau des possibilités
        $this->_leadsPossibilities  = array();
        $this->_leadsIgnored        = array();

        // Calcul du volume minimum pour les market ayant l'USDT pour monnaie de référence
        $getTicker      = $this->getTicker('market_usdt_btc');
        $usdtLast       = $getTicker->last;
        $usdtMinVolume  = $this->_btcMinVolume * $usdtLast;

        // Calcul du volume minimum pour les market ayant l'ETH pour monnaie de référence
        $getTicker      = $this->getTicker('market_btc_eth');
        $ethLast        = $getTicker->last;
        $ethMinVolume   = $this->_btcMinVolume / $ethLast;

        // Calcul du volume minimum pour les market ayant le BNB pour monnaie de référence
        $getTicker      = $this->getTicker('market_btc_bnb');
        $bnbLast        = $getTicker->last;
        $bnbMinVolume   = $this->_btcMinVolume / $bnbLast;

        // Boucle sur les différentes stratégies
        foreach ($this->strategiesList() as $keyStrat => $valStrat) {

            // On applique la stratégie sur toutes le monnaies indexées sur la crypto2
            foreach ($this->_marketSummaries as $keySummary => $valSummary) {

                if (strstr($keySummary, $valStrat['crypto2'].'-') && $keySummary != $valStrat['market']) {

                    // Récupération des deux monnais de marketName
                    $expMarket = explode('-', $keySummary);

                    // Variable de vérification des volumes des markets
                    $checkVolumes = 0;

                    // Boucles sur les trades du lead
                    $units  = array();
                    $trades = array();

                    foreach ($valStrat['trades'] as $keyTrade => $valTrade) {

                        // Récupération de la monnaie de référence
                        $cryptoREF  = $valTrade['ref'];

                        // Récupération de la monnaie tradée
                        if ($valTrade['tde'] == 'XXX') {
                            $cryptoTDE = $expMarket[1];
                            // Sauvegarde de la crypto tradée pour les résultats
                            $crypto = $cryptoTDE;
                        } else {
                            $cryptoTDE = $valTrade['tde'];
                        }

                        // check des volumes
                        switch ($cryptoREF) {
                            case 'USDT' : $volMin = $usdtMinVolume;     break;
                            case 'ETH'  : $volMin = $ethMinVolume;      break;
                            case 'BNB'  : $volMin = $bnbMinVolume;      break;
                            default     : $volMin = $this->_btcMinVolume;
                        }

                        $marketName = $cryptoREF.'-'.$cryptoTDE;

                        // Le BNB sert à financer les frais de transaction
                        if ($marketName == 'BTC-BNB') {
                            continue;
                        }

                        if ($cryptoREF == 'USDT') {
                            $volBTC = $this->_marketSummaries[$marketName]['baseVolume'] / $usdtLast;
                        } elseif ($cryptoREF == 'ETH') {
                            $volBTC = $this->_marketSummaries[$marketName]['baseVolume'] * $ethLast;
                        } elseif ($cryptoREF == 'BNB') {
                            $volBTC = $this->_marketSummaries[$marketName]['baseVolume'] * $bnbLast;
                        } else {
                            $volBTC = $this->_marketSummaries[$marketName]['baseVolume'];
                        }

                        $volBTC = round($volBTC, 2);

                        if ($this->_marketSummaries[$marketName]['baseVolume'] < $volMin) {
                            $checkVolumes++;
                            //continue;
                        }

                        // Premier trade, on utilise la mise de la conf, sinon celle du trade précédant
                        if ($keyTrade == 0) {
                            $mise = $this->_maxMise;
                        } else {
                            if (! isset($units[$keyTrade - 1])) {
                                unset($trades);
                                break;
                            }
                            $mise = $units[$keyTrade - 1];
                        }

                        // Calculs du trade pour un Bid
                        if ($valTrade['act'] == 'bid') {

                            $rate = $this->_marketSummaries[$marketName]['ask'];
                            $rate = $this->asksTop($rate);

                            $units[$keyTrade] = $mise / $rate;

                        // Calculs du trade pour un Ask
                        } else {

                            $rate = $this->_marketSummaries[$marketName]['bid'];
                            $rate = $this->bidsTop($rate);

                            $units[$keyTrade] = $mise * $rate;
                        }

                        // Retrait des frais du trade
                        $units[$keyTrade] = $units[$keyTrade] / (1 + ($this->_feesBuy / 100));

                        // Sauvegarde des résultats du trade
                        $trades[$keyTrade] = array(
                            'market'     => $marketName,
                            'baseVolume' => $volBTC,
                            'action'     => $valTrade['act'],
                            'rate'       => $rate,
                            'units'      => $units[$keyTrade],
                        );
                    }

                    if (! isset($units[$keyTrade])) {
                        continue;
                    }

                    // Gain en %
                    $gain = (100 / $this->_maxMise) * ($units[$keyTrade] - $this->_maxMise);
                    $gain = round($gain, 2);

                    if ($checkVolumes > 0) {
                        continue;
                    }

                    // Si ce lead dépasse le seuil mini, on le propose
                    if ($gain > $this->_seuilMini) {
                        $this->_leadsPossibilities[] = array(
                            'gain'              => $gain,
                            'crypto'            => $crypto,
                            'strategie'         => $valStrat['name'],
                            'trades'            => $trades,
                        );
                    } elseif ($gain < $this->_seuilMini && $gain > ($this->_seuilMini-1)) {
                        $this->_leadsIgnored[] = array(
                            'gain'              => $gain,
                            'crypto'            => $crypto,
                            'strategie'         => $valStrat['name'],
                            'trades'            => $trades,
                        );
                    }
                }
            }
        }
    }


    /**
     * Liste des stratégies
     */
    private function strategiesList()
    {
        return array(
            // BTC -> XXX -> USDT -> BTC
            array(
                'name'    => 'BTC-XXX-USDT-BTC',
                'crypto1' => 'BTC',
                'crypto2' => 'USDT',
                'market'  => 'USDT-BTC',
                'trades'  => array(
                    array('ref' => 'BTC',      'tde' => 'XXX',     'act' => 'bid'),
                    array('ref' => 'USDT',     'tde' => 'XXX',     'act' => 'ask'),
                    array('ref' => 'USDT',     'tde' => 'BTC',     'act' => 'bid'),
                )
            ),
            // BTC -> USDT -> XXX -> BTC
            array(
                'name'    => 'BTC-USDT-XXX-BTC',
                'crypto1' => 'BTC',
                'crypto2' => 'USDT',
                'market'  => 'USDT-BTC',
                'trades'  => array(
                    array('ref' => 'USDT',     'tde' => 'BTC',     'act' => 'ask'),
                    array('ref' => 'USDT',     'tde' => 'XXX',     'act' => 'bid'),
                    array('ref' => 'BTC',      'tde' => 'XXX',     'act' => 'ask'),
                )
            ),
            // BTC -> XXX -> ETH -> BTC
            array(
                'name'    => 'BTC-XXX-ETH-BTC',
                'crypto1' => 'BTC',
                'crypto2' => 'ETH',
                'market'  => 'BTC-ETH',
                'trades'  => array(
                    array('ref' => 'BTC',      'tde' => 'XXX',     'act' => 'bid'),
                    array('ref' => 'ETH',      'tde' => 'XXX',     'act' => 'ask'),
                    array('ref' => 'BTC',      'tde' => 'ETH',     'act' => 'ask'),
                ),
            ),
            // BTC -> ETH -> XXX ->BTC
            array(
                'name'    => 'BTC-ETH-XXX-BTC',
                'crypto1' => 'BTC',
                'crypto2' => 'ETH',
                'market'  => 'BTC-ETH',
                'trades'  => array(
                    array('ref' => 'BTC',      'tde' => 'ETH',     'act' => 'bid'),
                    array('ref' => 'ETH',      'tde' => 'XXX',     'act' => 'bid'),
                    array('ref' => 'BTC',      'tde' => 'XXX',     'act' => 'ask'),
                ),
            ),
            // BTC -> XXX -> BNB -> BTC
            array(
                'name'    => 'BTC-XXX-BNB-BTC',
                'crypto1' => 'BTC',
                'crypto2' => 'BNB',
                'market'  => 'BTC-BNB',
                'trades'  => array(
                    array('ref' => 'BTC',      'tde' => 'XXX',     'act' => 'bid'),
                    array('ref' => 'BNB',      'tde' => 'XXX',     'act' => 'ask'),
                    array('ref' => 'BTC',      'tde' => 'BNB',     'act' => 'ask'),
                ),
            ),
            // BTC -> BNB -> XXX ->BTC
            array(
                'name'    => 'BTC-BNB-XXX-BTC',
                'crypto1' => 'BTC',
                'crypto2' => 'BNB',
                'market'  => 'BTC-BNB',
                'trades'  => array(
                    array('ref' => 'BTC',      'tde' => 'BNB',     'act' => 'bid'),
                    array('ref' => 'BNB',      'tde' => 'XXX',     'act' => 'bid'),
                    array('ref' => 'BTC',      'tde' => 'XXX',     'act' => 'ask'),
                ),
            ),
        );
    }


    /**
     * Trie des résultats par gains
     */
    private function resultSort()
    {
        // Création d'un tableau associatif avec la liste des gains
        $rang = array();
        if (count($this->_leadsPossibilities) > 0) {
            foreach ($this->_leadsPossibilities as $key => $val) {
                $rang[$key]  = $val['gain'];
            }

            // Trie les données par rang décroissant sur la colonne 'gain'
            array_multisort($rang, SORT_DESC, $this->_leadsPossibilities);
        }

        // Création d'un tableau associatif avec la liste des gains pour les possibilités ignorées
        $rang = array();
        if (count($this->_leadsIgnored) > 0) {
            foreach ($this->_leadsIgnored as $key => $val) {
                $rang[$key]  = $val['gain'];
            }

            // Trie les données par rang décroissant sur la colonne 'gain'
            array_multisort($rang, SORT_DESC, $this->_leadsIgnored);
        }
    }


    /**
     * Lancement des trades à effectuer
     */
    private function newLead()
    {
        // Si la purge est activée, pas de nouveaux trades
        if ($this->_purge) {
            return false;
        }

        // Check des possibilités avant de lancer un nouveau lead
        $newLeadsPossibilities = $this->newLeadsPossibilities();

        $newLeadsCryptos    = $newLeadsPossibilities['newLeadsCryptos'];
        $addLeadQty         = $newLeadsPossibilities['addLeadQty'];

        // Requête d'enregistrement d'un lead en BDD
        $req_lead = "INSERT INTO TMD_leads
                    (date_crea, date_modif, plateforme, name_devise, name_strategie, strategie, price_buy, estimateGainPct, step, statut)
                    values
                    (NOW(), NOW(), :plateforme, :name_devise, :name_strategie, :strategie, :price_buy, :estimateGainPct, :step, :statut)";
        $sql_lead = $this->_dbh->prepare($req_lead);

        $leadsPossibilitiesAfterCheck = array();

        // Passage des possibilités de leads aux différents controls
        for ($i=0; $i<count($this->_leadsPossibilities); $i++) {

            // Vérification des cryptos éligibles
            if (count($this->_leadsPossibilities)>0 && count($newLeadsCryptos)>0 && in_array($this->_leadsPossibilities[$i]['crypto'], $newLeadsCryptos)) {

                // Vérification des orderBook de cette stratégie
                $checkOrderBook = $this->checkOrderBook($i);

                if ($checkOrderBook === false) {
                    continue;
                } else {
                    $leadsPossibilitiesAfterCheck[] = $this->_leadsPossibilities[$i];
                }
            }
        }

        // Lancement des leads
        if (count($leadsPossibilitiesAfterCheck) > 0) {

            for ($i=0; $i<$addLeadQty; $i++) {

                $strategie = json_encode($leadsPossibilitiesAfterCheck[$i]['trades']);

                // Enregistrement d'un lead en BDD
                $sql_lead->execute(array(
                    ':plateforme'       => 'bittrex',
                    ':name_devise'      => $leadsPossibilitiesAfterCheck[$i]['crypto'],
                    ':name_strategie'   => $leadsPossibilitiesAfterCheck[$i]['strategie'],
                    ':strategie'        => $strategie,
                    ':price_buy'        => $this->_maxMise,
                    ':estimateGainPct'  => $leadsPossibilitiesAfterCheck[$i]['gain'],
                    ':step'             => 0,
                    ':statut'           => 'run'
                ));

                // Appel de l'API bittrex pour lancer la transaction
                $marketName = $leadsPossibilitiesAfterCheck[$i]['trades'][0]['market'];

                // Type du premier ordre : bid|ask
                $action = $leadsPossibilitiesAfterCheck[$i]['trades'][0]['action'];

                // Mise à jour des informations sur la monnaie
                $getTicker = $this->_getMarket->getTicker($marketName);

                // Majoration du rate
                if ($action == 'bid') {
                    $rate = $getTicker->Bid;
                    $rate = $this->bidsTop($rate);
                } else {
                    $rate = $getTicker->Ask;
                    $rate = $this->asksTop($rate);
                }

                // Calcul de la quantité dans la devise tradée
                $quantity = (1 / $rate) * $this->_maxMise;

                // On extrait la commission d'achat pour tout inclure dans le prix
                $quantity = $quantity / (1 + $this->_feesBuy/100);
                $quantity = round($quantity, 8, PHP_ROUND_HALF_DOWN);

                // Informations de suivi
                $texte  = '<hr>';
                $texte .= $this->colorUI('Enregistrement nouveau Trade : ', 'title') . '<br><br>';
                $texte .= $this->colorUI('Market : '    . $marketName,  'texte') . '<br>';
                $texte .= $this->colorUI('Quantity : '  . $quantity,    'texte') . '<br>';
                $texte .= $this->colorUI('Rate : '      . $marketName,  'texte') . '<br>';

                $this->affTextUI($texte);

                // Création et stockage du nouvel ordre
                $this->newTransaction(array(
                'id_lead'       => $this->_dbh->lastInsertId(),
                'action'        => $action,
                'marketName'    => $marketName,
                'quantity'      => $quantity,
                'rate'          => $rate,
                'step'          => 0,
                'statut'        => 'init',
                ));
            }
        }
    }


    /**
     * Récupération des nouvelles cryptos monnaies pouvant être tradées
     * Nombre de Leads pouvant être ajoutés à ceux en cours
     */
    private function newLeadsPossibilities()
    {
        $miseWithFees = $this->_maxMise / (1 + ($this->_feesBuy / 100));

        // Nombre de leads possibles avec le solde actuel
        $leadQtyMax = floor($this->_availableBTC / $miseWithFees);
        if ($leadQtyMax > $this->_leadsQuantity) {
            $leadQtyMax = $this->_leadsQuantity;
        }

        // Liste des monnaies qui peuvent être tradées
        $leadsPossibilities = array();
        if (count($this->_leadsPossibilities) > 0) {
            foreach ($this->_leadsPossibilities as $k => $v) {
                $leadsPossibilities[] = $v['crypto'];
            }
        }

        // Récupération des monnaies blacklistées
        $req = "SELECT name_devise FROM TMD_blacklists WHERE activ = 1";
        $sql = $this->_dbh->query($req);

        $cryptosBlacklist = array();
        while ($res = $sql->fetch()) {
            $cryptosBlacklist[] = $res->name_devise;
        }

        // Suppression des monnaies blacklistées
        $leadsPossibilities = array_diff($leadsPossibilities, $cryptosBlacklist);

        // Récupération des monnaies pouvant être tradées
        $req = "SELECT      name_devise

                FROM        TMD_leads

                WHERE       plateforme = :plateforme
                AND         statut = :statut";

        $sql = $this->_dbh->prepare($req);

        $sql->execute(array(
            ':plateforme'   => 'bittrex',
            ':statut'       => 'run',
        ));

        // Nombre de monnaies en cours de trade
        $currentLeadsCount = $sql->rowCount();

        // Liste des monnaies en cours de trade
        $currentLeads = array();
        while ($res = $sql->fetch()) {
            $currentLeads[] = $res->name_devise;
        }

        // Diff avec les monnaies déjà tradées
        $newLeadsCryptos = array_diff($leadsPossibilities, $currentLeads);

        // On compte les monnaies qui pourraient être ajoutés aux leads en cours
        if ($leadQtyMax > count($newLeadsCryptos)) {
            $leadQtyMax = count($newLeadsCryptos);
        }

        // Nombre de monnaies max pouvant être ajoutées
        $addLeadQtyMax = $this->_leadsQuantity - $currentLeadsCount;

        if ($leadQtyMax > $addLeadQtyMax) {
            $addLeadQtyMax = $addLeadQtyMax;
        }

        return array(
            'newLeadsCryptos'   => $newLeadsCryptos,
            'addLeadQty'        => $addLeadQtyMax,
        );
    }


    /**
     *
     */
    private function checkOrderBook($keyLead)
    {
        // Informations de suivi
        $texte  = '<hr>';
        $texte .= $this->colorUI('Vérification des de la stratégie "' . $this->_leadsPossibilities[$keyLead]['strategie'] . '" dans l\'orderBook : ', 'title') . '<br>';

        $this->affTextUI($texte);

        foreach ($this->_leadsPossibilities[$keyLead]['trades'] as $key => $val) {

            $market = $val['market'];
            $action = $val['action'];

            // Récupération de la monnaie de référence
            $expMarket = explode('-', $market);
            $cryptoREF = $expMarket[0];

            // Récupération de l'orderBook du market
            $orderBookPrediction = \cryptos\cli\orderBookCLI::orderBookPrediction($market, 'both');

            // Nombre de ranges testés
            $nbRange = 3;

            for ($i=0; $i<$nbRange; $i++) {

                switch ($action) {
                    case 'bid' :    $checkOrderSide = 'sell';       break;
                    case 'ask' :    $checkOrderSide = 'buy';        break;
                }

                // Informations de suivi
                $texte  = '<br>';
                $texte .= $this->colorUI('Market : ' . $market, 'texte') . '<br>';
                $texte .= $this->colorUI('Action : ' . $action, 'texte') . '<br>';

                // On accepte de lancer seulemement si l'autre côté de l'orderBook peut absorber notre mise
                if      ($cryptoREF == 'BTC' || $market == 'USDT-BTC')  { $pctOrderBook = $this->_pctOrderBookBTC;  }
                elseif  ($cryptoREF == 'ETH')                           { $pctOrderBook = $this->_pctOrderBookETH;  }
                elseif  ($cryptoREF == 'USDT')                          { $pctOrderBook = $this->_pctOrderBookUSDT; }

                // On retire 5% à chaque passage de range supérieur
                if ($i>0) {
                    $pctOrderBook -= 10 * $i;
                }

                $check = 0;

                if ($orderBookPrediction[$i][$checkOrderSide] >= $pctOrderBook) {
                    $this->_leadsPossibilities[$keyLead]['trades'][$key]['orderBook'][$i]['pct']   = $orderBookPrediction[$i]['pct'];
                    $this->_leadsPossibilities[$keyLead]['trades'][$key]['orderBook'][$i]['buy']   = $orderBookPrediction[$i]['buy'];
                    $this->_leadsPossibilities[$keyLead]['trades'][$key]['orderBook'][$i]['sell']  = $orderBookPrediction[$i]['sell'];

                    $texte .= $this->colorUI('Range : ' . $orderBookPrediction[$i]['pct'], 'good') . '<br>';
                    $texte .= $this->colorUI('Pct ' . $checkOrderSide . ' : ' . $orderBookPrediction[$i][$checkOrderSide] . '% >= ' . $pctOrderBook . '% = Good !', 'good') . '<br>';

                } else {

                    $texte .= $this->colorUI('Range : ' . $orderBookPrediction[$i]['pct'], 'error') . '<br>';
                    $texte .= $this->colorUI('Pct ' . $checkOrderSide . ' : ' . $orderBookPrediction[$i][$checkOrderSide] . '% < ' . $pctOrderBook . '% = Echec !', 'error') . '<br>';

                    $check++;
                }

                $texte .= '<br>';
                $this->affTextUI($texte);

                if ($check > 0) {
                    return false;
                }
            }
        }

        //print_r($this->_leadsPossibilities[$keyLead]['trades']);

        return true;
    }


    /**
     * Suivi et lancement des étapes des leads en cours d'execution
     */
    private function currentLeads()
    {
        // Vérification des ordres ouverts pour éviter les pertes
        $listOpenOrder = $this->checkOpenOrder();

        // Vérification des leads en cours
        $req = "SELECT id, step, strategie FROM TMD_leads WHERE plateforme = :plateforme AND statut = :statut";
        $sql = $this->_dbh->prepare($req);
        $sql->execute(array(
            ':plateforme'   => 'bittrex',
            ':statut'       => 'run',
        ));

        // Vérification des transactions des leads en cours à l'étape courante
        $req_transaction = "SELECT id, uuid, quantity FROM TMD_transactions WHERE id_lead = :id_lead AND step = :step AND statut = :statut";
        $sql_transaction = $this->_dbh->prepare($req_transaction);

        // Requête permettant de passer à 'end' les ordres passés
        $req_transaction_maj = "UPDATE TMD_transactions SET date_modif = NOW(), quantity = :quantity, statut = :statut WHERE id_lead = :id_lead AND step = :step";
        $sql_transaction_maj = $this->_dbh->prepare($req_transaction_maj);

        // Requête permettant de mettre à jour le 'step' du lead
        $req_lead_maj = "UPDATE TMD_leads SET date_modif = NOW(), step = :step WHERE id = :id";
        $sql_lead_maj = $this->_dbh->prepare($req_lead_maj);

        // Lecture du prix d'achat d'un lead
        $req_lead_price_buy = "SELECT price_buy FROM TMD_leads WHERE id = :id";
        $sql_lead_price_buy = $this->_dbh->prepare($req_lead_price_buy);

        // Clôture d'un lead
        $req_lead_end = "UPDATE     TMD_leads

                         SET        date_modif  = NOW(),
                                    statut      = :statut,
                                    price_sell  = :price_sell,
                                    gain        = :gain,
                                    gain_pct    = :gain_pct

                         WHERE      id = :id";
        $sql_lead_end = $this->_dbh->prepare($req_lead_end);

        // Boucle sur les leads
        while ($res = $sql->fetch()) {

            $id_lead = $res->id;
            $step    = $res->step;

            $sql_transaction->execute(array(
                ':id_lead'  => $id_lead,
                ':step'     => $step,
                ':statut'   => 'init',
            ));

            // Boucle sur les transactions du lead
            while ($res_transaction = $sql_transaction->fetch()) {

                $uuid = $res_transaction->uuid;

                // Récupération de l'ordre dans l'historique
                $getOrder = $this->_tradingAPI->getOrderHistoryInfos($uuid);

                // On vérifie que la transactions se soit bien passée
                if (! in_array("'" . $uuid . "'", $listOpenOrder) && $getOrder !== false && $getOrder->QuantityRemaining == 0 && $getOrder->IsOpen != 1) {

                    $texte  = '<hr>';
                    $texte .= $this->colorUI("L'ordre est bien passé : " . $uuid, 'good') . '<br>';

                    $this->affTextUI($texte);

                    // Check s'il y a une nouvelle étape
                    $strategie  = json_decode($res->strategie);
                    $newStep    = $step + 1;

                    // Récupération du market et de l'action (bid|ask) du trade effectué
                    $oldMarket = $strategie[$step]->market;
                    $oldAction = $strategie[$step]->action;
                    $explodeOldMarket = explode('-', $oldMarket);

                    // Récupération des informations sur la dernière transaction passée de ce lead
                    $getOrderInfos = $this->_tradingAPI->getOrderHistoryInfos($uuid);

                    if (! is_object($getOrderInfos)) {
                        continue;
                    }

                    // Passage à l'étape suivante de la stratégie du lead
                    if (isset($strategie[$newStep])) {

                        // Récupération du market et de l'action (bid|ask) du trade à réaliser
                        $market = $strategie[$newStep]->market;
                        $action = $strategie[$newStep]->action;
                        $explodeMarket = explode('-', $market);

                        // Récupération des infos à jour du market
                        $cryptoCheck = $this->_getMarket->getMarketSummary($market);

                        // Prix de revente dans la devise de la dernière monnaie de référence
                        $price_rem_fees = $getOrderInfos->Price - $getOrderInfos->CommissionPaid;
                        $price_add_fees = $getOrderInfos->Price + $getOrderInfos->CommissionPaid;

                        if ($action == 'bid') {
                            // Récupération de la dernière valeur du tableau 'Bids' et on l'améliore
                            $newRate = $cryptoCheck[0]->Bid;
                            $newRate = $this->bidsTop($newRate);

                            // Calcul de la quantité à acheter dans la bonne devise
                            if ($explodeOldMarket[0] == $explodeMarket[0]) {
                                $quantity = $price_rem_fees / $newRate;
                            } elseif  ($explodeOldMarket[1] == $explodeMarket[0]) {
                                $price_devise   = $price_add_fees / $getOrderInfos->PricePerUnit;
                                $quantity       = $price_devise   / $newRate;
                            }

                        } else {
                            // Récupération de la dernière valeur du tableau 'Asks' et on l'améliore
                            $newRate = $cryptoCheck[0]->Ask;
                            $newRate = $this->asksTop($newRate);

                            // Calcul de la quantité à vendre dans la bonne devise
                            if ($explodeOldMarket[0] == $explodeMarket[1]) {
                                $quantity = $price_rem_fees;
                            } elseif ($explodeOldMarket[1] == $explodeMarket[1]) {

                                $pricePerUnit = $getOrderInfos->PricePerUnit;

                                if (empty($pricePerUnit)) {
                                    $marketSummary = $this->_getMarket->getMarketSummary($oldMarket);
                                    $pricePerUnit  = $marketSummary[0]->Last;
                                }

                                $quantity = $price_rem_fees / $pricePerUnit;
                            }
                        }

                        // Arrondi à 8 décimales
                        $quantity = round($quantity, 8, PHP_ROUND_HALF_DOWN);

                        // Informations de suivi
                        $texte  = '<hr>';
                        $texte .= $this->colorUI('Méhode "Current Trades" : ouverture d\'un nouvel ordre !', 'title') . '<br><br>';
                        $texte .= $this->colorUI('marketName : ' . $market,   'texte') . '<br>';
                        $texte .= $this->colorUI('newAction : '  . $action,   'texte') . '<br>';
                        $texte .= $this->colorUI('quantity : '   . $quantity, 'texte') . '<br>';
                        $texte .= $this->colorUI('newRate : '    . $newRate,  'texte') . '<br>';

                        $this->affTextUI($texte);

                        // Création et stockage du nouvel ordre
                        $trade = $this->newTransaction(array(
                            'id_lead'       => $id_lead,
                            'action'        => $action,
                            'marketName'    => $market,
                            'quantity'      => $quantity,
                            'rate'          => $newRate,
                            'step'          => $newStep,
                            'statut'        => 'init',
                        ));

                        if ($trade === true) {

                            // Ordre valide et passé, on met à jour l'entrée en BDD
                            $sql_transaction_maj->execute(array(
                                ':quantity' => $getOrderInfos->Price,
                                ':statut'   => 'end',
                                ':id_lead'  => $id_lead,
                                ':step'     => $step,
                            ));

                            // Passage du lead à l'étape suivante
                            $sql_lead_maj->execute(array(
                                ':id'       => $id_lead,
                                ':step'     => $newStep,
                            ));
                        }


                    // Toutes les étapes sont terminées, le lead peut être clôturé
                    } else {

                        $market = $strategie[$step]->market;
                        $action = $strategie[$step]->action;

                        // Récupération du prix d'achat
                        $sql_lead_price_buy->execute(array(
                            ':id' => $id_lead
                        ));

                        $quantity = ($getOrderInfos->Price - $getOrderInfos->CommissionPaid) / $getOrderInfos->PricePerUnit;

                        // Calcul du prix de revente
                        if      ($explodeOldMarket[0] == 'BTC')     { $price_sell = $getOrderInfos->Price - $getOrderInfos->CommissionPaid; }
                        elseif  ($explodeOldMarket[1] == 'BTC')     { $price_sell = $quantity; }

                        // Récupération du prix d'achat
                        $res_lead_price_buy = $sql_lead_price_buy->fetch();
                        $price_buy = $res_lead_price_buy->price_buy;

                        // Calcul des gains
                        $gain       = $price_sell - $price_buy;
                        $gain_pct   = (100 / $price_buy) * $gain;

                        // Informations de suivi
                        $texte  = '<hr>';
                        $texte .= $this->colorUI('Clôture du Lead !', 'title') . '<br><br>';
                        $texte .= $this->colorUI('price_buy : '  . $price_buy,      'texte') . '<br>';
                        $texte .= $this->colorUI('price_sell : ' . $price_sell,     'texte') . '<br>';
                        $texte .= $this->colorUI('gain : '       . $gain,           'texte') . '<br>';
                        $texte .= $this->colorUI('gainPct : '    . $gain_pct.'% ',  'texte') . '<br>';

                        $this->affTextUI($texte);

                        // Cloture des dernière transactions
                        $sql_transaction_maj->execute(array(
                            ':id_lead'  => $id_lead,
                            ':quantity' => $quantity,
                            ':step'     => $step,
                            ':statut'   => 'end',
                        ));

                        // Clôture du lead
                        $sql_lead_end->execute(array(
                            ':id'           => $id_lead,
                            ':statut'       => 'end',
                            ':price_sell'   => $price_sell,
                            ':gain'         => $gain,
                            ':gain_pct'     => $gain_pct,
                        ));
                    }
                }
            }
        }
    }


    /**
     * Vérification des ordres ouverts pour éviter les pertes
     */
    private function checkOpenOrder()
    {
        $getOpenOrders = $this->_tradingAPI->getOpenOrders();

        $listOpenOrder = array();

        if (isset($getOpenOrders->success) && $getOpenOrders->success === true && count($getOpenOrders->result) > 0) {

            // Récupération de la liste des ordres encore ouverts
            foreach ($getOpenOrders->result as $key => $val) {
                $listOpenOrder[] = "'" . $val->OrderUuid . "'";
            }
            $listOpenOrderStr = implode(', ', $listOpenOrder) . '';

            // Comparaison avec les ordres ouverts en BDD
            $req = "SELECT      a.id_lead, a.action, a.market, a.rate, a.step, a.uuid, UNIX_TIMESTAMP(a.date_modif) AS last_modif

                    FROM        TMD_transactions a

                    INNER JOIN  TMD_leads b
                    ON          a.id_lead = b.id

                    WHERE       a.statut = :statut
                    AND         b.plateforme = :plateforme
                    AND         a.uuid IN ($listOpenOrderStr)";

            $sql = $this->_dbh->prepare($req);

            $sql->execute(array(
                ':statut'       => 'init',
                ':plateforme'   => 'bittrex',
            ));

            $res = $sql->fetchAll();

            if (count($res) > 0) {

                // Requête : vérifie s'il y a d'autres transactions lancées avec le même id_lead
                $req_count_lead = "SELECT id FROM TMD_transactions WHERE id_lead = :id_lead AND uuid <> :uuid";
                $sql_count_lead = $this->_dbh->prepare($req_count_lead);

                // Requête : passage d'un lead au statut 'cancel'
                $req_cancel_lead = "UPDATE TMD_leads SET statut = :statut WHERE id = :id";
                $sql_cancel_lead = $this->_dbh->prepare($req_cancel_lead);

                // Requête : passage d'une transaction au statut 'cancel'
                $req_cancel_transaction = "UPDATE TMD_transactions SET statut = :statut WHERE id_lead = :id_lead";
                $sql_cancel_transaction = $this->_dbh->prepare($req_cancel_transaction);

                // Boucle sur les transactions posant problème
                foreach ($res as $key => $val) {

                    $id_lead    = $val->id_lead;
                    $action     = $val->action;
                    $marketName = $val->market;
                    $rate       = $val->rate;
                    $step       = $val->step;
                    $uuid       = $val->uuid;
                    $last_modif = $val->last_modif;

                    // Informations de suivi
                    $texte  = '<hr>';
                    $texte .= $this->colorUI('Vérification des ordres ouverts : ', 'title') . '<br><br>';
                    $texte .= $this->colorUI('uuid : '       . $uuid,       'texte') . '<br>';
                    $texte .= $this->colorUI('marketName : ' . $marketName, 'texte') . '<br>';
                    $texte .= $this->colorUI('id_lead : '    . $id_lead,    'texte') . '<br>';
                    $texte .= $this->colorUI('action : '     . $action,     'texte') . '<br>';
                    $texte .= $this->colorUI('rate : '       . $rate,       'texte') . '<br>';
                    $texte .= $this->colorUI('step : '       . $step,       'texte') . '<br>';

                    $this->affTextUI($texte);

                    $time1 = time() - $this->_replaceOrderTime1;  // Temps a attendre entre deux replaceOrder
                    $time2 = time() - $this->_replaceOrderTime2;  // Temps a attendre si rien ne se passe avant de relancer un replaceOrder

                    $nbLines = $this->_replaceOrderBook - 1;

                    if ($action == 'bid') {

                        // Récupération de l'orderBook des 'Buy'
                        $orderBook = \cryptos\api\bittrex\getOrderBook::getOrderBook($marketName, 'buy');


                        // Limite de sécurité : l'ordre est inférieur au x meilleures offres, on le replace en haut des 'Bids'
                        if (($last_modif < $time1 && $orderBook[$nbLines]->Rate > $rate) || ($last_modif < $time2)) {
                            $this->replaceOrder($marketName, $uuid, $action);
                        } else {
                            continue;
                        }

                    } else {

                        // Récupération de l'orderBook des 'Sell'
                        $orderBook = \cryptos\api\bittrex\getOrderBook::getOrderBook($marketName, 'sell');

                        // Limite de sécurité : l'ordre est supérieur au x meilleures offres, on le replace en haut des 'Asks'
                        if (($last_modif < $time1 && $orderBook[$nbLines]->Rate < $rate) || ($last_modif < $time2)) {
                            $this->replaceOrder($marketName, $uuid, $action);
                        } else {
                            continue;
                        }
                    }

                    // Etape 0 et pas d'autres transactions en cours, on annule tout
                    /*
                    $sql_count_lead->execute(array(
                        ':id_lead'  => $id_lead,
                        ':uuid'     => $uuid,
                    ));

                    $countOtherTransaction = $sql_count_lead->rowCount();

                    if ($step == 0 && $countOtherTransaction == 0) {

                        $sql_cancel_lead->execute(array(
                            ':id' => $id_lead,
                            ':statut' => 'cancel',
                        ));

                        $sql_cancel_transaction->execute(array(
                            ':id_lead' => $id_lead,
                            ':statut' => 'cancel',
                        ));

                    // Etape > 0, on relance l'ordre pour terminer le lead
                    } else {

                        // Mise à jour des informations sur la monnaie
                        $cryptoCheck = $this->_getMarket->getMarketSummary($marketName);

                        if ($action == 'bid') {
                            $rate = $cryptoCheck[0]->Bid;
                            $rate = $this->bidsTop($rate);
                        } else {
                            $rate = $cryptoCheck[0]->Ask;
                            $rate = $this->asksTop($rate);
                        }

                        $mise = $this->_maxMise / (1 + ($this->_feesBuy / 100));
                        $quantity = (1 / $rate) * $mise;

                        // Création et stockage du nouvel ordre
                        $optionsTransaction = array(
                            'id_lead'       => $id_lead,
                            'action'        => $action,
                            'marketName'    => $marketName,
                            'quantity'      => $quantity,
                            'rate'          => $rate,
                            'step'          => $step,
                            'statut'        => 'init',
                        );
                    }
                    */
                }
            }
        }

        // Mise à jour des ordres ouverts pour avoir les bons uuid
        $getOpenOrders = $this->_tradingAPI->getOpenOrders();

        $listOpenOrder = array();

        if ($getOpenOrders->success === true && count($getOpenOrders->result) > 0) {

            // Récupération de la liste des ordres encore ouverts
            foreach ($getOpenOrders->result as $key => $val) {
                $listOpenOrder[] = "'" . $val->OrderUuid . "'";
            }
        }

        return $listOpenOrder;
    }


    /**
     * Méhode permettant de replacer les ordres en haut du tableau des Bids ou des Asks
     *
     * @param       string      $marketName         Place de marché (ex: BTC-LTC)
     * @param       string      $uuid               Indentifiant unique de l'odre passé
     * @param       string      $action             bid|ask : ordre d'achat ou de vente
     *
     */
    private function replaceOrder($marketName, $uuid, $action)
    {
        // Récupération du nombre de replace déjà effectué pour cet ordre
        $req = "SELECT id, id_lead, count_replace FROM TMD_transactions WHERE uuid = :uuid";
        $sql = $this->_dbh->prepare($req);

        // Log des erreurs de recréation d'ordre
        $req_log = "INSERT INTO TMD_logs (id_lead, id_transaction, action, message, date_crea) VALUES (:id_lead, :id_transaction, :action, :message, NOW())";
        $sql_log = $this->_dbh->prepare($req_log);

        // Mise à jour d'une transaction annulée et recréé
        $req_maj = "UPDATE      TMD_transactions

                    SET         date_modif      = NOW(),
                                rate            = :rate,
                                quantity        = :quantity,
                                count_replace   = :count_replace,
                                uuid            = :uuid

                    WHERE       id = :id";
        $sql_maj = $this->_dbh->prepare($req_maj);


        $sql->execute(array(
            ':uuid' => $uuid,
        ));

        if ($sql->rowCount() > 0) {

            $res = $sql->fetch();

            $id      = $res->id;
            $id_lead = $res->id_lead;
            $countReplace = $res->count_replace;

            // Récupération des Bid et Ask pour calculer le nouveau Rate
            $getTicker = $this->_getMarket->getTicker($marketName);
            $bid = $getTicker->Bid;
            $ask = $getTicker->Ask;

            // On améliore ce rate
            if ($action == 'bid') {

                $topRate = $bid;
                $newRate = $this->bidsTop($topRate, $countReplace);
                //$newRate = $this->bidsTop($topRate, 0);

                // Sécurité pour ne pas acheter trop cher
                if ($newRate > $ask) {
                    $newRate = $ask;
                }

            } else {

                $topRate = $ask;
                $newRate = $this->asksTop($topRate, $countReplace);
                //$newRate = $this->asksTop($topRate, 0);

                // Sécurité pour ne pas vendre trop cher
                if ($newRate < $bid) {
                    $newRate = $bid;
                }
            }

            // Informations de suivi
            $texte  = '<hr>';
            $texte .= $this->colorUI('Tentative de replaceOrder : ', 'title') . '<br><br>';
            $texte .= $this->colorUI('marketName : ' . $marketName,  'texte') . '<br>';
            $texte .= $this->colorUI('topRate : '    . $topRate,     'texte') . '<br>';
            $texte .= $this->colorUI('newRate : '    . $newRate,     'texte') . '<br>';

            $this->affTextUI($texte);


            // Récupération des informations sur l'odre passé
            $getOpenOrders = $this->_tradingAPI->getOpenOrders();

            if ($getOpenOrders->success === true && count($getOpenOrders->result) > 0) {

                // Récupération de la liste des ordres encore ouverts
                foreach ($getOpenOrders->result as $key => $val) {
                    if ($val->OrderUuid == $uuid) {
                        $ordre = $val;
                    }
                }

                // Ordre retrouvé et il n'a pas été partiellement distribué
                if (isset($ordre) && $ordre->Quantity == $ordre->QuantityRemaining) {

                    // Pour un 'ask', Quantity est égal à la balance de la crypto indépendamment du rate
                    $quantity = $ordre->Quantity;

                    // Pour un 'bid', Quantity est recalculé en fonction de newRate et des frais
                    if ($action == 'bid') {
                        //$quantity = (1 /  $newRate) * ($quantity - (($quantity / 100) * $this->_feesBuy));
                        $quantity = ($ordre->Quantity * $ordre->Limit) / $newRate;
                    }

                    $texte .= $this->colorUI('quantity : ' . $quantity, 'texte') . '<br>';
                    $this->affTextUI($texte);

                    // On annule l'ordre en cours
                    $cancelOrder = $this->_tradingAPI->cancelOrder($uuid);

                    if ($cancelOrder->success !== true) {

                        $texte .= $this->colorUI('Message cancel : ' . $cancelOrder->message, 'error') . '<br>';
                        $this->affTextUI($texte);

                        $sql_log->execute(array(
                            ':id_lead'          => $id_lead,
                            ':id_transaction'   => $id,
                            ':action'           => 'replaceOrder : cancel order - ' . $marketName . ' - ' . $action,
                            ':message'          => $cancelOrder->message,
                        ));

                    } else {

                        // Récupération de la monnaie tradée
                        $currency = explode('-', $marketName);

                        $texte .= $this->colorUI('Le cancel retourne un true', 'texte') . '<br>';
                        $this->affTextUI($texte);

                        $quantity = $this->newTransactionCheck($marketName, $quantity, $action);

                        if ($action == 'bid') {
                            $trade = $this->_tradingAPI->buyLimit($marketName, $quantity, $newRate);
                        } else {
                            $trade = $this->_tradingAPI->sellLimit($marketName, $quantity, $newRate);
                        }

                        if ($trade->success !== true) {

                            $texte .= $this->colorUI('Message trade : ' . $trade->message, 'error') . '<br>';
                            $this->affTextUI($texte);

                            $sql_log->execute(array(
                                ':id_lead'          => $id_lead,
                                ':id_transaction'   => $id,
                                ':action'           => 'replaceOrder : new order - ' . $marketName . ' - ' . $action,
                                ':message'          => $trade->message,
                            ));

                        } else {

                            // Le nouvel ordre est passé on met à jour la transaction en BDD
                            $sql_maj->execute(array(
                                ':rate'          => $newRate,
                                ':quantity'      => $quantity,
                                ':count_replace' => $countReplace + 1,
                                ':uuid'          => $trade->result->uuid,
                                ':id'            => $id,
                            ));

                            // Informations de suivi
                            $texte  = '<hr>';
                            $texte .= $this->colorUI('On remonte l\'odre en haut de l\'orderBook  : ', 'title') . '<br><br>';
                            $texte .= $this->colorUI('Uuid : '          . $uuid,         'texte') . '<br>';
                            $texte .= $this->colorUI('Action : '        . $action,       'texte') . '<br>';
                            $texte .= $this->colorUI('topRate : '       . $topRate,      'texte') . '<br>';
                            $texte .= $this->colorUI('newRate : '       . $newRate,      'texte') . '<br>';
                            $texte .= $this->colorUI('countReplace : '  . $countReplace, 'texte') . '<br>';

                            $this->affTextUI($texte);
                        }
                    }
                }
            }
        }
    }


    /**
     * Relance des leads qui se sont stoppés avant la fin
     */
    private function relanceLeads()
    {
        // Détection des transactions présentes en BDD mais disparu de Bittrex
        $req = "SELECT          a.id_lead, a.step, a.uuid

                FROM            TMD_transactions a

                INNER JOIN      TMD_leads b
                ON              a.id_lead = b.id

                WHERE           a.statut = :statut_transaction
                AND             b.statut = :statut_lead";

        $sql = $this->_dbh->prepare($req);

        // Efface la dernière transaction du lead
        $res_trans_del = "DELETE FROM TMD_transactions WHERE uuid = :uuid";
        $sql_trans_del = $this->_dbh->prepare($res_trans_del);

        // Change le statut de la transaction précédente
        $res_trans_statut = "UPDATE TMD_transactions SET statut = :statut WHERE id_lead = :id_lead AND step = :step";
        $sql_trans_statut = $this->_dbh->prepare($res_trans_statut);

        // Passage du lead à l'étape précédente
        $res_lead_statut = "UPDATE TMD_leads SET step = :step WHERE id = :id";
        $sql_lead_statut = $this->_dbh->prepare($res_lead_statut);

        // Liste des ordre ouvert sur Bittrex
        $getOpenOrders = $this->_tradingAPI->getOpenOrders();
        $listOpenOrder = array();

        if ($getOpenOrders->success === true && count($getOpenOrders->result) > 0) {

            // Récupération de la liste des ordres encore ouverts
            foreach ($getOpenOrders->result as $key => $val) {
                $listOpenOrder[] = $val->OrderUuid;
            }
        }

        $sql->execute(array(
            ':statut_transaction'   => 'init',
            ':statut_lead'          => 'run',
        ));

        while ($res = $sql->fetch()) {

            $id_lead = $res->id_lead;
            $step    = $res->step;
            $uuid    = $res->uuid;

            // Récupération de l'ordre dans l'historique
            $getOrder = $this->_tradingAPI->getOrderHistoryInfos($uuid);

            // Si l'ordre est absent de l'historique des ordres validés et des ordres ouverts, on le relance
            if (! in_array($uuid, $listOpenOrder) && ($getOrder === false || ($getOrder->QuantityRemaining > 0 && $getOrder->IsOpen != 1))) {

                if ($step > 0) {

                    // Efface la dernière transaction du lead
                    $sql_trans_del->execute(array(
                        ':uuid'     => $uuid,
                    ));

                    // Change le statut de la transaction précédente
                    $sql_trans_statut->execute(array(
                        ':id_lead'  => $id_lead,
                        ':step'     => $step - 1,
                        ':statut'   => 'init',
                    ));

                    // Passage du lead à l'étape précédente
                    $sql_lead_statut->execute(array(
                        ':id'       => $id_lead,
                        ':step'     => $step - 1,
                    ));
                }
            }
        }
    }


    /**
     * Ajout d'une nouvelle transaction de type buy ou sell
     *
     * $options = array(
     *      'id_lead'       => ID du lead
     *      'action'        => bid | ask (achat | vente)
     *      'marketName'    => Ex: BTC-LTC
     *      'quantity'      => Quantité exprimée dans la monnaie de destination (LTC dans l'exemple)
     *      'rate'          => Valeur souhaite de l'unité à l'achat ou la vente
     *      'step'          => N° de l'étape du lead
     *      'statut'        => init | end
     * );
     *
     * @param       array      $options
     *
     * @return      boolean
     */
    private function newTransaction($options)
    {
        // Récupération des baggies (sommes inférieures à la mise minimum) et correction des 'INSUFFICIANT_FUNDS'
        $quantity = $this->newTransactionCheck($options['marketName'], $options['quantity'], $options['action']);

        // Création de l'ordre par API
        if ($options['action'] == 'bid') {
            $trade = $this->_tradingAPI->buyLimit($options['marketName'],  $quantity, $options['rate']);
        } else {
            $trade = $this->_tradingAPI->sellLimit($options['marketName'], $quantity, $options['rate']);
        }

        if ($trade->success === true) {

            // Récupération de l'uuid de la transation
            $uuid = $trade->result->uuid;

            // Enregistrement de la transaction en BDD
            $req_transaction = "INSERT INTO TMD_transactions
                        (id_lead, date_crea, date_modif, uuid, action, market, quantity, rate, step, statut)
                        values
                        (:id_lead, NOW(), NOW(), :uuid, :action, :market, :quantity, :rate, :step, :statut)";
            $sql_transaction = $this->_dbh->prepare($req_transaction);

            $sql_transaction->execute(array(
                ':id_lead'          => $options['id_lead'],
                ':uuid'             => $uuid,
                ':action'           => $options['action'],
                ':market'           => $options['marketName'],
                ':quantity'         => $quantity,
                ':rate'             => $options['rate'],
                ':step'             => $options['step'],
                ':statut'           => $options['statut'],
            ));

            // Informations de suivi
            $texte  = '<hr>';
            $texte .= $this->colorUI('Ordre accepté : ' . $uuid, 'title') . '<br><br>';
            $texte .= $this->colorUI('id_lead : '       . $options['id_lead'],      'texte') . '<br>';
            $texte .= $this->colorUI('step : '          . $options['step'],         'texte') . '<br>';
            $texte .= $this->colorUI('marketName : '    . $options['marketName'],   'texte') . '<br>';
            $texte .= $this->colorUI('action : '        . $options['action'],       'texte') . '<br>';

            $this->affTextUI($texte);

            return true;

        } else {

            // Informations de suivi
            $texte  = '<hr>';
            $texte .= $this->colorUI('Ordre refusé : '  . $trade->message, 'error') . '<br><br>';
            $texte .= $this->colorUI('id_lead : '       . $options['id_lead'],      'texte') . '<br>';
            $texte .= $this->colorUI('step : '          . $options['step'],         'texte') . '<br>';
            $texte .= $this->colorUI('marketName : '    . $options['marketName'],   'texte') . '<br>';
            $texte .= $this->colorUI('action : '        . $options['action'],       'texte') . '<br>';

            $this->affTextUI($texte);

            return false;
        }
    }


    /**
     * Cette méthode ajouste la quantité de monnaie tradée
     * pour ne pas bloquer une transaction pour quelques satoshis
     *
     * @param       string      $marketName         Nom du market, ex : 'BTC-LTC'
     * @param       number      $quantity           Quantité d'unités de la monnaie tradée
     * @param       string      $action             Type d'action : bid|ask
     *
     * @return      number                          Quantité d'unités de la monnaie tradée corrigé si nécessaire
     *
     */
    private function newTransactionCheck($marketName, $quantity, $action)
    {
        // Récupération de la crypto de référence et de la crypto tradée
        $explodeMarket  = explode('-', $marketName);
        $cryptoREF      = $explodeMarket[0];
        $cryptoTDE      = $explodeMarket[1];

        // Correction des quantité pour les 'bid'
        if ($action == 'bid' && ($cryptoREF == 'USDT' || $cryptoREF == 'ETH')) {

            // Récupération de la balance de la monnaie de référence
            $balanceCryptoREF = $this->_tradingAPI->getBalance($cryptoREF);
            $balanceCryptoREF = $balanceCryptoREF->Balance;

            // Conversion de cette balance en bitcoin
            if ($cryptoREF == 'USDT') {

                // Récupération des informations du marketName de la monnaie de référence
                $marketSummary = $this->_getMarket->getMarketSummary('USDT-BTC');
                $marketRate = $marketSummary[0]->Last;

                // Conversion de la balance de la monnaie de référence en Bitcoin
                $walletBTC = $balanceCryptoREF / $marketRate;

                echo 'Wallet USDT en BTC : ' . $walletBTC . $this->_br;
            }

            if ($cryptoREF == 'ETH') {

                // Récupération des informations du marketName de la monnaie de référence
                $marketSummary = $this->_getMarket->getMarketSummary('BTC-ETH');
                $marketRate = $marketSummary[0]->Last;

                // Conversion de la balance de la monnaie de référence en Bitcoin
                $walletBTC = $balanceCryptoREF * $marketRate;
            }

            // Récupération des informations du marketName de la monnaie tradée
            if ($marketName == 'USDT-BTC') {

                $quantityBTC = $quantity;

            } else {

                $marketSummary = $this->_getMarket->getMarketSummary('BTC-' . $cryptoTDE);
                $marketRate = $marketSummary[0]->Last;

                // Conversion de la monnaie tradée en Bitcoin
                $quantityBTC = $quantity * $marketRate;
            }

            // Diff entre la balance du Wallet et la quantité prévue pour cet ordre
            $quantityDiff = $walletBTC - $quantityBTC;

            // Il y un baggie à récupérer, on met l'intégralité du wallet dans la transaction
            if ($quantityDiff > 0 && $quantityDiff < 0.005) {
                $message = 'Récupération d\'un baggie de : ' . $quantityDiff . ' BTC<hr>';
            }

            // Il manque des fonds dans ce wallet pour gérer cette transaction
            if ($quantityDiff < 0 && $quantityBTC > 0.005) {
                $message = 'Fonds inssufisants pour cette transaction, il manque ' . $quantityDiff . ' BTC<hr>';
            }

            if (isset($message)) {

                // Récupération du bon rate
                $marketSummary = $this->_getMarket->getMarketSummary($marketName);
                $marketRate = $marketSummary[0]->Last;

                // Nouvelle quantité exprimée dans la monnaie tradée
                $newQuantityTDE = $balanceCryptoREF / $marketRate;

                $texte  = '<hr>';
                $texte .= $this->colorUI($message, 'error') . '<br><br>';
                $texte .= $this->colorUI('new Quantity BTC : '              . $walletBTC,       'texte') . '<br>';
                $texte .= $this->colorUI('new Quantity '.$cryptoTDE.' : '   . $newQuantityTDE,  'texte') . '<br>';

                $this->affTextUI($texte);

                $quantity = $newQuantityTDE;
            }
        }

        // Correction des quantité pour les 'ask'
        if ($action == 'ask' && $cryptoTDE != 'BTC') {

            // Valeur du wallet
            $balanceCryptoTDE = $this->_tradingAPI->getBalance($cryptoTDE);
            $balanceCryptoTDE = $balanceCryptoTDE->Balance;

            // Valeur de la monnaie tradée rapportée au Bitcoin
            $marketSummary = $this->_getMarket->getMarketSummary('BTC-' . $cryptoTDE);
            $marketRate = $marketSummary[0]->Last;

            // Valeur du wallet exprimée en Bitcoin
            $walletBTC = $balanceCryptoTDE * $marketRate;

            // Valeur de la quantité du trade exprimée en Bitcoin
            $quantityBTC = $quantity * $marketRate;

            // Diff entre la balance du Wallet et la quantité prévue pour cet ordre
            $quantityDiff = $walletBTC - $quantityBTC;

            // Il y un baggie à récupérer, on met l'intégralité du wallet dans la transaction
            if ($quantityDiff > 0 && $quantityDiff < 0.005) {
                $message = 'Récupération d\'un baggie de : ' . $quantityDiff . ' BTC<hr>';
                $newQuantityBTC = $walletBTC;
            }

            // Il manque des fonds dans ce wallet pour gérer cette transaction
            if ($quantityDiff < 0 && $quantityBTC > 0.005) {
                $message = 'Fonds inssufisants pour cette transaction, il manque ' . $quantityDiff . ' BTC<hr>';
                $newQuantityBTC = $walletBTC;
            }

            // Mise à jour de la quantité
            if (isset($newQuantityBTC)) {

                // Nouvelle quantité exprimée dans la monnaie tradée
                $newQuantityTDE = $newQuantityBTC / $marketRate;

                $texte  = '<hr>';
                $texte .= $this->colorUI($message, 'error') . '<br><br>';
                $texte .= $this->colorUI('new Quantity BTC : '              . $newQuantityBTC,  'texte') . '<br>';
                $texte .= $this->colorUI('new Quantity '.$cryptoTDE.' : '   . $newQuantityTDE,  'texte') . '<br>';

                $this->affTextUI($texte);

                $quantity = $newQuantityTDE;
            }
        }

        return $quantity;
    }


    /**
     * Achat au meilleur prix (bids) en ajoutant un peu pour être au plus haut
     */
    public function bidsTop($bid, $coef=0)
    {
        // Démultiplicateur de coef si on bataille avec un autre bot
        $pct = $this->_addAskPct + ($coef * ($this->_addAskPct / $this->_reduceCoef));

        // On ajoute x% au Bid le plus haut
        return $bid + (($bid / 100) * $pct);
    }


    /**
     * Revente au meilleur prix (Asks) en baissant un peu pour être le plus compétitif
     */
    public function asksTop($ask, $coef=0)
    {
        // Démultiplicateur de coef si on bataille avec un autre bot
        $pct = $this->_addAskPct + ($coef * ($this->_addAskPct / $this->_reduceCoef));

        // On retire x% au Bid le plus haut
        return $ask - (($ask / 100) * $pct);
    }


    /**
     * Mise ajout des informations sur un lead
     */
    private function majInfosLead()
    {
        // Requête de récupération des leads
        $req = "SELECT id FROM TMD_leads WHERE statut = :statut AND recalc = :recalc ORDER BY id ASC";
        $sql = $this->_dbh->prepare($req);

        // Requête de récupération des transaction
        $req_trans = "SELECT market, action, uuid FROM TMD_transactions WHERE id_lead = :id_lead";
        $sql_trans = $this->_dbh->prepare($req_trans);

        // Requête de mise à jour des informations du lead
        $req_lead_maj = "UPDATE     TMD_leads

                         SET        price_buy   = :price_buy,
                                    price_sell  = :price_sell,
                                    gain        = :gain,
                                    gain_pct    = :gain_pct,
                                    recalc      = :recalc

                         WHERE      id = :id";
        $sql_lead_maj = $this->_dbh->prepare($req_lead_maj);

        // Boucle sur les leads
        $sql->execute(array(
            ':statut' => 'end',
            ':recalc' => 0,
        ));

        while ($res = $sql->fetch()) {

            $id = $res->id;

            // Récupération des transactions
            $sql_trans->execute(array( ':id_lead' => $id ));
            $res_trans = $sql_trans->fetchAll();

            // Première transaction
            $trans_deb_uuid     = $res_trans[0]->uuid;
            $trans_deb_action   = $res_trans[0]->action;
            $trans_deb_market   = $res_trans[0]->market;

            $getOrderDeb = $this->_tradingAPI->getOrderHistoryInfos($trans_deb_uuid);
            $price_buy   = $getOrderDeb->Price + $getOrderDeb->CommissionPaid;

            // Dernière transaction
            $lastTrans = count($res_trans) - 1;
            $trans_end_uuid     = $res_trans[$lastTrans]->uuid;
            $trans_end_action   = $res_trans[$lastTrans]->action;
            $trans_end_market   = $res_trans[$lastTrans]->market;

            $getOrderEnd = $this->_tradingAPI->getOrderHistoryInfos($trans_end_uuid);

            if ($trans_end_market == 'USDT-BTC') {
                $price_sell = $getOrderEnd->Quantity;
            } else {
                $price_sell = $getOrderEnd->Price - $getOrderEnd->CommissionPaid;
            }

            // Calcul des gains
            $gain       = $price_sell - $price_buy;
            $gain_pct   = (100 / $price_buy) * $gain;

            // Sauvegarde des résultats
            $sql_lead_maj->execute(array(
                ':price_buy'    => $price_buy,
                ':price_sell'   => $price_sell,
                ':gain'         => $gain,
                ':gain_pct'     => $gain_pct,
                ':recalc'       => 1,
                ':id'           => $id,
            ));
        }
    }
}
