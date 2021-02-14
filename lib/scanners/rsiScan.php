<?php
namespace cryptos\scanners;

/**
 * Création d'un classement sur les résultats de la RSI
 * dans un interval de temps défini et avec l'exchange choisi
 *
 * @author Daniel Gomes
 */
class rsiScan
{
    /**
	 * Attributs
	 */
    private $_exchange;                         // Nom de l'exchange
    private $_vol24h;                           // Volume 24 heures

    private $_dbh;                              // Instance PDO

    private $_nameExBDD;                        // Nom de la base de données de l'Exchange
    private $_tablesList;                       // Liste des tables de market en BDD

    private $_prefixeTable  = 'market_';        // Préfixe des tables de market


    /**
	 * Constructeur
	 */
	public function __construct($exchange, $vol24h=500)
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
    public function rsiScanCurl($interval, $nbCandles=14, $nbResRSI=3)
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$this->_exchange] . '/scanners/rsiScan.php';

        $postFields = array(
            'exchange'      => $this->_exchange,
            'vol24h'        => $this->_vol24h,
            'interval'      => $interval,
            'nbCandles'     => $nbCandles,
            'nbResRSI'      => $nbResRSI,
        );

        $dataSet = \core\curl::curlPost($urlCurl, $postFields);

        return json_decode($dataSet, true);
    }


    /**
     * Classement des markets d'un exchange par évolution des prix dans un interval de temps défini
     *
     * @param       integer     $interval       Interval de temps
     *
     * @return      array
     */
    public function rsiScan($interval, $nbCandles=14, $nbResRSI=3)
    {
        // Déclaration du tableau de résultats
        $scanRSI = array();

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
            case 86400  : $delta = 24;      $unite = 'HOUR';        $txtDelta = '24 hours';     break;
        }

        foreach ($this->_tablesList as $tableMarket) {

            // Récupération du marketName
            $marketName = $this->recupMarketName($tableMarket);

            $rsi = \cryptos\trading\rsi::getRSI($this->_nameExBDD, $tableMarket, $unite, $delta, $nbCandles, $nbResRSI);
            $rsi = end($rsi['rsi']);
            $rsi = round($rsi, 2);

            if ($rsi == 0 || $rsi == 100) {
                continue;
            }

            // Tableau retourné par market
            $scanRSI[] = array(
                'exchange'                  => $this->_exchange,
                /*
                'nameExBDD'    => $this->_nameExBDD,
                'tableMarket'   => $tableMarket,
                'unite'         => $unite,
                'delta'         => $delta,
                'nbCandles'     => $nbCandles,
                'nbResRSI'      => $nbResRSI,
                */
                'marketName'                => $marketName,
                'urlMarket'                 => $this->linkMarketEx($marketName),
                'rsi'                       => $rsi,
            );
        }

        // Tableau trié sur les pourcentage
        $rang = array();
        if (count($scanRSI) > 0) {
            foreach ($scanRSI as $key => $val) {
                $rang[$key]  = $val['rsi'];
            }

            // Trie les données par rang décroissant sur la colonne 'pct'
            array_multisort($rang, SORT_ASC, $scanRSI);
        }

        return $scanRSI;
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
     * Récupération de la liste des tables des markets 'market_%' en BDD
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

            $tableMarket = $res->exTable;

            // Récupération du marketName
            $marketName = $this->recupMarketName($tableMarket);

            // On utilise ou recalcul le volume 24h pour filtrer la recherche des markets
            $expCryptos = explode('-', $marketName);
            $cryptoREF  = $expCryptos[0];

            if ($cryptoREF != 'BTC') {

                $fiatCurrencies = array('USD', 'USDT', 'EUR');

                if (in_array($cryptoREF, $fiatCurrencies)) {

                    try {
                        $table = 'market_' . strtolower($cryptoREF) . '_btc';
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
                $this->_tablesList[] = $tableMarket;
            }
        }
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
     * Code Javascript
     */
    public static function rsiScanJS()
    {
        $js = <<<eof

            //
            // Mise à jour de la date et heure de la dernière recherche du scanner RSI
            //
            $('#table_scanners_tab3').on('click', 'button[name="refresh"]', function () {

                var exchange = $('#rsiScan_exchange').val();
                var interval = $('#rsiScan_interval').val();
                var candles  = $('#rsiScan_candles').val();
                var nbRes    = $('#rsiScan_nbRes').val();
                var vol24h   = $('#rsiScan_vol24h').val();

                var url = 'scanners/inc/rsiScan.php';
                var get = 'exchange=' + exchange + '&interval=' + interval + '&candles=' + candles + '&nbRes=' + nbRes + '&vol24h=' + vol24h;

                $('#table_rsiScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Affichage de l'heure de mise à jour
                clockResfreshRsiScan();
            });


            //
            // Modification de la configuration du scanner
            //
            function rsiScan_conf(exchange, interval, vol24h)
            {
                $('#rsiScan_exchange').val(exchange);
                $('#rsiScan_interval').val(interval);
                $('#rsiScan_vol24h').val(vol24h);

                rsiScan_selectExchange();
            }


            //
            // Selection d'un exchange pour le scan
            //
            function rsiScan_selectExchange()
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
                        rsiScan_selectExchangeAux(data);

                    }, 'json');

                } else {

                    var exchanges = $('#allExchanges').val().split('|');
                    rsiScan_selectExchangeAux(exchanges);
                }
            }

            function rsiScan_selectExchangeAux(exchangesList)
            {
                if ($('#rsiScan_btnExchanges').length == 0) {

                    $('#rsiScan_tools').append('<div id="rsiScan_btnExchanges" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_rsiScan_exchanges"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#rsiScan_btnExchanges').append(btnSelect);
                    $('#rsiScan_btnExchanges').append('<ul id="rsiScan_optionsExchanges" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in exchangesList) {

                    var nameExchange = capitalize(exchangesList[i]);

                    if ($('#rsiScan_exchange').val() == exchangesList[i]) {
                        var affLib = 'Exchange : <span class="btnToolsOn">' + nameExchange + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + nameExchange + '</span></a></li>';
                    } else {
                        var jsFunction = 'rsiScan_conf( \'' + exchangesList[i] + '\', $(\'#rsiScan_interval\').val(), $(\'#rsiScan_vol24h\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + nameExchange + '</a></li>';
                    }
                }

                $('#rsiScan_optionsExchanges').html(options);
                $('#lib_rsiScan_exchanges').html(affLib);

                // Affichage du sélecteur d'interval
                rsiScan_selectInterval();

                // Affichage du sélecteur de volume 24h
                rsiScan_selectVol24h();

                // Refresh du tableau
                var exchange = $('#rsiScan_exchange').val();
                var interval = $('#rsiScan_interval').val();
                var candles  = $('#rsiScan_candles').val();
                var nbRes    = $('#rsiScan_nbRes').val();
                var vol24h   = $('#rsiScan_vol24h').val();

                var url = 'scanners/inc/rsiScan.php';
                var get = 'exchange=' + exchange + '&interval=' + interval + '&candles=' + candles + '&nbRes=' + nbRes + '&vol24h=' + vol24h;

                $('#table_rsiScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Maj du bouton d'export CSV
                $('#table_rsiScan_csv').attr( 'onclick', "window.open('" + url + "?csv&" + get + "');" );

                // Affichage de l'heure de mise à jour
                clockResfreshRsiScan();
            }


            //
            // Selection d'un delta avec l'heure actuelle pour la comparaison
            //
            function rsiScan_selectInterval()
            {
                var linkIntervals = {
                    60    : '1min',
                    180   : '3min',
                    300   : '5min',
                    900   : '15min',
                    1800  : '30min',
                    2700  : '45min',
                    3600  : '1h',
                    // 7200  : '2h',
                };

                if ($('#rsiScan_btnInterval').length == 0) {

                    $('#rsiScan_tools').append('<div id="rsiScan_btnInterval" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_rsiScan_interval"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#rsiScan_btnInterval').append(btnSelect);
                    $('#rsiScan_btnInterval').append('<ul id="rsiScan_optionsInterval" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkIntervals) {

                    if ($('#rsiScan_interval').val() == i) {
                        var affInterval = 'Period : <span class="btnToolsOn">' + linkIntervals[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkIntervals[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'rsiScan_conf( $(\'#rsiScan_exchange\').val(), ' + i + ', $(\'#rsiScan_vol24h\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkIntervals[i] + '</a></li>';
                    }
                }

                $('#rsiScan_optionsInterval').html(options);
                $('#lib_rsiScan_interval').html(affInterval);
            }


            //
            // Selection du volume 24h minimum
            //
            function rsiScan_selectVol24h()
            {
                var linkVol24h = new Array(250, 500, 750, 1000, 1500, 2000);

                if ($('#rsiScan_btnVol24h').length == 0) {

                    $('#rsiScan_tools').append('<div id="rsiScan_btnVol24h" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_rsiScan_vol24h"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#rsiScan_btnVol24h').append(btnSelect);
                    $('#rsiScan_btnVol24h').append('<ul id="rsiScan_optionsVol24h" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkVol24h) {

                    if ($('#rsiScan_vol24h').val() == linkVol24h[i]) {
                        var affLib = 'Vol. 24h > <span class="btnToolsOn">' + linkVol24h[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkVol24h[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'rsiScan_conf($(\'#rsiScan_exchange\').val(), $(\'#rsiScan_interval\').val(), ' + linkVol24h[i] + ');';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkVol24h[i] + '</a></li>';
                    }
                }

                $('#rsiScan_optionsVol24h').html(options);
                $('#lib_rsiScan_vol24h').html(affLib);
            }


            // rsiScan_selectExchange();         // Affichage du selecteur d'exchange

            // function appelée dans 'rsiScan_selectExchangeAux' pour respecter l'ordre des bouton et attendre le retour Ajax de la liste d'exchanges
            // rsiScan_selectInterval();      // Affichage du selecteur d'interval


            //
            // Mise à jour au click sur l'onglet
            //
            $('#table_scanners_id').on('click', '#table_scanners_id3', function() {

                // Vide le champ de recherche
                $('#table_rsiScan').bootstrapTable('resetSearch', '');

                rsiScan_selectExchange();
            });

            $('#rightTabs_id').on('click', '#rightTabs_id0', function() {
                if ( $('#table_scanners_id3').attr('class') == 'active' ) {

                    // Vide le champ de recherche
                    $('#table_rsiScan').bootstrapTable('resetSearch', '');

                    rsiScan_selectExchange();
                }
            });

            // Affichage de l'heure de mise à jour
            function clockResfreshRsiScan()
            {
                $('#table_scanners_tab3 h3').html('<span class="text-refresh-scan-min">Updated:</span><span class="text-refresh-scan">Last updated:</span> ' + localTime());

                if ( $(window).width() < 660 ) {
                    $('.text-refresh-scan-min').show();
                    $('.text-refresh-scan').hide();
                } else {
                    $('.text-refresh-scan-min').hide();
                    $('.text-refresh-scan').show();
                }
            }
eof;

        return $js;
    }
}
