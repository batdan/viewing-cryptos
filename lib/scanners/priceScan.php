<?php
namespace cryptos\scanners;

/**
 * Création d'un classement sur les évolutions de prix
 * dans un interval de temps défini et avec l'exchange choisi
 *
 * @author Daniel Gomes
 */
class priceScan
{
    /**
	 * Attributs
	 */
    private $_exchange;                         // Nom de l'exchange

    private $_dbh;                              // Instance PDO

    private $_nameExBDD;                        // Nom de la base de données de l'Exchange
    private $_tablesList;                       // Liste des tables de market en BDD

    private $_prefixeTable  = 'market_';        // Préfixe des tables de market


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
     * Méthode cURL pour la création du tableau de données
     * Compilation des données déportée sur le serveur de collecte
     */
    public function pricePctCurl($interval, $vol24h)
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$this->_exchange] . '/scanners/priceScan.php';

        $postFields = array(
            'exchange' => $this->_exchange,
            'interval' => $interval,
            'vol24h'   => $vol24h,
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
    public function pricePct($interval, $vol24h=150)
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
            case 86400  : $delta = 24;      $unite = 'HOUR';        $txtDelta = '24 hours';     break;
        }

        // Déclaration du tableau de résultats
        $pricePct = array();

        // Récupération de la plage pour cet interval
        $plage = $this->plage($delta, $unite);

        foreach ($this->_tablesList as $tableMarket) {

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

                        $req = "SELECT last FROM $table ORDER BY id DESC LIMIT 1";
                        $sql = $this->_dbh->query($req);
                        $res = $sql->fetch();

                        if ($res->last > 0) {
                            $checkVol24h = $vol24h * $res->last;
                        } else {
                            continue;
                        }

                    } catch (\Exception $e) {

                        //error_log($e);
                        continue;
                    }

                } else {

                    try {
                        $table = 'market_btc_' . strtolower($cryptoREF);

                        $req = "SELECT last FROM $table ORDER BY id DESC LIMIT 1";
                        $sql = $this->_dbh->query($req);
                        $res = $sql->fetch();

                        if ($res->last > 0) {
                            $checkVol24h = $vol24h / $res->last;
                        } else {
                            continue;
                        }


                    } catch (\Exception $e) {

                        //error_log($e);
                        continue;
                    }
                }
            } else {
                $checkVol24h = $vol24h;
            }

            // Vérification du volume du market et du dernier last
            $req0 = "SELECT     baseVolume, last
                     FROM       $tableMarket
                     ORDER BY   id DESC
                     LIMIT      1";

            $sql0 = $this->_dbh->query($req0);

            if ($sql0->rowCount() == 0) {
                continue;
            } else {

                $res0 = $sql0->fetch();

                if ($res0->baseVolume < $checkVol24h) {
                    continue;
                } else {
                    $last = $res0->last;
                }
            }

            // Récupération du last le plus bas dans l'intervalle souhaité
            $req1 = "SELECT     last
                     FROM       $tableMarket
                     WHERE      date_modif >= DATE_ADD(NOW(), INTERVAL -$delta $unite)
                     ORDER BY   id ASC
                     LIMIT      1";

            $sql1 = $this->_dbh->query($req1);

            // Pas de nouvelles entrées dans l'intervalle de temps
            if ($sql1->rowCount() == 0) {

                $pct = 0;

            // Calcul de la différence entre les 2 entrées
            } else {

                $res1    = $sql1->fetch();
                $oldLast = $res1->last;

                $req2 = "SELECT     last
                         FROM       $tableMarket
                         WHERE      date_modif >= DATE_ADD(NOW(), INTERVAL -$delta $unite)
                         ORDER BY   id DESC
                         LIMIT      1";

                $sql2 = $this->_dbh->query($req2);
                $res2 = $sql2->fetch();
                $last = $res2->last;

                $pct = (100 / $oldLast) * ($last - $oldLast);
            }

            $pct = round($pct, 2);
            $pct = number_format($pct, 2, ".", "");

            // Tableau retourné par market
            $pricePct[] = array(
                'exchange'      => $this->_exchange,
                'pct'           => $pct,
                'marketName'    => $marketName,
                'urlMarket'     => $this->linkMarketEx($marketName),
                'last'          => $last,
                //'oldLast'     => $oldLast,
                //'txtDelta'    => $txtDelta,
            );
        }

        // Tableau trié sur les pourcentage
        $rang = array();
        if (count($pricePct) > 0) {
            foreach ($pricePct as $key => $val) {
                $rang[$key]  = $val['pct'];
            }

            // Trie les données par rang décroissant sur la colonne 'pct'
            array_multisort($rang, SORT_DESC, $pricePct);
        }

        return $pricePct;
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
    public static function priceScanJS()
    {
        $js = <<<eof

            //
            // Mise à jour de la date et heure de la dernière recherche du scanner de prix
            //
            $('#table_scanners_tab0').on('click', 'button[name="refresh"]', function () {

                var url = 'scanners/inc/priceScan.php';
                var get = 'exchange=' + $('#priceScan_exchange').val() + '&interval=' + $('#priceScan_interval').val() + '&vol24h=' + $('#priceScan_vol24h').val()

                // Refresh du tableau
                $('#table_priceScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Affichage de l'heure de mise à jour
                clockResfreshPriceScan();
            });


            //
            // Modification de la configuration du scanner
            //
            function priceScan_conf(exchange, interval, vol24h)
            {
                $('#priceScan_exchange').val(exchange);
                $('#priceScan_interval').val(interval);
                $('#priceScan_vol24h').val(vol24h);

                priceScan_selectExchange();
            }


            //
            // Selection d'un exchange pour le scan
            //
            function priceScan_selectExchange()
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
                        priceScan_selectExchangeAux(data);

                    }, 'json');

                } else {

                    var exchanges = $('#allExchanges').val().split('|');
                    priceScan_selectExchangeAux(exchanges);
                }
            }

            function priceScan_selectExchangeAux(exchangesList)
            {
                if ($('#priceScan_btnExchanges').length == 0) {

                    $('#priceScan_tools').append('<div id="priceScan_btnExchanges" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_priceScan_exchanges"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#priceScan_btnExchanges').append(btnSelect);
                    $('#priceScan_btnExchanges').append('<ul id="priceScan_optionsExchanges" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in exchangesList) {

                    var nameExchange = capitalize(exchangesList[i]);

                    if ($('#priceScan_exchange').val() == exchangesList[i]) {
                        var affLib = 'Exchange : <span class="btnToolsOn">' + nameExchange + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + nameExchange + '</span></a></li>';
                    } else {
                        var jsFunction = 'priceScan_conf( \'' + exchangesList[i] + '\', $(\'#priceScan_interval\').val(), $(\'#priceScan_vol24h\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + nameExchange + '</a></li>';
                    }
                }

                $('#priceScan_optionsExchanges').html(options);
                $('#lib_priceScan_exchanges').html(affLib);

                // Affichage du sélecteur d'interval
                priceScan_selectInterval();

                // Affichage du sélecteur de volume 24h
                priceScan_selectVol24h();

                // Refresh du tableau
                var url = 'scanners/inc/priceScan.php';
                var get = 'exchange=' + $('#priceScan_exchange').val() + '&interval=' + $('#priceScan_interval').val() + '&vol24h=' + $('#priceScan_vol24h').val()

                $('#table_priceScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Maj du bouton d'export CSV
                $('#table_priceScan_csv').attr( 'onclick', "window.open('" + url + "?csv&" + get + "');" );

                // Affichage de l'heure de mise à jour
                clockResfreshPriceScan();
            }


            //
            // Selection d'un delta avec l'heure actuelle pour la comparaison
            //
            function priceScan_selectInterval()
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
                    86400 : '24h',
                };

                if ($('#priceScan_btnInterval').length == 0) {

                    $('#priceScan_tools').append('<div id="priceScan_btnInterval" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_priceScan_interval"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#priceScan_btnInterval').append(btnSelect);
                    $('#priceScan_btnInterval').append('<ul id="priceScan_optionsInterval" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkIntervals) {

                    if ($('#priceScan_interval').val() == i) {
                        var affInterval = 'Period : <span class="btnToolsOn">' + linkIntervals[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkIntervals[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'priceScan_conf( $(\'#priceScan_exchange\').val(), ' + i + ', $(\'#priceScan_vol24h\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkIntervals[i] + '</a></li>';
                    }
                }

                $('#priceScan_optionsInterval').html(options);
                $('#lib_priceScan_interval').html(affInterval);
            }


            //
            // Selection du volume 24h minimum
            //
            function priceScan_selectVol24h()
            {
                var linkVol24h = new Array(0, 25, 50, 75, 100, 150, 200, 300, 400, 500, 750, 1000, 1500, 2000);

                if ($('#priceScan_btnVol24h').length == 0) {

                    $('#priceScan_tools').append('<div id="priceScan_btnVol24h" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_priceScan_vol24h"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#priceScan_btnVol24h').append(btnSelect);
                    $('#priceScan_btnVol24h').append('<ul id="priceScan_optionsVol24h" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkVol24h) {

                    if ($('#priceScan_vol24h').val() == linkVol24h[i]) {
                        var affLib = 'Vol. 24h > <span class="btnToolsOn">' + linkVol24h[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkVol24h[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'priceScan_conf($(\'#priceScan_exchange\').val(), $(\'#priceScan_interval\').val(), ' + linkVol24h[i] + ');';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkVol24h[i] + '</a></li>';
                    }
                }

                $('#priceScan_optionsVol24h').html(options);
                $('#lib_priceScan_vol24h').html(affLib);
            }

            priceScan_selectExchange();         // Affichage du selecteur d'exchange

            // function appelée dans 'priceScan_selectExchangeAux' pour respecter l'ordre des bouton et attendre le retour Ajax de la liste d'exchanges
            // priceScan_selectInterval();      // Affichage du selecteur d'interval


            //
            // Mise à jour au click sur l'onglet
            //
            $('#table_scanners_id').on('click', '#table_scanners_id0', function() {

                // Vide le champ de recherche
                $('#table_priceScan').bootstrapTable('resetSearch', '');

                priceScan_selectExchange();
            });

            $('#rightTabs_id').on('click', '#rightTabs_id0', function() {
                if ( $('#table_scanners_id0').attr('class') == 'active' ) {

                    // Vide le champ de recherche
                    $('#table_priceScan').bootstrapTable('resetSearch', '');

                    priceScan_selectExchange();
                }
            });

            // Affichage de l'heure de mise à jour
            function clockResfreshPriceScan()
            {
                // Affichage de l'heure de mise à jour
                $('#table_scanners_tab0 h3').html('<span class="text-refresh-scan-min">Updated:</span><span class="text-refresh-scan">Last updated:</span> ' + localTime());

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
