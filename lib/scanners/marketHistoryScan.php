<?php
namespace cryptos\scanners;

/**
 * Création d'un classement sur les évolutions des échange dans le marketHistory
 * dans un interval de temps défini et avec l'exchange choisi
 *
 * @author Daniel Gomes
 */
class marketHistoryScan
{
    /**
	 * Attributs
	 */
    private $_exchange;                         // Nom de l'exchange
    private $_vol24h;                           // Volume 24 heures

    private $_dbh;                              // Instance PDO

    private $_nameExBDD;                        // Nom de la base de données de l'Exchange
    private $_tablesList;                       // Liste des tables de market en BDD

    private $_prefixeTable  = 'mh_';            // Préfixe des tables de market

    private $_cyrptoRefList;                    // Liste des monnaies de référence de l'exchange
    private $_cyrptoRefLast;                    // Last des cryptos de référence pour la conversion en BTC


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

        // Récupération de la liste des monnaies de référence de l'exchange
        $this->cyrptoRefList();

        // Récupération des 'Last' des monnaies de référence
        $this->lastCryptoRef();
    }


    /**
     * Méthode cURL pour la création du tableau de données
     * Compilation des données déportée sur le serveur de collecte
     */
    public function marketHistoryScanCurl($interval, $orientation)
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$this->_exchange] . '/scanners/marketHistoryScan.php';

        $postFields = array(
            'exchange'      => $this->_exchange,
            'vol24h'        => $this->_vol24h,
            'interval'      => $interval,
            'orientation'   => $orientation,
        );

        $dataSet = \core\curl::curlPost($urlCurl, $postFields);

        return json_decode($dataSet, true);
    }


    /**
     * Classement des markets d'un exchange par évolution des prix dans un interval de temps défini
     *
     * @param       integer     $interval       Interval de temps
     * @param       integer     $orientation    Orientation du marché à afficher (volume)
     *
     * @return      array
     */
    public function marketHistoryScan($interval, $orientation)
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
            case 21600  : $delta = 6;       $unite = 'HOUR';        $txtDelta = '6 hours';      break;
            case 43200  : $delta = 12;      $unite = 'HOUR';        $txtDelta = '12 hours';     break;
        }

        // Déclaration du tableau de résultats
        $checkVolume = array();

        // Récupération de la plage pour cet interval
        $plage = $this->plage($delta, $unite);

        foreach ($this->_tablesList as $tableMh) {

            //$this->_dbh->query("ALTER TABLE $tableMh ADD INDEX(`total`)");

            // Récupération du marketName
            $marketName = $this->recupMarketName($tableMh);

            // Récupération de la monnaie de référence
            $crytposMarket = $this->recupCryptoTdeRef($marketName);
            $cryptoRef = $crytposMarket['ref'];

            // Requete de récupération de la somme de volumes
            $req = "SELECT      SUM(total) AS volume

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

            if ($cryptoRef == 'USD' || $cryptoRef == 'USDT' || $cryptoRef == 'EUR') {
                $BTCvolBuy  = $REFvolBuy  / $this->_cyrptoRefLast[$cryptoRef];
                $BTCvolSell = $REFvolSell / $this->_cyrptoRefLast[$cryptoRef];
            } elseif ($cryptoRef != 'BTC' && $cryptoRef != 'XBT' && $cryptoRef != 'USD' && $cryptoRef != 'USDT' && $cryptoRef != 'EUR') {
                $BTCvolBuy  = $REFvolBuy  * $this->_cyrptoRefLast[$cryptoRef];
                $BTCvolSell = $REFvolSell * $this->_cyrptoRefLast[$cryptoRef];
            } else {
                $BTCvolBuy  = $REFvolBuy;
                $BTCvolSell = $REFvolSell;
            }

            // Calcul du volume échangé dans l'interval de temps (en Bitcoin et dans la monnaie de référence)
            $REFvolTotal = $REFvolBuy + $REFvolSell;
            $BTCvolTotal = $BTCvolBuy + $BTCvolSell;

            // Filtre par orientation
            if (($orientation == 'buy' && $REFvolBuy <= $REFvolSell) || ($orientation == 'sell' && $REFvolBuy >= $REFvolSell)) {
                continue;
            }

            // Orientation
            if      ( $BTCvolBuy > $BTCvolSell) { $marketOrientation = 'buy';  }
            elseif  ( $BTCvolBuy < $BTCvolSell) { $marketOrientation = 'sell'; }
            else                                { $marketOrientation = 'null'; }

            // Orientation : calcul du ratio à la hausse ou à la baisse
            if ($BTCvolBuy > $BTCvolSell) {
                $pctOrientation = (100 / $BTCvolTotal) * ($BTCvolBuy);
                $pctOrientation = number_format(round($pctOrientation, 2), 2, '.', '') . '%';
            } elseif ($BTCvolBuy < $BTCvolSell) {
                $pctOrientation = (100 / $BTCvolTotal) * ($BTCvolSell);
                $pctOrientation = number_format(round($pctOrientation, 2), 2, '.', '') . '%';
            } else {
                $pctOrientation = '-';
            }

            $checkVolume[] = array(
                'exchange'                  => $this->_exchange,
                'marketOrientation'         => $marketOrientation,
                'pctOrientation'            => $pctOrientation,
                'marketName'                => $marketName,
                'urlMarket'                 => $this->linkMarketEx($marketName),
                'txtDelta'                  => $txtDelta,
                'BTCvolumeTotal'            => $BTCvolTotal,
                'BTCvolumeBuy'              => $BTCvolBuy,
                'BTCvolumeSell'             => $BTCvolSell,
                'REFvolumeTotal'            => $REFvolTotal,
                'REFvolumeBuy'              => $REFvolBuy,
                'REFvolumeSell'             => $REFvolSell,
                'plageDeb'                  => $plage['deb'],
                'plageEnd'                  => $plage['end'],
            );
        }

        // Tableau trié sur les volumes
        $rang = array();
        if (count($checkVolume) > 0) {
            foreach ($checkVolume as $key => $val) {
                $rang[$key] = $val['pctOrientation'];
            }

            // Trie les données par rang décroissant sur la colonne 'pct'
            array_multisort($rang, SORT_DESC, $checkVolume);
        }

        return $checkVolume;
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

            $reqVol = "SELECT baseVolume FROM $tableMarket ORDER BY id DESC LIMIT 1";
            $sqlVol = $this->_dbh->query($reqVol);
            $resVol = $sqlVol->fetch();

            $baseVolume = $resVol->baseVolume;

            if ($baseVolume >= $checkVol24h) {
                $this->_tablesList[] = $res->exTable;
            }
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

                if ($cryptoRef == 'USD' || $cryptoRef == 'USDT' || $cryptoRef == 'EUR') {
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


    /**
     * Code Javascript
     */
    public static function marketHistoryScanJS()
    {
        $js = <<<eof

            //
            // Mise à jour de la date et heure de la dernière recherche du scanner de marketHistory
            //
            $('#table_scanners_tab1').on('click', 'button[name="refresh"]', function () {
                var exchange    = $('#mhScan_exchange').val();
                var interval    = $('#mhScan_interval').val();
                var vol24h      = $('#mhScan_vol24h').val();
                var orientation = $('#mhScan_orientation').val();

                var url = 'scanners/inc/marketHistoryScan.php';
                var get = 'exchange=' + exchange + '&interval=' + interval + '&vol24h=' + vol24h + '&orientation=' + orientation;

                $('#table_mhScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Affichage de l'heure de mise à jour
                clockResfreshMhScan();

                // Affichage / Masquage auto des colonnes
                hideColumnMhScan();
            });


            //
            // Modification de la configuration du scanner
            //
            function mhScan_conf(exchange, interval, vol24h, orientation)
            {
                $('#mhScan_exchange').val(exchange);
                $('#mhScan_interval').val(interval);
                $('#mhScan_vol24h').val(vol24h);
                $('#mhScan_orientation').val(orientation);

                mhScan_selectExchange();
            }


            //
            // Selection d'un exchange pour le scan
            //
            function mhScan_selectExchange()
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
                        mhScan_selectExchangeAux(data);

                    }, 'json');

                } else {

                    var exchanges = $('#allExchanges').val().split('|');
                    mhScan_selectExchangeAux(exchanges);
                }
            }

            function mhScan_selectExchangeAux(exchangesList)
            {
                if ($('#mhScan_btnExchanges').length == 0) {

                    $('#mhScan_tools').append('<div id="mhScan_btnExchanges" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_mhScan_exchanges"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#mhScan_btnExchanges').append(btnSelect);
                    $('#mhScan_btnExchanges').append('<ul id="mhScan_optionsExchanges" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in exchangesList) {

                    var nameExchange = capitalize(exchangesList[i]);

                    if ($('#mhScan_exchange').val() == exchangesList[i]) {
                        var affLib = 'Exchange : <span class="btnToolsOn">' + nameExchange + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + nameExchange + '</span></a></li>';
                    } else {
                        var jsFunction = 'mhScan_conf( \'' + exchangesList[i] + '\', $(\'#mhScan_interval\').val(), $(\'#mhScan_vol24h\').val(), $(\'#mhScan_orientation\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + nameExchange + '</a></li>';
                    }
                }

                $('#mhScan_optionsExchanges').html(options);
                $('#lib_mhScan_exchanges').html(affLib);

                // Affichage du sélecteur d'interval
                mhScan_selectInterval();

                // Affichage du sélecteur de volume 24h
                mhScan_selectVol24h();

                // Affichage du sélecteur d'orientation
                mhScan_selectOrientation();

                // Refresh du tableau
                var exchange    = $('#mhScan_exchange').val();
                var interval    = $('#mhScan_interval').val();
                var vol24h      = $('#mhScan_vol24h').val();
                var orientation = $('#mhScan_orientation').val();

                var url = 'scanners/inc/marketHistoryScan.php';
                var get = 'exchange=' + exchange + '&interval=' + interval + '&vol24h=' + vol24h + '&orientation=' + orientation;

                $('#table_mhScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Maj du bouton d'export CSV
                $('#table_mhScan_csv').attr( 'onclick', "window.open('" + url + "?csv&" + get + "');" );

                // Affichage de l'heure de mise à jour
                clockResfreshMhScan();

                // Affichage / Masquage auto des colonnes
                hideColumnMhScan();
            }


            //
            // Selection d'un delta avec l'heure actuelle pour la comparaison
            //
            function mhScan_selectInterval()
            {
                var linkIntervals = {
                    60    : '1min',
                    120   : '2min',
                    180   : '3min',
                    240   : '4min',
                    300   : '5min',
                    600   : '10min',
                    900   : '15min',
                    1800  : '30min',
                    2700  : '45min',
                    3600  : '1h',
                    7200  : '2h',
                    10800 : '3h',
                    21600 : '6h',
                    43200 : '12h',
                };

                if ($('#mhScan_btnInterval').length == 0) {

                    $('#mhScan_tools').append('<div id="mhScan_btnInterval" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_mhScan_interval"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#mhScan_btnInterval').append(btnSelect);
                    $('#mhScan_btnInterval').append('<ul id="mhScan_optionsInterval" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkIntervals) {

                    if ($('#mhScan_interval').val() == i) {
                        var affInterval = 'Period : <span class="btnToolsOn">' + linkIntervals[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkIntervals[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'mhScan_conf( $(\'#mhScan_exchange\').val(), ' + i + ', $(\'#mhScan_vol24h\').val(), $(\'#mhScan_orientation\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkIntervals[i] + '</a></li>';
                    }
                }

                $('#mhScan_optionsInterval').html(options);
                $('#lib_mhScan_interval').html(affInterval);
            }


            //
            // Selection du volume 24h minimum
            //
            function mhScan_selectVol24h()
            {
                var linkVol24h = new Array(0, 25, 50, 75, 100, 150, 200, 300, 400, 500, 750, 1000, 1500, 2000);

                if ($('#mhScan_btnVol24h').length == 0) {

                    $('#mhScan_tools').append('<div id="mhScan_btnVol24h" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_mhScan_vol24h"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#mhScan_btnVol24h').append(btnSelect);
                    $('#mhScan_btnVol24h').append('<ul id="mhScan_optionsVol24h" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkVol24h) {

                    if ($('#mhScan_vol24h').val() == linkVol24h[i]) {
                        var affLib = 'Vol. 24h > <span class="btnToolsOn">' + linkVol24h[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkVol24h[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'mhScan_conf( $(\'#mhScan_exchange\').val(), $(\'#mhScan_interval\').val(), ' + linkVol24h[i] + ', $(\'#mhScan_orientation\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkVol24h[i] + '</a></li>';
                    }
                }

                $('#mhScan_optionsVol24h').html(options);
                $('#lib_mhScan_vol24h').html(affLib);
            }


            //
            // Selection de l'orientation du marché (toutes, hausses ou baisses)
            //
            function mhScan_selectOrientation()
            {
                var linkOrientation = {
                    'all'   : '<i class="fa fa-arrow-up    colorBold1"></i> <i class="fa fa-arrow-down  colorBold2"></i>',
                    'buy'   : '<i class="fa fa-arrow-up    colorBold1"></i>',
                    'sell'  : '<i class="fa fa-arrow-down  colorBold2"></i>',
                };

                if ($('#mhScan_btnOrientation').length == 0) {

                    $('#mhScan_tools').append('<div id="mhScan_btnOrientation" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_mhScan_orientation"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#mhScan_btnOrientation').append(btnSelect);
                    $('#mhScan_btnOrientation').append('<ul id="mhScan_optionsOrientation" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkOrientation) {

                    if ($('#mhScan_orientation').val() == i) {
                        var affLib = '<span class="btnToolsOn">' + linkOrientation[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkOrientation[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'mhScan_conf( $(\'#mhScan_exchange\').val(), $(\'#mhScan_interval\').val(), $(\'#mhScan_vol24h\').val(),  \'' + i + '\' );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkOrientation[i] + '</a></li>';
                    }
                }

                $('#mhScan_optionsOrientation').html(options);
                $('#lib_mhScan_orientation').html(affLib);
            }


            //mhScan_selectExchange();        // Affichage du selecteur d'exchange

            // function appelée dans 'mhScan_selectExchangeAux' pour respecter l'ordre des bouton et attendre le retour Ajax de la liste d'exchanges
            // mhScan_selectInterval();       // Affichage du selecteur d'interval
            // mhScan_selectOrientation();    // Séléction de l'orientation du marché


            //
            // Mise à jour au click sur l'onglet
            //
            $('#table_scanners_id').on('click', '#table_scanners_id1', function() {

                // Vide le champ de recherche
                $('#table_mhScan').bootstrapTable('resetSearch');

                mhScan_selectExchange();
            });

            $('#rightTabs_id').on('click', '#rightTabs_id0', function() {
                if ( $('#table_scanners_id1').attr('class') == 'active' ) {

                    // Vide le champ de recherche
                    $('#table_mhScan').bootstrapTable('resetSearch');

                    mhScan_selectExchange();
                }
            });

            // Affichage de l'heure de mise à jour
            function clockResfreshMhScan()
            {
                $('#table_scanners_tab1 h3').html('<span class="text-refresh-scan-min">Updated:</span><span class="text-refresh-scan">Last updated:</span> ' + localTime());

                if ( $(window).width() < 660 ) {
                    $('.text-refresh-scan-min').show();
                    $('.text-refresh-scan').hide();
                } else {
                    $('.text-refresh-scan-min').hide();
                    $('.text-refresh-scan').show();
                }
            }

            // Responsive Design - Masquage auto des colonnes
            function hideColumnMhScan()
            {
                if ( $(window).width() < 500 ) {
                    $('#table_mhScan').bootstrapTable('hideColumn', 'volumeInfos');
                } else {
                    $('#table_mhScan').bootstrapTable('showColumn', 'volumeInfos');
                }
            }

            $(window).resize(function() {
                if ( $('#rightTabs_id0').attr('class') == 'active' && $('#table_scanners_id1').attr('class') == 'active' ) {
                    hideColumnMhScan();
                }
            });
eof;

        return $js;
    }
}
