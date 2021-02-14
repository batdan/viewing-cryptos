<?php
namespace cryptos\graph;

/**
 * Préparation des données pour l'affichage d'un graph d'orderBook
 *
 * @author Daniel Gomes
 */
class graphOrderBook
{
    /**
     * Permet d'extrapoler des données retournées par l'API orderBook
     * afin de gérer le cumul des quantités.
     * Le but étant d'obtenir des courbes qui s'épaissisent
     * à l'image de tous les graphiques d'orderBook
     * @param       object      $dbh                Instance PDO
     *
     * @param       string      $marketName         Nom du market
     * @param       float       $range              Pourcentage du range de l'orderBook analysé
     * @param       integer     $timeMachine        Gestion du décalage de temps en minutes pour la requête Time Machine
     *
     * @return      array
     */
    private static function orderBookCumulVol($nameExBDD, $dbh, $marketName, $range=1, $timeMachine='', $crypto, $compareEx='0')
    {
        $expMarket = explode('-', $marketName);
        $cryptoRef = $expMarket[0];
        $cryptoTde = $expMarket[1];

        $last = '';

        $tableOrderBookName =  'ob_' . str_replace('-', '_', strtolower($marketName));
        $tableMarketName = 'market_' . str_replace('-', '_', strtolower($marketName));

        // Changement de base s'il s'agit d'une comparaison d'exchange
        if ($compareEx != '0') {

            $expCompareEx  = explode('|', $compareEx);
            $compareExName = $expCompareEx[0];

            $nameExBDD = 'cryptos_ex_' . $compareExName;
            $tableOrderBookName = $expCompareEx[1];
            $tableMarketName = str_replace('ob_', 'market_', $tableOrderBookName);
        }

        // On vérifie si la table existe toujours
        $tableExist = false;

        $req_check = "SELECT  table_name AS exTable

                      FROM    information_schema.tables

                      WHERE   table_name    = :table_name
                      AND     table_schema  = :table_schema";

        $sql_check = $dbh->prepare($req_check);
        $sql_check->execute(array(
            ':table_name'   => $tableOrderBookName,
            ':table_schema' => $nameExBDD,
        ));

        if ($sql_check->rowCount() > 0) {
            $tableExist = true;
        }

        if ($tableExist === true) {

            // OrderBook Time Machine
            $addReq = '';
            if (! empty($timeMachine)) {

                // Gestion du décalage de temps en minutes pour la requête Time Machine
                $d = new \DateTime();
                $timestamp = $d->getTimestamp();
                $d->setTimestamp($timestamp - ($timeMachine * 60));
                $dateTimeMachine = $d->format('Y-m-d H:i');

                $addReq = "WHERE date_crea LIKE '$dateTimeMachine%'";
            }

            // Récupération de l'orderBook
            $req = "SELECT jsonOrderBook FROM $tableOrderBookName $addReq ORDER BY id DESC LIMIT 1";
            $sql = $dbh->query($req);
            $res = $sql->fetch();

            $orderBook = json_decode($res->jsonOrderBook);
            $origine   = 'bdd';

            // Récupération du last pour ne pas afficher les ordres "hors-champ"
            try {
                $req  = "SELECT last FROM $tableMarketName $addReq ORDER BY id DESC LIMIT 1";
                $sql  = $dbh->query($req);
                $res  = $sql->fetch();
                $last = $res->last;

            } catch (\Exception $e) {
                // error_log($e);
            }

        } else {

            $orderBook = \cryptos\api\bittrex\getOrderBook::getOrderBook($marketName, 'both');
            $origine   = 'ws';
        }

        $bids = $orderBook->bids;
        $asks = $orderBook->asks;

        $bidRateMax = floatval($bids[0][0]);
        $askRateMin = floatval($asks[0][0]);

        // Suppression des ordres "hors-champ"
        if (isset($last) && $bidRateMax > $last) {
            $bidRateMax = $last;
        }

        if (isset($last) && $askRateMin < $last) {
            $askRateMin = $last;
        }

        // Calcul de la valeur "middle"
        $middle = ($bidRateMax + $askRateMin) / 2;

        // Comptage du nombre de chiffres à afficher après la virgule
        $nbDecimals = 2;

        if (strstr(strval($bidRateMax), 'E-')) {
            $expBidMax  = explode('E-', strval($bidRateMax));

            switch($expBidMax[1])
            {
                case '8' : $nbDecimals = 11;    break;
                case '7' : $nbDecimals = 10;    break;
                case '6' : $nbDecimals = 9;     break;
                case '5' : $nbDecimals = 8;     break;
                case '4' : $nbDecimals = 8;     break;
            }
        } else {

            $int = floor($bidRateMax);
            $dec = $bidRateMax - $int;

            if ($int > 10) {
                $nbDecimals = 2;
            } else {
                $nbDecimals = 8;
            }
        }

        $decimalsFormat = '%.' . $nbDecimals . 'f';

        $middle = strval($middle);
        $middle = sprintf($decimalsFormat, $middle);

        // Bid le plus bas en fonction du range choisi
        $bidRateMin = $middle - ($middle / 100) * $range;

        // Ask le plus haut en fonction du range choisi
        $askRateMax = $middle + ($middle / 100) * $range;

        $bidsCumul = array();
        $asksCumul = array();

        // On refait le tableau de bids et des asks en cumulant les quantités
        if (count($bids) > 0) {
            $i=0;
            foreach ($bids as $bid) {

                $bidRate     = floatval($bid[0]);
                $bidQuantity = floatval($bid[1]);

                if ($bidRate > $bidRateMin) {

                    if ($i==0) {
                        $bidsCumul[$i]['Rate'] = $bidRate;

                        if ($cryptoRef == $crypto) {
                            $bidsCumul[$i]['Quantity'] = $bidQuantity * $bidRate;
                        } else {
                            $bidsCumul[$i]['Quantity'] = $bidQuantity;
                        }

                    } else {
                        $bidsCumul[$i]['Rate'] = $bidRate;

                        if ($cryptoRef == $crypto) {
                            $bidsCumul[$i]['Quantity'] = ($bidQuantity * $bidRate) + $bidsCumul[$i-1]['Quantity'];
                        } else {
                            $bidsCumul[$i]['Quantity'] = $bidQuantity + $bidsCumul[$i-1]['Quantity'];
                        }
                    }

                    $i++;
                }
            }
        }

        if (count($asks) > 0) {
            $i=0;
            foreach ($asks as $ask) {

                $askRate     = floatval($ask[0]);
                $askQuantity = floatval($ask[1]);

                if ($askRate < $askRateMax) {

                    if ($i==0) {
                        $asksCumul[$i]['Rate'] = $askRate;

                        if ($cryptoRef == $crypto) {
                            $asksCumul[$i]['Quantity'] = $askQuantity * $askRate;
                        } else {
                            $asksCumul[$i]['Quantity'] = $askQuantity;
                        }

                    } else {
                        $asksCumul[$i]['Rate'] = $askRate;

                        if ($cryptoRef == $crypto) {
                            $asksCumul[$i]['Quantity'] = ($askQuantity * $askRate) + $asksCumul[$i-1]['Quantity'];
                        } else {
                            $asksCumul[$i]['Quantity'] = $askQuantity + $asksCumul[$i-1]['Quantity'];
                        }
                    }

                    $i++;
                }
            }
        }

        return array(
            'bids'          => $bidsCumul,
            'asks'          => $asksCumul,
            'middle'        => $middle,
            'nbDecimals'    => $nbDecimals,
            'origine'       => $origine,
            'last'          => $last,
        );
    }


