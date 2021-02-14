<?php
namespace cryptos\analyse;

/**
 * Analyse des enregistrement des orderBook en BDD
 * Pour établir un classement sur volumes enregistrés
 * dans un interval de temps défini
 *
 * @author Daniel Gomes
 */
class analyseOrderBook
{
    /**
	 * Attributs
	 */
    private $_exchange;                         // Nom de l'exchange

    private $_dbh;                              // Instance PDO

    private $_nameExBDD;                        // Nom de la base de données de l'Exchange
    private $_tablesList;                       // Liste des tables de market en BDD

    private $_prefixeTable  = 'ob_';            // Préfixe des tables de market


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
    }


    /**
     * Classement des markets d'un exchange par évolution des prix dans un interval de temps défini
     *
     * @param       integer     $delta          nombre d'unités d'interval de temps
     * @param       string      $unite          unité de l'interval de temps
     * @param       float       $range          range sur lequel l'orderBook sera analysé
     *
     * @return      array
     */
    public function orderBookVolumes($delta=5, $unite='MINUTE', $range=1)
    {
        // Déclaration du tableau de résultats
        $orderBookVolumes = array();

        foreach ($this->_tablesList as $tableOb) {

            // Récupération de l'orderBook actuel
            $req1 = "SELECT jsonOrderBook, DATE_ADD(date_crea, INTERVAL -$delta $unite) AS oldDateCrea FROM $tableOb ORDER BY id DESC LIMIT 1 ";
            $sql1 = $this->_dbh->query($req1);
            $res1 = $sql1->fetch();

            $jsonOrderBook  = $res1->jsonOrderBook;
            $oldDateCrea    = $res1->oldDateCrea;

            // Récupération de l'orderBook antérieur en tenant compte de l'interval
            $req2 = "SELECT jsonOrderBook FROM $tableOb WHERE date_crea <= :oldDateCrea ORDER BY id DESC LIMIT 1";
            $sql2 = $this->_dbh->prepare($req2);
            $sql2->execute(array(':oldDateCrea' => $oldDateCrea));

            if ($sql2->rowCount() > 0) {

                $res2 = $sql2->fetch();

                $jsonOrderBookOld = $res2->jsonOrderBook;

                // Analyse du dernier orderBook
                $resOrderBook = $this->analyseOrderBookRange($jsonOrderBook, $range);

                // Analyse de l'ancien orderBook (diff interval)
                $resOrderBookOld = $this->analyseOrderBookRange($jsonOrderBookOld, $range);

                // Calcul du pourcentage d'évolution dans l'orderBook
                if ($resOrderBookOld['all'] == 0) {
                    continue;
                }

                $pctAll = (100 / $resOrderBookOld['all']) * ($resOrderBook['all'] - $resOrderBookOld['all']);

                if ($resOrderBookOld['bids'] == 0) {
                    $pctBids = 'null';
                } else {
                    $pctBids = (100 / $resOrderBookOld['bids']) * ($resOrderBook['bids'] - $resOrderBookOld['bids']);
                }

                if ($resOrderBookOld['asks'] == 0) {
                    $pctAsks = 'null';
                } else {
                    $pctAsks = (100 / $resOrderBookOld['asks']) * ($resOrderBook['asks'] - $resOrderBookOld['asks']);
                }

                // Récupération du marketName
                $marketName = $this->recupMarketName($tableOb);

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

                // Tableau retourné par market
                $orderBookVolumes[] = array(
                    'marketName'                => $marketName,
                    'pctAll'                    => $pctAll,
                    'pctBids'                   => $pctBids,
                    'pctAsks'                   => $pctAsks,
                    'resOrderBook'              => $resOrderBook,
                    'resOrderBookOld'           => $resOrderBookOld,
                    'orderBookExchange'         => $orderBookExchange,
                    'pageCryptoview'            => $pageCryptoview,
                    /*
                    'orderBookCryptoView'       => $orderBookCryptoview,
                    'marketHistoryCryptoview'   => $marketHistoryCryptoview,
                    'linkGraph'                 => $graphTradinView,
                    */
                    //'delta'                   => $delta,
                    //'unite'                   => $unite,
                );
            }
        }

        // Tableau trié sur les pourcentage
        $rang = array();
        if (count($orderBookVolumes) > 0) {
            foreach ($orderBookVolumes as $key => $val) {
                $rang[$key]  = $val['pctAll'];
            }

            // Trie les données par rang décroissant sur la colonne 'pct'
            array_multisort($rang, SORT_DESC, $orderBookVolumes);
        }

        return $orderBookVolumes;
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

        // Bid le plus haut
        $bids = $orderBook->bids;

        // Ask le plus bas
        $asks = $orderBook->asks;

        $bidRateMax = floatval($bids[0][0]);
        $askRateMin = floatval($asks[0][0]);

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

        return array(
            'bids' => $bidsCumul,
            'asks' => $asksCumul,
            'all'  => $bidsCumul + $asksCumul,
        );
    }


    /**
     * Récupération du marketName avec le nom de la table
     */
    private function recupMarketName($tableOb)
    {
        // Récupération du marketName
        $marketName = explode($this->_prefixeTable, $tableOb);
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
