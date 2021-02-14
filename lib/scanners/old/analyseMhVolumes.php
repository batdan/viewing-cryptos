<?php
namespace cryptos\analyse;

/**
 * Analyse des enregistrement de marketHistory en BDD
 * Pour établir un classement sur les volumes échangés
 * dans un interval de temps défini
 *
 * @author Daniel Gomes
 */
class analyseMhVolumes
{
    /**
	 * Attributs
	 */
    private $_exchange;                         // Nom de l'exchange

    private $_dbh;                              // Instance PDO

    private $_nameExBDD;                        // Nom de la base de données de l'Exchange
    private $_tablesList;                       // Liste des tables de market en BDD

    private $_prefixeTable  = 'mh_';            // Préfixe des tables de market

    private $_cyrptoRefList;                    // Liste des monnaies de référence de l'exchange
    private $_cyrptoRefLast;                    // Last des cryptos de référence pour la conversion en BTC



    /**
	 * Constructeur
	 */
	public function __construct($exchange)
	{
        // Nom de l'exchange
        $this->_exchange = $exchange;

        // Nom de la base de données de l'Exchange
        $this->_nameExBDD = 'cryptos_ex_' . $exchange;

        // Instance PDO
        $this->_dbh = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Récupération de la liste des tables de market en BDD
        $this->tableList();

        // Récupération de la liste des monnaies de référence de l'exchange
        $this->cyrptoRefList();

        // Récupération des 'Last' des monnaies de référence
        $this->lastCryptoRef();
    }


