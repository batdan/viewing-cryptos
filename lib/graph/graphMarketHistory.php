<?php
namespace cryptos\graph;

/**
 * Préparation des données pour l'affichage d'un graph d'orderBook
 *
 * @author Daniel Gomes
 */
class graphMarketHistory
{
    /**
     * Méthode cURL pour la création du tableau de données
     * Compilation des données déportée sur le serveur de collecte
     */
    public static function prepareDataMarketHistoryCurl($exchange, $marketName, $timeZone, $crypto, $interval)
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$exchange] . '/charts/chartMarketHistory.php';

        $postFields = array(
            'exchange'  => $exchange,
            'marketName'=> $marketName,
            'timeZone'  => $timeZone,
            'crypto'    => $crypto,
            'interval'  => $interval,
        );

        $dataSet = \core\curl::curlPost($urlCurl, $postFields);

        return json_decode($dataSet, true);
    }


    /**
     * Préparation des données pour l'affichage du graphique marketHistory
     *
     * @param       string      $marketName         Nom du market
     * @param       string      $timeZone           Nom du fuseau horaire pour afficher des heures locales
     * @param       string      $crypto             Crypto utilisée comme référence pour l'affichage des volumes en ordonnée du graphique (yAxis)
     * @param       integer     $interval           Interval souhaité exprimé en secondes
     */
    public static function prepareDataMarketHistory($exchange, $marketName, $timeZone, $crypto, $interval=10)
    {
        // Nom de la base de données de l'Exchange
        $nameExBDD = 'cryptos_ex_' . $exchange;

        // Ouverture de l'instance PDO
        $dbh = \core\dbSingleton::getInstance($nameExBDD);

        $table_mh       = 'mh_' . str_replace('-', '_', strtolower($marketName));
        $table_market   = 'market_' . str_replace('-', '_', strtolower($marketName));

        $plages = self::plages($interval);

        // L'unité des volumes (yAxis) pour s'afficher dans la monnaie de référence ou dans la monnaie tradée
        $expMarket = explode('-', $marketName);
        switch ($crypto)
        {
            case $expMarket[0] : $unitField = 'total';       break;
            case $expMarket[1] : $unitField = 'quantity';    break;
            default            : $unitField = 'total';
        }

        // Préparation de la requête de market history
        $req = "SELECT      SUM($unitField) AS volume

                FROM        $table_mh

                WHERE       orderType   =  :orderType
                AND         timestampEx >= :date_deb
                AND         timestampEx <  :date_end";
        $sql = $dbh->prepare($req);

        // Préparation de la requête de prix
        $req2 = "SELECT last FROM $table_market WHERE timestampEx < :date_end ORDER BY id DESC LIMIT 1";
        $sql2 = $dbh->prepare($req2);

        $buy   = array();
        $sell  = array();
        $price = array();

        foreach ($plages as $plage) {

            // Calcul du plot xAxis en tenant compte du fuseau horaire
            $offset = self::getOffsetTimeZone($timeZone);
            // Timestamp exprimé en millisecondes dans highcharts
            $xVal = (($plage[2] + $offset) * 1000);

            // Somme des 'buy'
            $sql->execute(array(
                ':orderType' => 'BUY',
                ':date_deb'  => $plage[0],
                ':date_end'  => $plage[1],
            ));

            $res  = $sql->fetch();

            $buy[] = array(
                $xVal,   // Timestamp exprimé en millième de secondes dans highcharts
                floatval($res->volume),
            );

            // Somme des 'sell'
            $sql->execute(array(
                ':orderType' => 'SELL',
                ':date_deb'  => $plage[0],
                ':date_end'  => $plage[1],
            ));

            $res = $sql->fetch();

            $sell[] = array(
                $xVal,
                floatval($res->volume),
            );

            // Prix
            $sql2->execute(array(
                ':date_end'  => $plage[1],
            ));

            $res2 = $sql2->fetch();

            $price[] = array(
                $xVal,
                floatval($res2->last),
            );
        }

        // Fermeture de l'instance PDO
        // \core\dbSingleton::closeInstance($nameExBDD);

        return array(
            'exchange'  => ucfirst($exchange),
            'buy'       => $buy,
            'sell'      => $sell,
            'price'     => $price,
            'yAxisUnit' => $crypto,
            'cryptoRef' => $expMarket[0],
        );
    }

    /**
     * Création des groupes de range
     */
    private static function groupeRanges($interval)
    {
        $groupeRanges = array(
            10    => array('unite'  => 's', 'values' => @range(0, 50, 10)),
            15    => array('unite'  => 's', 'values' => @range(0, 45, 15)),
            30    => array('unite'  => 's', 'values' => @range(0, 30, 30)),
            60    => array('unite'  => 'i', 'values' => @range(0, 59,  1)),
            120   => array('unite'  => 'i', 'values' => @range(0, 58,  2)),
            180   => array('unite'  => 'i', 'values' => @range(0, 57,  3)),
            240   => array('unite'  => 'i', 'values' => @range(0, 56,  4)),
            300   => array('unite'  => 'i', 'values' => @range(0, 55,  5)),
            600   => array('unite'  => 'i', 'values' => @range(0, 50, 10)),
            900   => array('unite'  => 'i', 'values' => @range(0, 45, 15)),
            1800  => array('unite'  => 'i', 'values' => @range(0, 30, 30)),
            3600  => array('unite'  => 'H', 'values' => @range(0, 23,  1)),
            7200  => array('unite'  => 'H', 'values' => @range(0, 22,  2)),
            10800 => array('unite'  => 'H', 'values' => @range(0, 21,  3)),
            14400 => array('unite'  => 'H', 'values' => @range(0, 20,  4)),
            21600 => array('unite'  => 'H', 'values' => @range(0, 18,  6)),
            43200 => array('unite'  => 'H', 'values' => @range(0, 12,  12)),
            86400 => array('unite'  => 'H', 'values' => @range(0, 0,  24)),
        );

        return $groupeRanges[$interval];
    }


    /**
     * Récupération des plages d'interval pour les requêtes
     * de calcul des volumes des 'buy' et des 'sell'
     *
     * @param       integer     $interval           Interval souhaité exprimé en secondes
     */
    public static function plages($interval)
    {
        $dateTime   = gmdate('Y-m-d H:i:s');
        $range      = self::groupeRanges($interval);
        $unite      = date($range['unite']);

        foreach ($range['values'] as $key => $val)
        {
            if ($unite >= $val) {
                if (isset($range['values'][$key+1])) {
                    $max = $range['values'][$key+1];
                    $uniteSupAdd = 0;
                } else {
                    $max = 0;
                    $uniteSupAdd = 1;
                }
            }
        }

        $d = new \DateTime($dateTime);
        $timestamp = $d->getTimestamp();

        // range en secondes
        if ($range['unite'] == 's') {
            if ($uniteSupAdd == 0) {
                $maxTimestamp = $timestamp + ($max - $unite);
            } else {
                $maxTimestamp = $timestamp + ($max - $unite) + 60;              // 60 : Nb de secondes par minute
            }
        }

        // Range en minutes
        if ($range['unite'] == 'i') {
            if ($uniteSupAdd == 0) {
                $maxTimestamp = $timestamp + (($max - $unite) * 60);
            } else {
                $maxTimestamp = $timestamp + (($max - $unite) * 60) + 3600;     // 3600 : Nb de secondes par heure
            }

            $maxTimestamp -= $d->format('s');
        }

        // Range en heures
        if ($range['unite'] == 'H') {
            if ($uniteSupAdd == 0) {
                $maxTimestamp = $timestamp + (($max - $unite) * 3600);
            } else {
                $maxTimestamp = $timestamp + (($max - $unite) * 3600) + 86400;  // 86400 : Nb de secondes par jour
            }

            $maxTimestamp -= $d->format('i') * 60;
            $maxTimestamp -= $d->format('s');
        }

        $d->setTimestamp($maxTimestamp);
        $maxDateTime = $d->format('Y-m-d H:i:s');

        // Création des 10 intervals de temps en tenant compte du timezone du client
        $intervals = array();

        for ($i=0; $i<=12; $i++) {

            // dateTime début UTC
            $deb = $maxTimestamp - ($interval * ($i + 1));
            $d->setTimestamp($deb);
            $debDateTimeUTC = $d->format('Y-m-d H:i:s');

            // dateTime fin UTC
            $end = $maxTimestamp - ($interval * $i);
            $d->setTimestamp($end);
            $endDateTimeUTC = $d->format('Y-m-d H:i:s');

            $intervals[] = array($debDateTimeUTC, $endDateTimeUTC, $end);
        }

        return array_reverse($intervals);
    }


    /**
     * Code Javascript
     */
    public static function highchartsJS($theme='dark')
    {
        switch ($theme)
        {
            case 'light' :

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
                // $seriePriceColor        = '#038392';
                $seriePriceColor        = 'rgba(3, 131, 146, 0.4)';

                break;

            case 'dark'  :

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
                $yAxisLabelsColor       = '#fff';
                $yAxisTickColor         = '#ccc';
                $yAxisTitlesColor       = '#ccc';

                $xAxisLabelsColor       = '#fff';
                $xAxisLineColor         = 'rgba(155, 155, 155, 0.3)';
                $xAxisTickColor         = 'rgba(155, 155, 155, 0.3)';

                $midPlotLineColor       = '#666';
                $midPlotTextColor       = '#ddd';

                $serieBidsColor         = '#2ecd57';
                $serieAsksColor         = '#cd2e2e';
                // $seriePriceColor        = '#038392';
                $seriePriceColor        = 'rgba(3, 131, 146, 0.4)';

                break;
        }

        $js = <<<eof

            //
            // Affichage du graphique
            //
            function mh_affHighcharts(data)
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

                Highcharts.setOptions({
                    global: {
                        useUTC: false
                    }
                });

                Highcharts.chart('mh_container', {
                    chart: {
                        backgroundColor: {
                            $chartBackground
                        },
                        //marginLeft: 20,
                        borderRadius: '$chartBorderRadius',
                        type: 'spline',
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
                        formatter: function () {

                            var myTooltip = '<b>' + this.series.name + '</b><br/>';
                            myTooltip += Highcharts.dateFormat('%Y-%m-%d %H:%M:%S', this.x) + '<br/>';

                            // Tooltip volume
                            if (this.series.yAxis.options.index == 0) {

                                var nbDecimal = 8;
                                if (data.yAxisUnit == 'USDT') {
                                    nbDecimal = 2;
                                }

                                myTooltip +=Highcharts.numberFormat(this.y, nbDecimal) + ' ' + data.yAxisUnit;

                            // Tooltip Prix
                            } else {

                                var nbDecimal = 8;
                                if (data.cryptoRef == 'USDT') {
                                    nbDecimal = 2;
                                }

                                myTooltip +=Highcharts.numberFormat(this.y, nbDecimal) + ' ' + data.cryptoRef;
                            }

                            return myTooltip;
                        },
                        style: {
                            color: '$toltipColor',
                        },
                        backgroundColor: '$toltipBackground'
                    },
                    yAxis: [{
                            gridLineColor: '$yAxisGridLineColor',
                            labels: {
                                style: {
                                    color: '$yAxisLabelsColor'
                                }
                            },
                            tickColor: '$yAxisTickColor',
                            title: {
                                text: 'Vol. : ' + data.yAxisUnit,
                                style: {
                                    color: '$yAxisTitlesColor'
                                }
                            },
                            plotLines: [{
                                value: 0,
                                width: 1,
                            }]
                        }, {
                            gridLineColor: '$yAxisGridLineColor',
                            labels: {
                                style: {
                                    color: '$yAxisLabelsColor'
                                }
                            },
                            tickColor: '$yAxisTickColor',
                            title: {
                                text: 'Price : ' + data.cryptoRef,
                                style: {
                                    color: '$yAxisTitlesColor'
                                }
                            },
                            plotLines: [{
                                value: 0,
                                width: 1,
                            }],
                            opposite: true
                    }],
                    xAxis: {
                        tickPixelInterval: 50,
                        labels: {
                            style: {
                                color: '$xAxisLabelsColor'
                            },
                            rotation: -45
                        },
                        lineColor: '$xAxisLineColor',
                        tickColor: '$xAxisTickColor',
                        type: 'datetime',
                    },
                    series: [
                        {
                            name: 'Buy',
                            color: '$serieBidsColor',
                            data: data.buy
                        },
                        {
                            name: 'Sell',
                            color: '$serieAsksColor',
                            data: data.sell
                        },
                        {
                            name: 'Price',
                            color: '$seriePriceColor',
                            data: data.price,
                            yAxis: 1
                        }
                    ],
                    plotOptions: {
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
            // Modification de la configuration du marketHistory
            //
            function mh_confGraph(interval, crypto)
            {
                $('#mh_myInterval').val(interval);
                $('#mh_myCrypto').val(crypto);

                mh_selectCrypto_yAxis();
                mh_selectIntervals();

                ajaxMarketHistory();
            }


            //
            // Selection des Intervals de temps du marketHistory
            //
            function mh_selectIntervals()
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
                    3600  : '1h',
                    7200  : '2h',
                    10800 : '3h',
                    14400 : '4h',
                    21600 : '6h',
                    // 43200 : '12h',
                    // 86400 : 'Day',
                };

                if ($('#mh_btnInterval').length == 0) {

                    $('#mh_toolsMarketHistory').append('<div id="mh_btnInterval" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="mh_interval"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#mh_btnInterval').append(btnSelect);
                    $('#mh_btnInterval').append('<ul id="mh_optionsInterval" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkIntervals) {
                    if ($('#mh_myInterval').val() == i) {
                        var affInterval = 'Interval : <span class="btnToolsOn">' + linkIntervals[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkIntervals[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'mh_confGraph(' + i + ', $(\'#mh_myCrypto\').val());';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkIntervals[i] + '</a></li>';
                    }
                }

                $('#mh_optionsInterval').html(options);
                $('#mh_interval').html(affInterval);
            }


            //
            // Selection de l'unité de volume en monnaie de référence ou monnaie tradée
            //
            function mh_selectCrypto_yAxis()
            {
                var linkCrypto    = $('#marketName').val().split('-');

                if ($('#mh_myCrypto').val() == '') {
                    $('#mh_myCrypto').val( linkCrypto[0] );
                }

                if ($('#mh_btnCryptoYAxis').length == 0) {

                    $('#mh_toolsMarketHistory').append('<div id="mh_btnCryptoYAxis" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="mh_cryptoYAxisLib"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#mh_btnCryptoYAxis').append(btnSelect);
                    $('#mh_btnCryptoYAxis').append('<ul id="mh_optionsCryptoYAxis" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkCrypto) {
                    if ($('#mh_myCrypto').val() == linkCrypto[i]) {
                        var affCryptoYAxisLib = 'yAxis : <span class="btnToolsOn">' + linkCrypto[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkCrypto[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'mh_confGraph($(\'#mh_myInterval\').val(), \'' + linkCrypto[i] + '\');';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkCrypto[i] + '</a></li>';
                    }
                }

                $('#mh_optionsCryptoYAxis').html(options);
                $('#mh_cryptoYAxisLib').html(affCryptoYAxisLib);
            }


            //
            // Selection de la fonction play / pause
            //
            function mh_selectPlayGraph()
            {
                if ( $('#mh_btnPlayGraph').length == 0 ) {

                    $('#mh_toolsMarketHistory').append('<div id="mh_btnPlayGraph" class="btn-group" role="group" title="Chart pause"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default" aria-haspopup="true" aria-expanded="false" onclick="mh_selectPlayGraphClick();">';
                    btnSelect    +=    '<span id="mh_playGraphLib"><i class="fa fa-pause greyBold"></i></span>';
                    btnSelect    += '</button>';

                    $('#mh_btnPlayGraph').html(btnSelect);
                }
            }

            function mh_selectPlayGraphClick()
            {
                if ($('#mh_playGraph').val() == 1) {
                    $('#mh_playGraphLib').html('<i class="fa fa-play colorBold2"></i>');
                    $('#mh_playGraph').val(0);
                    $('#mh_btnPlayGraph').attr('data-original-title', 'Chart play');
                } else {
                    $('#mh_playGraphLib').html('<i class="fa fa-pause greyBold"></i>');
                    $('#mh_playGraph').val(1);
                    $('#mh_btnPlayGraph').attr('data-original-title', 'Chart pause');

                    mh_confGraph( $('#mh_myInterval').val(), $('#mh_myCrypto').val() );
                }

                $('#mh_btnPlayGraph button').blur();
            }


            //
            // Appel Ajax pour l'affichage du marketHistory
            //
            function ajaxMarketHistory()
            {
                $.post("/app/ajax/ajaxGraphMarketHistory.php",
                {
                    exchange    : $('#exchange').val(),
                    marketName  : $('#marketName').val(),
                    timeZone    : getTimezoneName(),
                    crypto      : $('#mh_myCrypto').val(),
                    interval    : $('#mh_myInterval').val()
                },
                function success(data)
                {
                    //console.log(data);

                    //var title = 'Historique des achats et des ventes | ' + data.exchange + ', ' + $('#marketName').val();
                    //$('#mh_title').html(title);

                    mh_affHighcharts(data);

                }, 'json');
            }


            //
            // Mise à jour du marketHistory toutes les secondes
            //
            setInterval(function() {
                if ($('#mh_playGraph').val() == 1 && $('#leftTabs_id0').attr('class') == 'active' && $('#chartsTabs_id3').attr('class') == 'active' && $(document).scrollTop().valueOf() < 50) {
                    ajaxMarketHistory();
                }
            }, 1000);


            //
            // Mise à jour au click sur l'onglet
            //
            function majMarketHistory()
            {
                mh_selectCrypto_yAxis();    // Affichage du selecteur de crypto pour l'axe des ordonnées
                mh_selectIntervals();       // Affichage du selecteur d'intervals
                mh_selectPlayGraph();       // Selection de la fonction playGraph

                ajaxMarketHistory();        // Actualisation du marketHistory
            }

            $('#chartsTabs_id').on('click', '#chartsTabs_id3', function() {
                majMarketHistory()
            });

            $('#leftTabs_id').on('click', '#leftTabs_id0', function() {
                if ( $('#chartsTabs_id3').attr('class') == 'active' ) {
                    majMarketHistory();
                }
            });
eof;

        return $js;
    }


    /**
     * Récupération du nombre de secondes entre UTC et le fuseau horaire demandé
     *
     * @param       string      $timeZone       Nom du fuseau horaire - Ex : Europe/Paris
     * @return      integer
     */
    private static function getOffsetTimeZone($timeZone)
    {
        $dtz = new \DateTimeZone($timeZone);
        $dt  = new \DateTime('now', $dtz);

        // Z : Décalage horaire en secondes
        return $dt->format('Z');
    }
}
