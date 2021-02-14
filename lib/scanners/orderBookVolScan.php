<?php
namespace cryptos\scanners;

/**
 * Création d'un classement sur les évolutions des volume dans l'orderBook
 * dans un interval de temps défini et avec l'exchange choisi
 *
 * @author Daniel Gomes
 */
class orderBookVolScan
{
    /**order
	 * Attributs
	 */
    private $_exchange;                         // Nom de l'exchange
    private $_vol24h;                           // Volume 24 heures

    private $_dbh;                              // Instance PDO

    private $_nameExBDD;                        // Nom de la base de données de l'Exchange
    private $_tablesList;                       // Liste des tables de market en BDD

    private $_prefixeTable  = 'ob_';            // Préfixe des tables de market


    /**
	 * Constructeur
	 */
	public function __construct($exchange, $vol24h=150)
	{
        // Nom de l'exchange
        $this->_exchange = $exchange;

        // Volume 24 heures
        $this->_vol24h = $vol24h;

        // Nom de la base de données de l'Exchange
        $this->_nameExBDD = 'cryptos_ex_' . $exchange;

        // Instance PDO
        $this->_dbh = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Récupération de la liste des tables de market en BDD
        $this->tableList();
    }


    /**
     * Méthode cURL pour la création du tableau de données
     * Compilation des données déportée sur le serveur de collecte
     */
    public function orderBookVolScanCurl($interval, $orientation, $range)
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$this->_exchange] . '/scanners/orderBookScan.php';

        $postFields = array(
            'exchange'      => $this->_exchange,
            'vol24h'        => $this->_vol24h,
            'interval'      => $interval,
            'orientation'   => $orientation,
            'range'         => $range,
        );

        $dataSet = \core\curl::curlPost($urlCurl, $postFields);

        return json_decode($dataSet, true);
    }


    /**
     * Classement des markets d'un exchange par évolution des prix dans un interval de temps défini
     *
     * @param       integer     $interval       Interval de temps
     * @param       float       $range          Range sur lequel l'orderBook sera analysé
     * @param       integer     $orientation    Orientation du marché à afficher (volume)
     *
     * @return      array
     */
    public function orderBookVolScan($interval, $orientation, $range=1)
    {
        switch ($interval)
        {
            case 60     : $delta = 1;       $unite = 'MINUTE';      $txtDelta = '1 minute';     break;
            case 120    : $delta = 2;       $unite = 'MINUTE';      $txtDelta = '2 minutes';    break;
            case 180    : $delta = 3;       $unite = 'MINUTE';      $txtDelta = '3 minutes';    break;
            case 240    : $delta = 4;       $unite = 'MINUTE';      $txtDelta = '4 minutes';    break;
            case 300    : $delta = 5;       $unite = 'MINUTE';      $txtDelta = '5 minutes';    break;
            case 600    : $delta = 10;      $unite = 'MINUTE';      $txtDelta = '10 minutes';   break;
            case 900    : $delta = 15;      $unite = 'MINUTE';      $txtDelta = '15 minutes';   break;
            case 1800   : $delta = 30;      $unite = 'MINUTE';      $txtDelta = '30 minutes';   break;
            case 2700   : $delta = 45;      $unite = 'MINUTE';      $txtDelta = '45 minutes';   break;
            case 3600   : $delta = 1;       $unite = 'HOUR';        $txtDelta = '1 hour';       break;
            case 7200   : $delta = 2;       $unite = 'HOUR';        $txtDelta = '2 hours';      break;
            case 10800  : $delta = 3;       $unite = 'HOUR';        $txtDelta = '3 hours';      break;
            case 18000  : $delta = 5;       $unite = 'HOUR';        $txtDelta = '5 hours';      break;
            case 36000  : $delta = 10;      $unite = 'HOUR';        $txtDelta = '12 hours';     break;
        }

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

                // Récupération du marketName
                $marketName = $this->recupMarketName($tableOb);

                // Analyse du dernier orderBook
                $resOrderBook = $this->analyseOrderBookRange($jsonOrderBook, $range);

                // Analyse de l'ancien orderBook (diff interval)
                $resOrderBookOld = $this->analyseOrderBookRange($jsonOrderBookOld, $range);

                // Calcul du pourcentage d'évolution dans l'orderBook
                if ($resOrderBookOld['all'] > 0) {
                    $pctAll = ((100 / $resOrderBookOld['all']) * $resOrderBook['all']) - 100;
                } else {
                    $pctAll = 0;
                }

                if ($resOrderBookOld['bids'] > 0) {
                    $pctBids = ((100 / $resOrderBookOld['bids']) * $resOrderBook['bids']) - 100;
                } else {
                    $pctBids = 0;
                }

                if ($resOrderBookOld['asks'] > 0) {
                    $pctAsks = ((100 / $resOrderBookOld['asks']) * $resOrderBook['asks']) - 100;
                } else {
                    $pctAsks = 0;
                }

                $pctAll  = number_format(round($pctAll,  2), 2, '.', '');
                $pctBids = number_format(round($pctBids, 2), 2, '.', '');
                $pctAsks = number_format(round($pctAsks, 2), 2, '.', '');

                // Filtre par orientation
                if (($orientation == 'bids' && $resOrderBook['bids'] <= $resOrderBook['asks']) || ($orientation == 'asks' && $resOrderBook['bids'] >= $resOrderBook['asks'])) {
                    continue;
                }

                // Orientation
                if      ( $resOrderBook['bids'] > $resOrderBook['asks'])    { $marketOrientation = 'bids'; }
                elseif  ( $resOrderBook['bids'] < $resOrderBook['asks'])    { $marketOrientation = 'asks'; }
                else                                                        { $marketOrientation = 'null'; }

                // Orientation : calcul du ratio à la hausse ou à la baisse
                if ($pctBids > $pctAsks && $pctAll > 0) {
                    $pctOrientation = (100 / $pctAll) * ($pctBids);
                    $pctOrientation = number_format(round($pctOrientation, 2), 2, '.', '') . '%';
                } elseif ($pctBids < $pctAsks && $pctAll > 0) {
                    $pctOrientation = (100 / $pctAll) * ($pctAsks);
                    $pctOrientation = number_format(round($pctOrientation, 2), 2, '.', '') . '%';
                } else {
                    $pctOrientation = '-';
                }

                // Tableau retourné par market
                $orderBookVolumes[] = array(
                    'exchange'                  => $this->_exchange,
                    'pctAll'                    => $pctAll,
                    'pctBids'                   => $pctBids,
                    'pctAsks'                   => $pctAsks,
                    'marketOrientation'         => $marketOrientation,
                    'pctOrientation'            => $pctOrientation,
                    'marketName'                => $marketName,
                    'urlMarket'                 => $this->linkMarketEx($marketName),
                    'txtDelta'                  => $txtDelta,
                    'resOrderBook'              => $resOrderBook,
                    'resOrderBookOld'           => $resOrderBookOld,
                );
            }
        }

        // Tableau trié sur les volumes
        $rang = array();
        if (count($orderBookVolumes) > 0) {
            foreach ($orderBookVolumes as $key => $val) {
                $rang[$key] = $val['pctAll'];
            }

            // Trie les données par rang décroissant sur la colonne 'pct'
            array_multisort($rang, SORT_DESC, $orderBookVolumes);
        }

        return $orderBookVolumes;
    }


    /**
     * Lien vers le market du site de l'Exchange
     */
    private function linkMarketEx($marketName)
    {
        switch ($this->_exchange) {
            case 'bittrex' :
                $urlMarket = 'https://bittrex.com/Market/Index?MarketName=' . $marketName;
                break;

            case 'bitfinex' :
                $expMarket = explode('-', $marketName);
                $getMarket = $expMarket[1] . $expMarket[0];
                $urlMarket = 'https://www.bitfinex.com/trading/' . $getMarket;
                break;

            case 'poloniex' :
                $getMarket = str_replace('-', '_', strtolower($marketName));
                $urlMarket = 'https://poloniex.com/exchange#' . $getMarket;
                break;

            case 'binance' :
                $expMarket = explode('-', $marketName);
                $getMarket = $expMarket[1] . '_' . $expMarket[0];
                $urlMarket = 'https://www.binance.com/trade.html?symbol=' . $getMarket;
                break;

            case 'gdax' :
                $expMarket = explode('-', $marketName);
                $getMarket = $expMarket[1] . '-' . $expMarket[0];
                $urlMarket = 'https://pro.coinbase.com/trade/' . $getMarket;
                break;
        }

        return $urlMarket;
    }


    /**
     * Récupération de la liste des tables des markets 'ob_%' en BDD
     */
    private function tableList()
    {
        $vol24h = $this->_vol24h;

        $prefixeTable = $this->_prefixeTable;
        $bddExchange  = $this->_nameExBDD;

        $this->_tablesList = array();

        $req = "SELECT  table_name AS exTable

                FROM    information_schema.tables

                WHERE   table_name LIKE ('$prefixeTable%')
                AND     table_schema = '$bddExchange'";

        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {

            // Récupération du marketName
            $marketName = $this->recupMarketName($res->exTable);

            $tableMarket = 'market_' . strtolower(str_replace('-', '_', $marketName));

            // On utilise ou recalcul le volume 24h pour filtrer la recherche des markets
            $expCryptos = explode('-', $marketName);
            $cryptoREF  = $expCryptos[0];

            if ($cryptoREF != 'BTC') {

                $fiatCurrencies = array('USD', 'USDT', 'EUR');

                if (in_array($cryptoREF, $fiatCurrencies)) {

                    try {
                        $table = 'market_' . strtolower($cryptoREF) . '_btc';

                        $req1 = "SELECT last FROM $table ORDER BY id DESC LIMIT 1";
                        $sql1 = $this->_dbh->query($req1);
                        $res1 = $sql1->fetch();

                        $checkVol24h = $vol24h * $res1->last;

                    } catch (\Exception $e) {

                        //error_log($e);
                        continue;
                    }

                } else {

                    try {
                        $table = 'market_btc_' . strtolower($cryptoREF);

                        $req1 = "SELECT last FROM $table ORDER BY id DESC LIMIT 1";
                        $sql1 = $this->_dbh->query($req1);
                        $res1 = $sql1->fetch();

                        $checkVol24h = $vol24h / $res1->last;

                    } catch (\Exception $e) {

                        //error_log($e);
                        continue;
                    }
                }
            } else {
                $checkVol24h = $vol24h;
            }

            try {
                $reqVol = "SELECT baseVolume FROM $tableMarket ORDER BY id DESC LIMIT 1";
                $sqlVol = $this->_dbh->query($reqVol);
                $resVol = $sqlVol->fetch();

                $baseVolume = $resVol->baseVolume;

                if ($baseVolume >= $checkVol24h) {
                    $this->_tablesList[] = $res->exTable;
                }

            } catch (\Exception $e) {

                //error_log($e);
                continue;
            }

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

        if (is_object($orderBook)) {
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


    /**
     * Code Javascript
     */
    public static function orderBookVolScanJS()
    {
        $js = <<<eof

            //
            // Mise à jour de la date et heure de la dernière recherche du scanner de marketHistory
            //
            $('#table_scanners_tab2').on('click', 'button[name="refresh"]', function () {
                var exchange    = $('#obvScan_exchange').val();
                var range       = $('#obvScan_range').val();
                var interval    = $('#obvScan_interval').val();
                var vol24h      = $('#obvScan_vol24h').val();
                var orientation = $('#obvScan_orientation').val();

                var url = 'scanners/inc/orderBookVolScan.php';
                var get = 'exchange=' + exchange + '&range=' + range + '&interval=' + interval + '&vol24h=' + vol24h + '&orientation=' + orientation;

                $('#table_obvScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Affichage de l'heure de mise à jour
                clockResfreshObScan();

                // Affichage / Masquage auto des colonnes
                hideColumnObScan();
            });


            //
            // Modification de la configuration du scanner
            //
            function obvScan_conf(exchange, range, interval, vol24h, orientation)
            {
                $('#obvScan_exchange').val(exchange);
                $('#obvScan_range').val(range);
                $('#obvScan_interval').val(interval);
                $('#obvScan_orientation').val(orientation);
                $('#obvScan_vol24h').val(vol24h);

                obvScan_selectExchange();
            }


            //
            // Selection d'un exchange pour le scan
            //
            function obvScan_selectExchange()
            {
                if ( $('#allExchanges').val() == '' ) {

                    $.post("/app/ajax/ajaxExchangeList.php",
                    {
                        action : 1
                    },
                    function success(data)
                    {
                        // console.log(data);

                        $('#allExchanges').val( data.join('|') );
                        obvScan_selectExchangeAux(data);

                    }, 'json');

                } else {

                    var exchanges = $('#allExchanges').val().split('|');
                    obvScan_selectExchangeAux(exchanges);
                }
            }

            function obvScan_selectExchangeAux(exchangesList)
            {
                if ($('#obvScan_btnExchanges').length == 0) {

                    $('#obvScan_tools').append('<div id="obvScan_btnExchanges" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_obvScan_exchanges"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#obvScan_btnExchanges').append(btnSelect);
                    $('#obvScan_btnExchanges').append('<ul id="obvScan_optionsExchanges" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in exchangesList) {

                    var nameExchange = capitalize(exchangesList[i]);

                    if ($('#obvScan_exchange').val() == exchangesList[i]) {
                        var affLib = 'Ex : <span class="btnToolsOn">' + nameExchange + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + nameExchange + '</span></a></li>';
                    } else {
                        var jsFunction = 'obvScan_conf( \'' + exchangesList[i] + '\', $(\'#obvScan_range\').val(), $(\'#obvScan_interval\').val(), $(\'#obvScan_vol24h\').val(), $(\'#obvScan_orientation\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + nameExchange + '</a></li>';
                    }
                }

                $('#obvScan_optionsExchanges').html(options);
                $('#lib_obvScan_exchanges').html(affLib);

                // Affichage du sélecteur de Range
                obvScan_selectRange();

                // Affichage du sélecteur d'interval
                obvScan_selectInterval();

                // Affichage du sélecteur de volume 24h
                obvScan_selectVol24h();

                // Affichage du sélecteur d'orientation
                obvScan_selectOrientation();

                // Refresh du tableau
                var exchange    = $('#obvScan_exchange').val();
                var range       = $('#obvScan_range').val();
                var interval    = $('#obvScan_interval').val();
                var vol24h      = $('#obvScan_vol24h').val();
                var orientation = $('#obvScan_orientation').val();

                var url = 'scanners/inc/orderBookVolScan.php';
                var get = 'exchange=' + exchange + '&range=' + range + '&interval=' + interval + '&vol24h=' + vol24h + '&orientation=' + orientation;

                $('#table_obvScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Maj du bouton d'export CSV
                $('#table_obvScan_csv').attr( 'onclick', "window.open('" + url + "?csv&" + get + "');" );

                // Affichage de l'heure de mise à jour
                clockResfreshObScan();

                // Affichage / Masquage auto des colonnes
                hideColumnObScan();
            }


            //
            // Sélection du range à étudier de l'orderBook
            //
            function obvScan_selectRange()
            {
                var linkRange = new Array(1, 2, 3, 4, 5, 10, 25, 50, 120);

                if ($('#obvScan_btnRange').length == 0) {

                    $('#obvScan_tools').append('<div id="obvScan_btnRange" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="obvScan_rangeLib"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#obvScan_btnRange').append(btnSelect);
                    $('#obvScan_btnRange').append('<ul id="obvScan_optionsRange" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkRange) {
                    if ($('#obvScan_range').val() == linkRange[i]) {
                        var affRange = 'Range : <span class="btnToolsOn">' + linkRange[i] + '%</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkRange[i] + ' %</span></a></li>';
                    } else {
                        var jsFunction = 'obvScan_conf( $(\'#obvScan_exchange\').val(), \'' + linkRange[i] + '\', $(\'#obvScan_interval\').val(), $(\'#obvScan_vol24h\').val(), $(\'#obvScan_orientation\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkRange[i] + ' %</a></li>';
                    }
                }

                $('#obvScan_optionsRange').html(options);
                $('#obvScan_rangeLib').html(affRange);
            }


            //
            // Selection d'un delta avec l'heure actuelle pour la comparaison
            //
            function obvScan_selectInterval()
            {
                var linkIntervals = {
                    60    : '1m',
                    120   : '2m',
                    180   : '3m',
                    240   : '4m',
                    300   : '5m',
                    600   : '10m',
                    900   : '15m',
                    1800  : '30m',
                    2700  : '45m',
                    3600  : '1h',
                    7200  : '2h',
                    10800 : '3h',
                    18000 : '5h',
                    36000 : '10h',
                };

                if ($('#obvScan_btnInterval').length == 0) {

                    $('#obvScan_tools').append('<div id="obvScan_btnInterval" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_obvScan_interval"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#obvScan_btnInterval').append(btnSelect);
                    $('#obvScan_btnInterval').append('<ul id="obvScan_optionsInterval" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkIntervals) {

                    if ($('#obvScan_interval').val() == i) {
                        var affInterval = 'Period : <span class="btnToolsOn">' + linkIntervals[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkIntervals[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'obvScan_conf( $(\'#obvScan_exchange\').val(), $(\'#obvScan_range\').val(), ' + i + ', $(\'#obvScan_vol24h\').val(), $(\'#obvScan_orientation\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkIntervals[i] + '</a></li>';
                    }
                }

                $('#obvScan_optionsInterval').html(options);
                $('#lib_obvScan_interval').html(affInterval);
            }


            //
            // Selection du volume 24h minimum
            //
            function obvScan_selectVol24h()
            {
                var linkVol24h = new Array(0, 25, 50, 75, 100, 150, 200, 300, 400, 500, 750, 1000, 1500, 2000);

                if ($('#obvScan_btnVol24h').length == 0) {

                    $('#obvScan_tools').append('<div id="obvScan_btnVol24h" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_obvScan_vol24h"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#obvScan_btnVol24h').append(btnSelect);
                    $('#obvScan_btnVol24h').append('<ul id="obvScan_optionsVol24h" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkVol24h) {

                    if ($('#obvScan_vol24h').val() == linkVol24h[i]) {
                        var affLib = 'Vol24h > <span class="btnToolsOn">' + linkVol24h[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkVol24h[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'obvScan_conf( $(\'#obvScan_exchange\').val(), $(\'#obvScan_range\').val(), $(\'#obvScan_interval\').val(), ' + linkVol24h[i] + ', $(\'#obvScan_orientation\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkVol24h[i] + '</a></li>';
                    }
                }

                $('#obvScan_optionsVol24h').html(options);
                $('#lib_obvScan_vol24h').html(affLib);
            }


            //
            // Selection de l'orientation du marché (toutes, hausses ou baisses)
            //
            function obvScan_selectOrientation()
            {
                var linkOrientation = {
                    'all'   : '<i class="fa fa-arrow-up    colorBold1"></i> <i class="fa fa-arrow-down  colorBold2"></i>',
                    'bids'  : '<i class="fa fa-arrow-up    colorBold1"></i>',
                    'asks'  : '<i class="fa fa-arrow-down  colorBold2"></i>',
                };

                if ($('#obvScan_btnOrientation').length == 0) {

                    $('#obvScan_tools').append('<div id="obvScan_btnOrientation" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_obvScan_orientation"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#obvScan_btnOrientation').append(btnSelect);
                    $('#obvScan_btnOrientation').append('<ul id="obvScan_optionsOrientation" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkOrientation) {

                    if ($('#obvScan_orientation').val() == i) {
                        var affLib = '<span class="btnToolsOn">' + linkOrientation[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkOrientation[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'obvScan_conf( $(\'#obvScan_exchange\').val(), $(\'#obvScan_range\').val(), $(\'#obvScan_interval\').val(), $(\'#obvScan_vol24h\').val(),  \'' + i + '\');';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkOrientation[i] + '</a></li>';
                    }
                }

                $('#obvScan_optionsOrientation').html(options);
                $('#lib_obvScan_orientation').html(affLib);
            }


            //obvScan_selectExchange();        // Affichage du selecteur d'exchange

            // function appelée dans 'obvScan_selectExchangeAux' pour respecter l'ordre des bouton et attendre le retour Ajax de la liste d'exchanges
            // obvScan_selectInterval();       // Affichage du selecteur d'interval
            // obvScan_selectRange();          // Affichage du selecteur de range
            // obvScan_selectOrientation();    // Séléction de l'orientation du marché


            //
            // Mise à jour au click sur l'onglet
            //
            $('#table_scanners_id').on('click', '#table_scanners_id2', function() {

                // Vide le champ de recherche
                $('#table_obvScan').bootstrapTable('resetSearch');

                obvScan_selectExchange();
            });

            $('#rightTabs_id').on('click', '#rightTabs_id0', function() {
                if ( $('#table_scanners_id2').attr('class') == 'active' ) {

                    // Vide le champ de recherche
                    $('#table_obvScan').bootstrapTable('resetSearch');

                    obvScan_selectExchange();
                }
            });

            // Affichage de l'heure de mise à jour
            function clockResfreshObScan()
            {
                $('#table_scanners_tab2 h3').html('<span class="text-refresh-scan-min">Updated:</span><span class="text-refresh-scan">Last updated:</span> ' + localTime());

                if ( $(window).width() < 660 ) {
                    $('.text-refresh-scan-min').show();
                    $('.text-refresh-scan').hide();
                } else {
                    $('.text-refresh-scan-min').hide();
                    $('.text-refresh-scan').show();
                }
            }

            // Responsive Design - Masquage auto des colonnes
            function hideColumnObScan()
            {
                if ( $(window).width() < 500 ) {
                    $('#table_obvScan').bootstrapTable('hideColumn', 'pctBids');
                    $('#table_obvScan').bootstrapTable('hideColumn', 'pctAsks');
                } else {
                    $('#table_obvScan').bootstrapTable('showColumn', 'pctBids');
                    $('#table_obvScan').bootstrapTable('showColumn', 'pctAsks');
                }
            }

            $(window).resize(function() {
                if ( $('#rightTabs_id0').attr('class') == 'active' && $('#table_scanners_id2').attr('class') == 'active' ) {
                    hideColumnObScan();
                }
            });
eof;

        return $js;
    }
}