    /**
     * Classement des markets d'un exchange par évolution des prix dans un interval de temps défini
     *
     * @param       integer     $delta          nombre d'unités d'interval de temps
     * @param       string      $unite          Unité de l'interval de temps
     *
     * @return      array
     */
    public function checkVolume($delta=5, $unite='MINUTE')
    {
        // Déclaration du tableau de résultats
        $checkVolume = array();

        // Récupération de la plage pour cet interval
        $plage = $this->plage($delta, $unite);

        foreach ($this->_tablesList as $tableMh) {

            // Récupération du marketName
            $marketName = $this->recupMarketName($tableMh);

            // Récupération de la monnaie de référence
            $crytposMarket = $this->recupCryptoTdeRef($marketName);
            $cryptoRef = $crytposMarket['ref'];

            // Requete de récupération de la somme de volumes
            $req = "SELECT SUM(total) AS volume

            FROM        $tableMh

            WHERE       orderType   =  :orderType
            AND         timestampEx >= :date_deb
            AND         timestampEx <  :date_end";

            // Récupération de la somme des 'buy'
            $sql = $this->_dbh->prepare($req);
            $sql->execute(array(
                ':orderType' => 'BUY',
                ':date_deb'  => $plage['deb'],
                ':date_end'  => $plage['end'],
            ));
            $res = $sql->fetch();
            $REFvolBuy = $res->volume;
            if ($REFvolBuy == '') {
                $REFvolBuy = 0;
            }

            // Récupération de la somme des 'sell'
            $sql = $this->_dbh->prepare($req);
            $sql->execute(array(
                ':orderType' => 'SELL',
                ':date_deb'  => $plage['deb'],
                ':date_end'  => $plage['end'],
            ));
            $res = $sql->fetch();
            $REFvolSell = $res->volume;
            if ($REFvolSell == '') {
                $REFvolSell = 0;
            }

            if ($cryptoRef == 'USD' || $cryptoRef == 'USDT') {
                $BTCvolBuy  = $REFvolBuy  / $this->_cyrptoRefLast[$cryptoRef];
                $BTCvolSell = $REFvolSell / $this->_cyrptoRefLast[$cryptoRef];
            } elseif ($cryptoRef != 'BTC' && $cryptoRef != 'USD' && $cryptoRef != 'USDT') {
                $BTCvolBuy  = $REFvolBuy  * $this->_cyrptoRefLast[$cryptoRef];
                $BTCvolSell = $REFvolSell * $this->_cyrptoRefLast[$cryptoRef];
            } else {
                $BTCvolBuy  = $REFvolBuy;
                $BTCvolSell = $REFvolSell;
            }

            // Calcul du volume échangé dans l'interval de temps (en Bitcoin et dans la monnaie de référence)
            $REFvolTotal = $REFvolBuy + $REFvolSell;
            $BTCvolTotal = $BTCvolBuy + $BTCvolSell;

            // Lien vers la page de market de l'exchange
            $urlOrderBookExchange = array();

            switch ($this->_exchange) {
                case 'bittrex' :
                    $urlOrderBookExchange[$this->_exchange] = 'https://bittrex.com/Market/Index?MarketName=' . $marketName;
                    break;

                case 'bitfinex' ;
                    $expMarket = explode('-', $marketName);
                    $getMarket = $expMarket[1] . $expMarket[0];
                    $urlOrderBookExchange[$this->_exchange] = 'https://www.bitfinex.com/trading/' . $getMarket;
                    break;

                case 'poloniex' ;
                    $getMarket = str_replace('-', '_', strtolower($marketName));
                    $urlOrderBookExchange[$this->_exchange] = 'https://poloniex.com/exchange#' . $getMarket;
                    break;
            }

            $libOrderBookExchange       = 'OrderBook ' . ucfirst($this->_exchange) . ' : ' . $marketName;
            $orderBookExchange          = '<a href="' . $urlOrderBookExchange[$this->_exchange] . '" target="_blank">' . $libOrderBookExchange . '</a>';

            // Liens vers l'orderBook et le marketHistory de cryptoview
            $linkCryptoview             = 'https://viewing.dpy.ovh/app';
            $getCryptoview              = 'exchange=' . $this->_exchange . '&market=' . $marketName;

            /*
            $orderBookCryptoview        = '<a href="' . $linkCryptoview . '/graphOrderBook.php?' . $getCryptoview . '" target="_blank">OrderBook Cryptoview : ' . $marketName . '</a>';
            $marketHistoryCryptoview    = '<a href="' . $linkCryptoview . '/graphMarketHistory.php?' . $getCryptoview . '" target="_blank">MarketHistory Cryptoview : '. $marketName . '</a>';

            // Lien vers le graph de tradingView
            $studies = array(
                'BB@tv-basicstudies', 
                'MACD@tv-basicstudies',
            );

            $graphTradinView = \cryptos\graph\graphTradingView::configurator($this->_exchange, $marketName, 'link', $studies);
            */

            // Nouvelle page cryptoview
            $pageCryptoview = '<a href="' . $linkCryptoview . '/scanners.php?' . $getCryptoview . '" target="_blank">Nouvelle page Cryptoview : ' . $marketName . '</a>';

            // Orientation
            if ($BTCvolBuy > $BTCvolSell) {

                $pct = (100 / $BTCvolBuy) * ($BTCvolBuy - $BTCvolSell);
                $pct = round($pct, 2);

                $orientation = '<span style="color:#2ECD57;">hausse : ' . $pct . '%</span>';

            } elseif ($BTCvolBuy < $BTCvolSell) {

                $pct = (100 / $BTCvolSell) * ($BTCvolSell - $BTCvolBuy);
                $pct = round($pct, 2);

                $orientation = '<span style="color:#CD2E2E;">baisse : ' . $pct . '%</span>';

            } else {

                $orientation = 'null';
            }


            $checkVolume[] = array(
                'orientationVol'            => $orientation,
                'marketName'                => $marketName,
                'BTCvolumeTotal'            => $BTCvolTotal,
                'BTCvolumeBuy'              => $BTCvolBuy,
                'BTCvolumeSell'             => $BTCvolSell,
                'REFvolumeTotal'            => $REFvolTotal,
                'REFvolumeBuy'              => $REFvolBuy,
                'REFvolumeSell'             => $REFvolSell,
                'orderBookExchange'         => $orderBookExchange,
                'pageCryptoview'            => $pageCryptoview,
                /*
                'orderBookCryptoView'       => $orderBookCryptoview,
                'marketHistoryCryptoview'   => $marketHistoryCryptoview,
                'linkGraph'                 => $graphTradinView,
                */
                'plageDeb'                  => $plage['deb'],
                'plageEnd'                  => $plage['end'],
            );
        }

        // Tableau trié sur les volumes
        $rang = array();
        if (count($checkVolume) > 0) {
            foreach ($checkVolume as $key => $val) {
                $rang[$key] = $val['BTCvolumeTotal'];
            }

            // Trie les données par rang décroissant sur la colonne 'pct'
            array_multisort($rang, SORT_DESC, $checkVolume);
        }


        return $checkVolume;
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

        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {
            $this->_tablesList[] = $res->exTable;
        }
    }