    /**
     * Méthode cURL pour la création du tableau de données
     * Compilation des données déportée sur le serveur de collecte
     */
    public static function prepareDataOrderBookCurl($exchange, $marketName, $range, $timeMachine, $crypto, $compareEx)
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$exchange] . '/charts/chartOrderBook.php';

        $postFields = array(
            'exchange'      => $exchange,
            'marketName'    => $marketName,
            'range'         => $range,
            'timeMachine'   => $timeMachine,
            'crypto'        => $crypto,
            'compareEx'     => $compareEx,
        );

        $dataSet = \core\curl::curlPost($urlCurl, $postFields);

        return json_decode($dataSet, true);
    }


    /**
     * Préparation des données pour l'affichage de l'orderBook
     *
     * @param       string      $exchange           Nom de l'exchange
     * @param       string      $marketName         Nom du market
     * @param       float       $range              Pourcentage du range de l'orderBook analysé
     * @param       integer     $timeMachine        Affichage de la timeMachine - comparaison avec un orderBook antérieur
     * @param       string      $crypto             Crypto-monnaie servant de référence pour yAxis (ordonnée)
     * @param       string      $compareEx          Permet la comparaison avec l'orderBook d'un autre exchange
     *
     * @return      array
     */
    public static function prepareDataOrderBook($exchange, $marketName, $range=1.2, $timeMachine=0, $crypto, $compareEx='0')
    {
        // Nom de la base de données de l'Exchange
        $nameExBDD = 'cryptos_ex_' . $exchange;

        // Ouverture de l'instance PDO
        $dbh = \core\dbSingleton::getInstance($nameExBDD);

        // Récupération des data cumulées
        $orderBookCumulVol = self::orderBookCumulVol($nameExBDD, $dbh, $marketName, $range, '', $crypto);

        $tableMarketName = 'market_' . str_replace('-', '_', strtolower($marketName));

        // Récupération du last pour ne pas afficher les ordres "hors-champ"
        $last = $orderBookCumulVol['last'];

        // Bids ----------------------------------------------------------------
        $prepareBids = array();

        foreach ($orderBookCumulVol['bids'] as $bidCumul) {
            if (!empty($last) && $bidCumul['Rate'] <= $last) {
                $prepareBids[] = array($bidCumul['Rate'], $bidCumul['Quantity']);
            }
        }

        $prepareBids = array_reverse($prepareBids);

        // Asks ----------------------------------------------------------------
        $prepareAsks = array();

        foreach ($orderBookCumulVol['asks'] as $askCumul) {
            if (!empty($last) && $askCumul['Rate'] >= $last) {
                $prepareAsks[] = array($askCumul['Rate'], $askCumul['Quantity']);
            }
        }

        /**
         * Récupération des datas pour afficher l'orderBook Time Machine
         */
        $prepareBidsTM = array();
        $prepareAsksTM = array();
        $showTimeMachine = false;

        if ($timeMachine > 0 && is_numeric($timeMachine)) {

            $showTimeMachine = true;

            // Récupération des data cumulées pour le Time Machine
            $orderBookCumulVolTM = self::orderBookCumulVol($nameExBDD, $dbh, $marketName, $range, $timeMachine, $crypto);

            // Récupération du last pour ne pas afficher les ordres "hors-champ"
            $lastTM = $orderBookCumulVolTM['last'];

            // Bids TM ---------------------------------------------------------
            foreach ($orderBookCumulVolTM['bids'] as $bidCumul) {
                if (!empty($lastTM) && $bidCumul['Rate'] <= $lastTM) {
                    $prepareBidsTM[] = array($bidCumul['Rate'], $bidCumul['Quantity']);
                }
            }

            $prepareBidsTM = array_reverse($prepareBidsTM);

            // Asks TM ---------------------------------------------------------
            $prepareAsksTM = array();

            foreach ($orderBookCumulVolTM['asks'] as $askCumul) {
                if (!empty($lastTM) && $askCumul['Rate'] >= $lastTM) {
                    $prepareAsksTM[] = array($askCumul['Rate'], $askCumul['Quantity']);
                }
            }
        }

        /**
         * Récupération des datas pour afficher l'orderBook d'un autre exchange pour comparaison
         */
        $prepareBidsCE = array();
        $prepareAsksCE = array();
        $showCompareExchange = false;
        $compareExName = '';

        if ($compareEx != '0') {

            $showCompareExchange = true;

            // Nom de la base de données de l'Exchange
            $expCompareEx  = explode('|', $compareEx);
            $compareExName = $expCompareEx[0];

            $nameExBDDCompare = 'cryptos_ex_' . $compareExName;

            // Ouverture de l'instance PDO
            $dbhEx = \core\dbSingleton::getInstance($nameExBDDCompare);

            // Récupération des data cumulées pour l'exchange de comparaison
            $orderBookCumulVolCE = self::orderBookCumulVol($nameExBDD, $dbhEx, $marketName, $range, $timeMachine, $crypto, $compareEx);

            // Récupération du last pour ne pas afficher les ordres "hors-champ"
            $lastCE = $orderBookCumulVolCE['last'];

            // Bids TM ---------------------------------------------------------
            foreach ($orderBookCumulVolCE['bids'] as $bidCumul) {
                if (!empty($lastCE) && $bidCumul['Rate'] <= $lastCE) {
                    $prepareBidsCE[] = array($bidCumul['Rate'], $bidCumul['Quantity']);
                }
            }

            $prepareBidsCE = array_reverse($prepareBidsCE);

            // Asks TM ---------------------------------------------------------
            $prepareAsksCE = array();

            foreach ($orderBookCumulVolCE['asks'] as $askCumul) {
                if (!empty($lastCE) && $askCumul['Rate'] >= $lastCE) {
                    $prepareAsksCE[] = array($askCumul['Rate'], $askCumul['Quantity']);
                }
            }
        }

        // Fermeture de l'instance PDO
        // \core\dbSingleton::closeInstance($nameExBDD);

        return array(
            'exchange'      => ucfirst($exchange),
            'bids'          => $prepareBids,
            'asks'          => $prepareAsks,
            'bidsTM'        => $prepareBidsTM,
            'asksTM'        => $prepareAsksTM,
            'showTM'        => $showTimeMachine,
            'nameCE'        => ucfirst($compareExName),
            'bidsCE'        => $prepareBidsCE,
            'asksCE'        => $prepareAsksCE,
            'showCE'        => $showCompareExchange,
            'middle'        => $orderBookCumulVol['middle'],
            'nbDecimals'    => $orderBookCumulVol['nbDecimals'],
            'origine'       => $orderBookCumulVol['origine'],
            'yAxisUnit'     => $crypto,
        );
    }


    /**
     * Récupération de la liste des Exchanges pouvant être comparés
     */
    public static function compareExchangeList($currentExchange, $marketName)
    {
        //$test = array();

        // Ouverture de l'instance PDO de la base 'cryptoview'
        $dbh = \core\dbSingleton::getInstance();

        $req = "SELECT name FROM exchanges WHERE name <> :currentExchange";
        $sql = $dbh->prepare($req);
        $sql->execute(array(
            ':currentExchange' => $currentExchange,
        ));

        $expMarket = explode('-', $marketName);
        $cryptoRef = $expMarket[0];
        $cryptoTde = $expMarket[1];

        $cryptoRefGeneric = self::genericName($currentExchange, $cryptoRef);
        $cryptoTdeGeneric = self::genericName($currentExchange, $cryptoTde);

        /*
        $test[$currentExchange] = array(
            '$cryptoRef' => $cryptoRef,
            '$cryptoTde' => $cryptoTde,
            '$cryptoRefGeneric' => $cryptoRefGeneric,
            '$cryptoTdeGeneric' => $cryptoTdeGeneric,
        );
        */

        $exchangeList = array();

        while ($res = $sql->fetch()) {

            // Nom de la base de données de l'Exchange
            $bddExchange = 'cryptos_ex_' . $res->name;

            // Ouverture de l'instance PDO de l'exchange
            $dbhEx = \core\dbSingleton::getInstance($bddExchange);

            // Nom de la table recherchée
            $tableObMarket = 'ob_' . strtolower(str_replace('-', '_', $marketName));

            $req2 = "SELECT  table_name AS exTable

                     FROM    information_schema.tables

                     WHERE   table_name LIKE 'ob_%'
                     AND     table_schema = :table_schema";

            $sql2 = $dbhEx->prepare($req2);
            $sql2->execute(array(
                ':table_schema' => $bddExchange,
            ));

            while ( $res2 = $sql2->fetch() ) {

                $exTable = $res2->exTable;

                $exp_exTable = explode('_', $exTable);
                $cryptoRef_exTable = strtoupper($exp_exTable[1]);
                $cryptoTde_exTable = strtoupper($exp_exTable[2]);

                $cryptoRefGeneric_exTable = self::genericName($res->name, $cryptoRef_exTable);
                $cryptoTdeGeneric_exTable = self::genericName($res->name, $cryptoTde_exTable);

                /*
                $test[$res->name][] = array(
                    'count' => $sql2->rowCount(),
                    'table' => $exTable,
                    '$cryptoRef_exTable' => $cryptoRef_exTable,
                    '$cryptoTde_exTable' => $cryptoTde_exTable,
                    '$cryptoRefGeneric_exTable' => $cryptoRefGeneric_exTable,
                    '$cryptoTdeGeneric_exTable' => $cryptoTdeGeneric_exTable,
                );
                */

                if (($cryptoRefGeneric == $cryptoRefGeneric_exTable) && ($cryptoTdeGeneric == $cryptoTdeGeneric_exTable)) {
                    $exchangeList[ $res->name . '|' . $exTable ] = $res->name;
                }
            }

            // Vérification s'il y a des markets identiques mais avec les variantes USD/USDT
            if (! isset($exchangeList[$res->name]) && ($cryptoRef == 'USD' || $cryptoRef == 'USDT')) {

                if ($cryptoRef == 'USD') {
                    $similarCryptoRef = 'USDT';
                    $similarTableObMarket = 'ob_usdt_' . strtolower($cryptoTde);
                }
                if ($cryptoRef == 'USDT') {
                    $similarCryptoRef = 'USD';
                    $similarTableObMarket = 'ob_usd_' . strtolower($cryptoTde);
                }

                $req3 = "SELECT  table_name AS exTable

                         FROM    information_schema.tables

                         WHERE   table_name   = :table_name
                         AND     table_schema = :table_schema";

                $sql3 = $dbhEx->prepare($req3);

                $sql3->execute(array(
                    ':table_name'   => $similarTableObMarket,
                    ':table_schema' => $bddExchange,
                ));

                if ($sql3->rowCount() > 0) {
                    $exchangeList[ $res->name . '|' . $similarTableObMarket ] = $res->name . ' (' . $similarCryptoRef . ')';
                }
            }
        }

        return array(
            'countExchangeList'  => count($exchangeList),
            'exchangeList'       => $exchangeList,
            //'test'               => $test,
        );
    }


    /**
     * Gestion des noms différents de marchés en fonction des exchanges
     */
    public static function genericName($exchange , $currency)
    {
        switch ($exchange) {
            case 'bittrex' :

                switch ($currency)
                {
                    case 'BCC'  : $genericName = 'BCH';         break;
                    default     : $genericName = $currency;     break;
                }

                break;

            case 'bitfinex' :

                switch ($currency)
                {
                    default     : $genericName = $currency;     break;
                }

                break;

            case 'poloniex' :

                switch ($currency)
                {
                    case 'STR'  : $genericName = 'XLM';         break;
                    default     : $genericName = $currency;     break;
                }

                break;

            default :
                $genericName = $currency;     break;

        }

        return $genericName;
    }


    /**
     * Code Javascript
     */
    public static function highchartsJS($theme='dark')
    {
        switch ($theme)
        {
            case 'light' :

                $loader                 = 'appLoader1.gif';

                $chartBackground        = " linearGradient: [0, 0, 500, 500],
                                            stops: [
                                                [0, 'rgba(225, 225, 225)'],
                                                [1, 'rgba(255, 255, 255)']
                                            ]";

                $chartBorderRadius      = '5px';

                $legendItemColor        = '#333';
                $legendItemHoverColor   = '#cd2e2e';
                $legendItemHiddenColor  = '#ccc';

                $toltipBackground       = '#fff';
                $toltipColor            = '#333';

                $yAxisGridLineColor     = '#ccc';
                $yAxisLabelsColor       = '#333';
                $yAxisTickColor         = '#333';
                $yAxisTitlesColor       = '#333';

                $xAxisLabelsColor       = '#333';
                $xAxisLineColor         = '#333';
                $xAxisTickColor         = '#333';

                $midPlotLineColor       = '#cd2e2e';
                $midPlotTextColor       = '#333';

                $serieBidsColor         = '#33c324';
                $serieAsksColor         = '#cd2e2e';
                $serieBidsTMColor       = '#ff6600';
                $serieAsksTMColor       = '#0078ff';

                break;

            case 'dark'  :

                $loader                 = 'appLoader-dark.gif';

                $chartBackground        = " linearGradient: [0, 0, 500, 500],
                                            stops: [
                                                [0, 'rgba(155, 155, 155, 0.5)'],
                                                [1, 'rgba(0, 0, 0, 0.6)']
                                            ]";

                $chartBorderRadius      = '5px';

                $legendItemColor        = '#fff';
                $legendItemHoverColor   = '#eda738';
                $legendItemHiddenColor  = '#666';

                $toltipBackground       = 'rgba(0, 0, 0, 0.7)';
                $toltipColor            = '#ccc';

                $yAxisGridLineColor     = 'rgba(155, 155, 155, 0.3)';
                $yAxisLabelsColor       = '#ccc';
                $yAxisTickColor         = '#ccc';
                $yAxisTitlesColor       = '#ccc';

                $xAxisLabelsColor       = '#ccc';
                $xAxisLineColor         = 'rgba(155, 155, 155, 0.3)';
                $xAxisTickColor         = 'rgba(155, 155, 155, 0.3)';

                $midPlotLineColor       = '#cd2e2e';
                $midPlotTextColor       = '#ddd';

                $serieBidsColor         = '#2ecd57';
                $serieAsksColor         = '#cd2e2e';
                $serieBidsTMColor       = '#eda738';
                $serieAsksTMColor       = '#3effd8';

                break;
        }

        $js = <<<eof

            // Récupération monnaie tradee
            var marketName = $('#marketName').val();
            var expMarket  = marketName.split('-');
            var cryptoTDE  = expMarket[1];


            //
            // Affichage du graphique
            //
            function ob_affHighcharts(data)
            {
                if (currentPage() == 'index.php') {
                    var graphHeight = $(window).height() - 244;
                    if (graphHeight < 360) {
                        graphHeight = 360;
                    }
                }

                if (currentPage() == 'multiCharts.php') {
                    var graphHeight = (($(window).height() - 50) / 2) - 100;
                }

                Highcharts.chart('ob_container', {
                    chart: {
                        //type: 'areaspline',
                        type: 'area',
                        backgroundColor: {
                            $chartBackground
                        },
                        borderRadius: '$chartBorderRadius',
                        height: graphHeight,
                    },
                    title: {
                        text: '',
                    },
                    legend: {
                        itemStyle: {
                            color: '$legendItemColor'
                        },
                        itemHoverStyle: {
                            color: '$legendItemHoverColor'
                        },
                        itemHiddenStyle: {
                            color: '$legendItemHiddenColor'
                        }
                    },
                    tooltip: {
                        shared: true,
                        valueSuffix: ' units ' + data.yAxisUnit,
                        style: {
                            color: '$toltipColor',
                        },
                        backgroundColor: '$toltipBackground'
                    },
                    yAxis: {
                        gridLineColor: '$yAxisGridLineColor',
                        labels: {
                            style: {
                                color: '$yAxisLabelsColor'
                            }
                        },
                        tickColor: '$yAxisTickColor',
                        title: {
                            text: 'Units ' + data.yAxisUnit,
                            style: {
                                color: '$yAxisTitlesColor'
                            }
                        }
                    },
                    xAxis: {
                        labels: {
                            style: {
                                color: '$xAxisLabelsColor'
                            },
                            formatter: function() {
                                return ((this.value).toFixed(data.nbDecimals));
                            },
                            rotation: -45
                        },
                        lineColor: '$xAxisLineColor',
                        tickColor: '$xAxisTickColor',
                        plotLines: [{
                            dashStyle: 'ShortDash',
                            color: '$midPlotLineColor',
                            width: 1,
                            value: data.middle,
                            zIndex: 5,
                            label: {
                                style: {
                                    color: '$midPlotTextColor',
                                    fontSize: '13px',
                                },
                                text: data.middle,
                                y: 15,
                                x: -6,
                                rotation: 270,
                                textAlign: 'right'
                            }
                        }],
                    },
                    series: [{
                        name: 'Bids',
                        data: data.bids,
                        color: '$serieBidsColor'
                    }, {
                        name: 'Asks',
                        data: data.asks,
                        color: '$serieAsksColor'
                    }, {
                        name: 'Bids Time Machine',
                        data: data.bidsTM,
                        color: '$serieBidsTMColor',
                        fillOpacity: 0,
                        dashStyle: 'ShortDot',
                        showInLegend: data.showTM
                    }, {
                        name: 'Asks Time Machine',
                        data: data.asksTM,
                        color: '$serieAsksTMColor',
                        fillOpacity: 0,
                        dashStyle: 'ShortDot',
                        showInLegend: data.showTM
                    }, {
                        name: 'Bids ' + data.nameCE,
                        data: data.bidsCE,
                        color: '$serieBidsTMColor',
                        fillOpacity: 0,
                        dashStyle: 'ShortDot',
                        showInLegend: data.showCE
                    }, {
                        name: 'Asks ' + data.nameCE,
                        data: data.asksCE,
                        color: '$serieAsksTMColor',
                        fillOpacity: 0,
                        dashStyle: 'ShortDot',
                        showInLegend: data.showCE
                    }],
                    plotOptions: {
                        areaspline: {
                            fillOpacity: 0.3
                        },
                        area: {
                            fillOpacity: 0.3
                        },
                        series: {
                            animation: false
                        }
                    },
                    exporting: {
                        enabled: false
                    },
                    credits: {
                        enabled: false
                    },
                });
            }


            //
            // Modification de la configuration de l'orderBook
            //
            function ob_confGraph(range, orderBookTM, crypto, compareExchange)
            {
                $('#ob_container').html('<div align="center" style="margin-top:150px;"><img src="/app/img/$loader"></div>');

                // Sélectionner un timeMachine désactive la comparaison d'exchange
                if ((orderBookTM != $('#ob_orderBookTM').val()) && orderBookTM != 0) {
                    compareExchange = 0;
                }

                // Sélectionner une comparaison d'exchange désactive la timeMachine
                if ((compareExchange != $('#ob_compareEx').val()) && compareExchange != 0) {
                    orderBookTM = 0;
                }

                $('#ob_range').val(range);
                $('#ob_orderBookTM').val(orderBookTM);
                $('#ob_myCrypto').val(crypto);
                $('#ob_compareEx').val(compareExchange);

                ob_selectCrypto_yAxis();
                ob_selectRange();
                ob_selectTimeMachine();
                ob_selectCompareExchange();

                ajaxOrderBook();
            }


            //
            // Selection des ranges de l'orderBook
            //
            function ob_selectRange()
            {
                var linkRanges    = new Array(0.05, 0.1, 0.2, 0.4, 0.6, 0.8, 1, 1.5, 2, 3, 4, 5, 10, 25, 50, 75, 100);

                if ($('#ob_btnRange').length == 0) {

                    $('#ob_toolsOrderBook').append('<div id="ob_btnRange" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="ob_rangeLib"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#ob_btnRange').append(btnSelect);
                    $('#ob_btnRange').append('<ul id="ob_optionsRange" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkRanges) {
                    if ($('#ob_range').val() == linkRanges[i]) {
                        var affRange = 'Range : <span class="btnToolsOn">' + linkRanges[i] + '%</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkRanges[i] + ' %</span></a></li>';
                    } else {
                        var jsFunction = 'ob_confGraph(' + linkRanges[i] + ', $(\'#ob_orderBookTM\').val(), $(\'#ob_myCrypto\').val(), $(\'#ob_compareEx\').val());';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkRanges[i] + ' %</a></li>';
                    }
                }

                $('#ob_optionsRange').html(options);
                $('#ob_rangeLib').html(affRange);
            }


            //
            // Selection des Intervals timeMachine de l'orderBook
            //
            function ob_selectTimeMachine()
            {
                var linkTM = {
                    0   : 'Off',
                    1   : '-1m',
                    2   : '-2m',
                    3   : '-3m',
                    4   : '-4m',
                    5   : '-5m',
                    10  : '-10m',
                    15  : '-15m',
                    30  : '-30m',
                    60  : '-1h',
                    120 : '-2h',
                    180 : '-3h',
                    300 : '-5h',
                    //600 : '-10h',
                };

                if ($('#ob_btnTimeMachine').length == 0) {

                    $('#ob_toolsOrderBook').append('<div id="ob_btnTimeMachine" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="ob_timeMachineLib"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#ob_btnTimeMachine').append(btnSelect);
                    $('#ob_btnTimeMachine').append('<ul id="ob_optionsTimeMachine" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkTM) {
                    if ($('#ob_orderBookTM').val() == i) {
                        var affTM = 'Time Machine : <span class="btnToolsOn">' + linkTM[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkTM[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'ob_confGraph($(\'#ob_range\').val(), ' + i + ', $(\'#ob_myCrypto\').val(), $(\'#ob_compareEx\').val());';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkTM[i] + '</a></li>';
                    }
                }

                $('#ob_optionsTimeMachine').html(options);
                $('#ob_timeMachineLib').html(affTM);
            }


            //
            // Selection des exchanges pouvant être comparés
            //
            function ob_selectCompareExchange()
            {
                var linkCompareEx = new Array();

                $.post("/app/ajax/ajaxCompareExchangeList.php",
                {
                    currentExchange : $('#exchange').val(),
                    marketName      : $('#marketName').val()
                },
                function success(data)
                {
                    // console.log(data);

                    if (data.countExchangeList > 0) {

                        // Création du tableau des liens à réaliser
                        $('.compareExchange').css('display', 'block');

                        linkCompareEx[0] = 0;

                        for (var i in data.exchangeList) {
                            linkCompareEx[i] = data.exchangeList[i];
                        }

                        if ($('#ob_btnCompareEx').length == 0) {

                            $('#ob_toolsOrderBook').append('<div id="ob_btnCompareEx" class="btn-group" role="group"></div>');

                            var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                            btnSelect    +=    '<span id="ob_compareExLib"></span> <span class="caret"></span>';
                            btnSelect    += '</button>';

                            $('#ob_btnCompareEx').append(btnSelect);
                            $('#ob_btnCompareEx').append('<ul id="ob_optionsCompareEx" class="dropdown-menu"></ul>');
                        }

                        var options = '';

                        for (var i in linkCompareEx) {

                            var libLinkCompareEx = capitalize(linkCompareEx[i]);
                            if (linkCompareEx[i] == 0) {
                                libLinkCompareEx = 'Off';
                            }

                            if ($('#ob_compareEx').val() == i) {
                                var affCompareEx = 'Compare Ex : <span class="btnToolsOn">' + libLinkCompareEx + '</span>';
                                options += '<li><a><span class="btnToolsOn">' + libLinkCompareEx + '</span></a></li>';
                            } else {
                                var jsFunction = 'ob_confGraph($(\'#ob_range\').val(), $(\'#ob_orderBookTM\').val(), $(\'#ob_myCrypto\').val(), \'' + i + '\');';
                                options += '<li><a href="javascript:' + jsFunction + '">' + libLinkCompareEx + '</a></li>';
                            }
                        }

                        $('#ob_optionsCompareEx').html(options);
                        $('#ob_compareExLib').html(affCompareEx);

                    } else {
                        $('.timeMachine').css('margin-bottom', '40px');
                    }

                    ob_selectPlayGraph();

                }, 'json');
            }


            //
            // Selection de l'unité de volume en monnaie de référence ou monnaie tradée
            //
            function ob_selectCrypto_yAxis()
            {
                var linkCrypto = $('#marketName').val().split('-');

                if ($('#ob_myCrypto').val() == '') {
                    $('#ob_myCrypto').val( linkCrypto[0] );
                }

                if ($('#ob_btnCryptoYAxis').length == 0) {

                    $('#ob_toolsOrderBook').append('<div id="ob_btnCryptoYAxis" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="ob_cryptoYAxisLib"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#ob_btnCryptoYAxis').append(btnSelect);
                    $('#ob_btnCryptoYAxis').append('<ul id="ob_optionsCryptoYAxis" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkCrypto) {
                    if ($('#ob_myCrypto').val() == linkCrypto[i]) {
                        var affLib = 'yAxis : <span class="btnToolsOn">' + linkCrypto[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkCrypto[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'ob_confGraph($(\'#ob_range\').val(), $(\'#ob_orderBookTM\').val(), \'' + linkCrypto[i] + '\', $(\'#ob_compareEx\').val());';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkCrypto[i] + '</a></li>';
                    }
                }

                $('#ob_optionsCryptoYAxis').html(options);
                $('#ob_cryptoYAxisLib').html(affLib);
            }


            //
            // Selection de la fonction play / pause
            //
            function ob_selectPlayGraph()
            {
                if ( $('#ob_btnPlayGraph').length == 0 ) {

                    $('#ob_toolsOrderBook').append('<div id="ob_btnPlayGraph" class="btn-group" role="group" title="Chart pause"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default" aria-haspopup="true" aria-expanded="false" onclick="ob_selectPlayGraphClick();">';
                    btnSelect    +=    '<span id="ob_playGraphLib"><i class="fa fa-pause greyBold"></i></span>';
                    btnSelect    += '</button>';

                    $('#ob_btnPlayGraph').html(btnSelect);
                }
            }

            function ob_selectPlayGraphClick()
            {
                if ($('#ob_playGraph').val() == 1) {
                    $('#ob_playGraphLib').html('<i class="fa fa-play colorBold2"></i>');
                    $('#ob_playGraph').val(0);
                    $('#ob_btnPlayGraph').attr('data-original-title', 'Chart play');
                } else {
                    $('#ob_playGraphLib').html('<i class="fa fa-pause greyBold"></i>');
                    $('#ob_playGraph').val(1);
                    $('#ob_btnPlayGraph').attr('data-original-title', 'Chart pause');

                    ob_confGraph( $('#ob_range').val(), $('#ob_orderBookTM').val(), $('#ob_myCrypto').val(), $('#ob_compareEx').val()  );
                }

                $('#ob_btnPlayGraph button').blur();
            }


            //
            // Appel Ajax pour l'affichage de l'orderBook
            //
            function ajaxOrderBook()
            {
                if ( $('#ob_container').length == 0 ) {
                    $('#ob_container').append('<div align="center" style="margin-top:150px;"><img src="/app/img/$loader"></div>');
                }

                $.post("/app/ajax/ajaxGraphOrderBook.php",
                {
                    exchange    : $('#exchange').val(),
                    marketName  : $('#marketName').val(),
                    crypto      : $('#ob_myCrypto').val(),
                    range       : $('#ob_range').val(),
                    timeMachine : $('#ob_orderBookTM').val(),
                    compareEx   : $('#ob_compareEx').val()
                },
                function success(data)
                {
                    // console.log(data);

                    ob_affHighcharts(data);

                }, 'json');
            }


            //
            // Mise à jour de l'orderBook toutes les secondes
            //
            setInterval(function() {
                if ($('#ob_playGraph').val() == 1 && $('#leftTabs_id0').attr('class') == 'active' && $('#chartsTabs_id1').attr('class') == 'active' && $(document).scrollTop().valueOf() < 50) {
                    ajaxOrderBook();
                }
            }, 1000);


            //
            // Mise à jour au click sur l'onglet
            //
            function majOrderBook()
            {
                ob_selectCrypto_yAxis();            // Affichage du selecteur de crypto pour l'axe des ordonnées
                ob_selectRange();                   // Affichage du selecteur de ranges
                ob_selectTimeMachine();             // Affichage du selecteur d'intervals timeMachine
                ob_selectCompareExchange();         // Affichage du selecteur d'exchange pouvant être comparés
                //ob_selectPlayGraph();             // Exécuté au retour de l'ajax 'ob_selectCompareExchange' pour s'afficher en dernière position

                ajaxOrderBook();                    // Actualisation de l'orderBook
            }

            $('#chartsTabs_id').on('click', '#chartsTabs_id1', function() {
                majOrderBook();
            });

            $('#leftTabs_id').on('click', '#leftTabs_id0', function() {
                if ( $('#chartsTabs_id1').attr('class') == 'active' ) {
                    majOrderBook();
                }
            });
eof;

        return $js;
    }
}
