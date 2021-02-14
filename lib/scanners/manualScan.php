<?php
namespace cryptos\scanners;

/**
 * Recherche manuelle d'opportunités
 *
 * @author Daniel Gomes
 */
class manualScan
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
    public function marketListCurl()
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$this->_exchange] . '/scanners/manualScan.php';

        $postFields = array(
            'exchange'  => $this->_exchange,
            'vol24h'    => $this->_vol24h,
        );

        $dataSet = \core\curl::curlPost($urlCurl, $postFields);

        return json_decode($dataSet, true);
    }


    /**
     * Classement des markets d'un exchange par évolution des prix dans un interval de temps défini
     *
     * @param       integer     $delta          nombre d'unités d'interval de temps
     * @param       string      $unite          Unité de l'interval de temps
     *
     * @return      array
     */
    public function marketList()
    {
        $marketList = array();

        $i=0;
        foreach ($this->_tablesList as $tableMarket) {

            $marketName = $this->recupMarketName($tableMarket);

            $marketList[$i]['exchange']   = $this->_exchange;
            $marketList[$i]['marketName'] = $this->recupMarketName($tableMarket);
            $marketList[$i]['price24h']   = $this->calculPct24h($tableMarket, 'last');
            $marketList[$i]['vol24h']     = $this->calculPct24h($tableMarket, 'volume');
            $marketList[$i]['volume']     = $this->lastVolume($tableMarket);
            $marketList[$i]['urlMarket']  = $this->linkMarketEx($marketName);

            $i++;
        }

        return $marketList;
    }


    /**
     * Calcul de l'évolution du prix les 24 dernières heures
     */
    private function calculPct24h($tableMarket, $chp)
    {
        // Récupération du last le plus bas dans l'intervalle souhaité
        $req1 = "SELECT     $chp
                 FROM       $tableMarket
                 WHERE      date_modif >= DATE_ADD(NOW(), INTERVAL -24 HOUR)
                 ORDER BY   id ASC
                 LIMIT      1";

        $sql1 = $this->_dbh->query($req1);

        // Pas de nouvelles entrées dans l'intervalle de temps
        if ($sql1->rowCount() == 0) {

            $pct = '-';

        // Calcul de la différence entre les 2 entrées
        } else {

            $res1    = $sql1->fetch();
            $oldLast = $res1->{$chp};

            $req2 = "SELECT     $chp
                     FROM       $tableMarket
                     WHERE      date_modif >= DATE_ADD(NOW(), INTERVAL -24 HOUR)
                     ORDER BY   id DESC
                     LIMIT      1";

            $sql2 = $this->_dbh->query($req2);
            $res2 = $sql2->fetch();
            $last = $res2->{$chp};

            if ($oldLast != 0) {
                $pct = (100 / $oldLast) * ($last - $oldLast);
            } else {
                $pct = '-';
            }
        }

        $pct = round($pct, 2);
        $pct = number_format($pct, 2, ".", "");

        return ($pct);
    }


    /**
     * Récupération du dernier volume exprimé dans la monnaie de référence
     */
    private function lastVolume($tableMarket)
    {
        $req = "SELECT volume, baseVolume, last FROM $tableMarket ORDER BY id DESC LIMIT 1";
        $sql = $this->_dbh->query($req);
        $res = $sql->fetch();
        $baseVolume = $res->baseVolume;

        if (empty($baseVolume)) {
            $baseVolume = $res->volume * $res->last;
        }

        $marketName = $this->recupMarketName($tableMarket);
        $expMarket  = explode('-', $marketName);
        $cryptoRef  = $expMarket[0];

        if ($cryptoRef != 'BTC' && $cryptoRef != 'XBT') {

            if ($cryptoRef == 'USD' || $cryptoRef == 'USDT' || $cryptoRef == 'EUR') {
                $tableCheck1 = 'market_' . strtolower($cryptoRef) . '_BTC';
                $tableCheck2 = 'market_' . strtolower($cryptoRef) . '_XBT';
            } else {
                $tableCheck1 = 'market_btc_' . strtolower($cryptoRef);
                $tableCheck2 = 'market_xbt_' . strtolower($cryptoRef);
            }

            // Vérification de l'existence de la table
            $bddExchange = 'cryptos_ex_' . $this->_exchange;

            $reqTable = "SELECT  table_name AS exTable
                         FROM    information_schema.tables
                         WHERE   (table_name = '$tableCheck1' OR table_name = '$tableCheck2')
                         AND     table_schema = '$bddExchange'";

            $sqlTable = $this->_dbh->query($reqTable);

            if ($sqlTable->rowCount() > 0) {

                $resTable = $sqlTable->fetch();
                $table = $resTable->exTable;

                $req = "SELECT last FROM $table ORDER BY id DESC LIMIT 1";
                $sql = $this->_dbh->query($req);

                if ($sql->rowCount() > 0) {

                    $res = $sql->fetch();
                    $last = $res->last;

                    if (substr($table, 7, 3) == 'usd' || substr($table, 7, 3) == 'eur') {
                        $baseVolume = $baseVolume / $last;
                    } else {
                        $baseVolume = $baseVolume * $last;
                    }

                } else {
                    error_log('Manual Scan echec 1 : Impossible de convertir le baseVolume en BTC !');
                }
            } else {
                error_log('Manual Scan echec 2 : Impossible de convertir le baseVolume en BTC !');
            }
        }

        return ($baseVolume);
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
    public static function manualScanJS()
    {
        $js = <<<eof

            //
            // Mise à jour de la date et heure de la dernière recherche du scanner de prix
            //
            $('#table_scanners_tab4').on('click', 'button[name="refresh"]', function () {

                var exchange = $('#manualScan_exchange').val();
                var vol24h   = $('#manualScan_vol24h').val();

                var url = 'scanners/inc/manualScan.php';
                var get = 'exchange=' + exchange + '&vol24h=' + vol24h;

                $('#table_manualScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Affichage de l'heure de mise à jour
                clockResfreshManualScan();

                // Affichage / Masquage auto des colonnes
                hideColumnManualScan();
            });


            //
            // Modification de la configuration de l'orderBook
            //
            function manualScan_conf(exchange, vol24h)
            {
                $('#manualScan_exchange').val(exchange);
                $('#manualScan_vol24h').val(vol24h);

                manualScan_selectExchange();
            }


            //
            // Selection d'un exchange pour le scan
            //
            function manualScan_selectExchange()
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
                        manualScan_selectExchangeAux(data);

                    }, 'json');

                } else {

                    var exchanges = $('#allExchanges').val().split('|');
                    manualScan_selectExchangeAux(exchanges);
                }
            }

            function manualScan_selectExchangeAux(exchangesList)
            {
                if ($('#manualScan_btnExchanges').length == 0) {

                    $('#manualScan_tools').append('<div id="manualScan_btnExchanges" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_manualScan_exchanges"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#manualScan_btnExchanges').append(btnSelect);
                    $('#manualScan_btnExchanges').append('<ul id="manualScan_optionsExchanges" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in exchangesList) {

                    var nameExchange = capitalize(exchangesList[i]);

                    if ($('#manualScan_exchange').val() == exchangesList[i]) {
                        var affLib = 'Exchange : <span class="btnToolsOn">' + nameExchange + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + nameExchange + '</span></a></li>';
                    } else {
                        var jsFunction = 'manualScan_conf( \'' + exchangesList[i] + '\', $(\'#manualScan_vol24h\').val() );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + nameExchange + '</a></li>';
                    }
                }

                $('#manualScan_optionsExchanges').html(options);
                $('#lib_manualScan_exchanges').html(affLib);

                // Affichage du sélecteur de volume 24h
                manualScan_selectVol24h();

                // Refresh du tableau
                var exchange = $('#manualScan_exchange').val();
                var vol24h   = $('#manualScan_vol24h').val();

                var url = 'scanners/inc/manualScan.php';
                var get = 'exchange=' + exchange + '&vol24h=' + vol24h;

                $('#table_manualScan').bootstrapTable('refresh', {
                    url: url + '?json&' + get
                });

                // Maj du bouton d'export CSV
                $('#table_manualScan_csv').attr( 'onclick', "window.open('" + url + "?csv&" + get + "');" );

                // Affichage de l'heure de mise à jour
                clockResfreshManualScan();

                // Affichage / Masquage auto des colonnes
                hideColumnManualScan();
            }


            //
            // Selection du volume 24h minimum
            //
            function manualScan_selectVol24h()
            {
                var linkVol24h = new Array(0, 25, 50, 75, 100, 150, 200, 300, 400, 500, 750, 1000, 1500, 2000);

                if ($('#manualScan_btnVol24h').length == 0) {

                    $('#manualScan_tools').append('<div id="manualScan_btnVol24h" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="lib_manualScan_vol24h"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#manualScan_btnVol24h').append(btnSelect);
                    $('#manualScan_btnVol24h').append('<ul id="manualScan_optionsVol24h" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkVol24h) {

                    if ($('#manualScan_vol24h').val() == linkVol24h[i]) {
                        var affLib = 'Vol. 24h > <span class="btnToolsOn">' + linkVol24h[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkVol24h[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'manualScan_conf( $(\'#manualScan_exchange\').val(), ' + linkVol24h[i] + ' );';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkVol24h[i] + '</a></li>';
                    }
                }

                $('#manualScan_optionsVol24h').html(options);
                $('#lib_manualScan_vol24h').html(affLib);
            }


            //
            // Mise à jour au click sur l'onglet
            //
            $('#table_scanners_id').on('click', '#table_scanners_id4', function() {

                // Vide le champ de recherche
                $('#table_manualScan').bootstrapTable('resetSearch');

                manualScan_selectExchange();
            });

            $('#rightTabs_id').on('click', '#rightTabs_id0', function() {
                if ( $('#table_scanners_id4').attr('class') == 'active' ) {

                    // Vide le champ de recherche
                    $('#table_manualScan').bootstrapTable('resetSearch');

                    manualScan_selectExchange();
                }
            });

            // Affichage de l'heure de mise à jour
            function clockResfreshManualScan()
            {
                $('#table_scanners_tab4 h3').html('<span class="text-refresh-scan-min">Updated:</span><span class="text-refresh-scan">Last updated:</span> ' + localTime());

                if ( $(window).width() < 660 ) {
                    $('.text-refresh-scan-min').show();
                    $('.text-refresh-scan').hide();
                } else {
                    $('.text-refresh-scan-min').hide();
                    $('.text-refresh-scan').show();
                }
            }

            // Responsive Design - Masquage auto des colonnes
            function hideColumnManualScan()
            {
                if ( $(window).width() < 500 ) {
                    $('#table_manualScan').bootstrapTable('hideColumn', 'vol24h');
                } else {
                    $('#table_manualScan').bootstrapTable('showColumn', 'vol24h');
                }
            }

            $(window).resize(function() {
                if ( $('#rightTabs_id0').attr('class') == 'active' && $('#table_scanners_id4').attr('class') == 'active' ) {
                    hideColumnManualScan();
                }
            });
eof;

        return $js;
    }
}