    /**
     * Récupération des plages d'interval pour les requêtes
     * de calcul des volumes des 'buy' et des 'sell'
     *
     * @param       integer     $delta          nombre d'unités d'interval de temps
     * @param       string      $unite          Unité de l'interval de temps
     */
    private function plage($delta, $unite)
    {
        switch ($unite) {

            case 'SECOND'   : $interval = $delta;                   break;
            case 'MINUTE'   : $interval = $delta * 60;              break;
            case 'HOUR'     : $interval = $delta * 60 * 60;         break;
            case 'DAY'      : $interval = $delta * 60 * 60 * 24;    break;
        }

        $dateTime   = gmdate('Y-m-d H:i:s');

        $d = new \DateTime($dateTime);
        $timestamp = $d->getTimestamp();

        // dateTime début UTC
        $deb = $timestamp - $interval;
        $d->setTimestamp($deb);
        $debDateTimeUTC = $d->format('Y-m-d H:i:s');

        // dateTime fin UTC
        $end = $timestamp;
        $d->setTimestamp($end);
        $endDateTimeUTC = $d->format('Y-m-d H:i:s');

        $interval = array(
            'deb' => $debDateTimeUTC,
            'end' => $endDateTimeUTC,
        );

        return $interval;
    }


    /**
     * Récupération de la liste des monnaies de référence de l'exchange
     */
    private function cyrptoRefList()
    {
        $this->_cyrptoRefList = array();

        foreach ($this->_tablesList as $tableMh) {

            // Récupération du marketName
            $marketName = $this->recupMarketName($tableMh);

            // Récupération de la monnaie de référence
            $crytposMarket = $this->recupCryptoTdeRef($marketName);

            if (! in_array($crytposMarket['ref'], $this->_cyrptoRefList)) {
                $this->_cyrptoRefList[] = $crytposMarket['ref'];
            }
        }
    }


    /**
     * Récupération des 'Last' des monnaies de référence
     * Afin de réaliser la conversion en Bitcoin
     */
    private function lastCryptoRef()
    {
        $this->_cyrptoRefLast = array();

        foreach ($this->_cyrptoRefList as $cryptoRef) {

            if ($cryptoRef != 'BTC') {

                if ($cryptoRef == 'USD' || $cryptoRef == 'USDT') {
                    $tableName = 'market_' . strtolower($cryptoRef) . '_btc';
                } else {
                    $tableName = 'market_btc_' . strtolower($cryptoRef);
                }

                $req = "SELECT last FROM $tableName ORDER BY id DESC LIMIT 1";
                $sql = $this->_dbh->query($req);

                if ($sql->rowCount() > 0) {
                    $res  = $sql->fetch();
                    $this->_cyrptoRefLast[$cryptoRef] = $res->last;
                }
            }
        }
    }


    /**
     * Récupération du marketName avec le nom de la table
     */
    private function recupMarketName($tableMh)
    {
        // Récupération du marketName
        $marketName = explode($this->_prefixeTable, $tableMh);
        $marketName = str_replace('_', '-', $marketName[1]);
        $marketName = strtoupper($marketName);

        return $marketName;
    }


    /**
     * Récupération de la monnaie de référence et tradée d'un market
     */
    private function recupCryptoTdeRef($marketName)
    {
        $expMarket  = explode('-', $marketName);

        return array(
            'ref' => $expMarket[0],
            'tde' => $expMarket[1],
        );
    }
}
