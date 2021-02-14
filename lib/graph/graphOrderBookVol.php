<?php
namespace cryptos\graph;

/**
 * Préparation des données pour l'affichage d'un graph d'orderBook
 *
 * @author Daniel Gomes
 */
class graphOrderBookVol
{
    /**
     * Méthode cURL pour la création du tableau de données
     * Compilation des données déportée sur le serveur de collecte
     */
    public static function prepareDataOrderBookVolCurl($exchange, $marketName, $timeZone, $interval, $range)
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$exchange] . '/charts/chartOrderBookVol.php';

        $postFields = array(
            'exchange'  => $exchange,
            'marketName'=> $marketName,
            'timeZone'  => $timeZone,
            'interval'  => $interval,
            'range'     => $range,
        );

        $dataSet = \core\curl::curlPost($urlCurl, $postFields);

        return json_decode($dataSet, true);
    }


    /**
     * Récupération des cumuls des bids et des asks
     * Dans l'interval et le range souhaiter
     * pour alimenter le graphique
     *
     */
    public static function prepareDataOrderBookVol($exchange, $marketName, $timeZone, $interval, $range)
    {
        // Nom de la base de données de l'Exchange
        $nameExBDD = 'cryptos_ex_' . $exchange;

        $expMarket = explode('-', $marketName);
        $cryptoRef = $expMarket[0];
        $yAxisUnit = $expMarket[1];

        // Ouverture de l'instance PDO
        $dbh = \core\dbSingleton::getInstance($nameExBDD);

        $tableOrderBook = 'ob_' . str_replace('-', '_', strtolower($marketName));
        $tableMarket    = 'market_' . str_replace('-', '_', strtolower($marketName));

        $plages = self::plages($interval);

        // On vérifie si la table existe toujours
        $req_check = "SELECT  table_name AS exTable

                      FROM    information_schema.tables

                      WHERE   table_name   = '$tableOrderBook'
                      AND     table_schema = '$nameExBDD'";
                      $sql_check = $dbh->query($req_check);

        if ($sql_check->rowCount() > 0) {

            $bids  = array();
            $asks  = array();
            $price = array();

            // Requête préparée  :orderBook
            $req = "SELECT jsonOrderBook FROM $tableOrderBook WHERE date_crea > :date1 AND date_crea <= :date2 ORDER BY id DESC LIMIT 1";
            $sql = $dbh->prepare($req);

            // PRequête préparée : prix
            $req2 = "SELECT last FROM $tableMarket WHERE date_crea < :dateEnd ORDER BY id DESC LIMIT 1";
            $sql2 = $dbh->prepare($req2);

            foreach ($plages as $plage) {

                // Calcul du plot xAxis en tenant compte du fuseau horaire
                $offset = self::getOffsetTimeZone($timeZone);
                $offsetServeur = self::getOffsetTimeZone('Europe/Paris');

                // Timestamp exprimé en millisecondes dans highcharts
                $xVal = (($plage[2] - $offsetServeur + $offset) * 1000);

                // Récupération du dernier orderBook
                $sql->execute(array(
                    ':date1' => $plage[0],
                    ':date2' => $plage[1],
                ));

                if ($sql->rowCount() == 0)  {
                    continue;
                }

                $res = $sql->fetch();
                $jsonOrderBook = $res->jsonOrderBook;

                $analyseOrderBookRange = self::analyseOrderBookRange($jsonOrderBook, $range);

                $bids[] = array(
                    $xVal,
                    $analyseOrderBookRange['bids'],
                );

                $asks[] = array(
                    $xVal,
                    $analyseOrderBookRange['asks'],
                );

                // Prix
                $sql2->execute(array(
                    ':dateEnd' => $plage[1] . '%',
                ));

                $res2 = $sql2->fetch();

                $price[] = array(
                    $xVal,
                    floatval($res2->last),
                );
            }
        }


        // Fermeture de l'instance PDO
        // \core\dbSingleton::closeInstance($nameExBDD);

        return array(
            'exchange'  => ucfirst($exchange),
            'bids'      => $bids,
            'asks'      => $asks,
            'price'     => $price,
            'yAxisUnit' => $yAxisUnit,
            'cryptoRef' => $cryptoRef,
        );
    }


    /**
     * Analyse des volumes d'un orderBook dans le range défini
     *
     * @param       string      $jsonOrderBookOld
     * @param       integer     $range
     *
     * @return      array
     */
    private static function analyseOrderBookRange($jsonOrderBook, $range)
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
                //$bidsCumul += $bidQuantity * $bidRate;
                $bidsCumul += $bidQuantity;
            }
        }

        // Cumul des asks en respectant le range
        $asksCumul=0;
        foreach ($asks as $ask) {
            $askRate     = floatval($ask[0]);
            $askQuantity = floatval($ask[1]);

            if ($askRate < $askRateMax) {
                //$asksCumul += $askQuantity * $askRate;
                $asksCumul += $askQuantity;
            }
        }

        return array(
            'bids' => $bidsCumul,
            'asks' => $asksCumul,
        );
    }

    /**
     * Création des groupes de range
     */
    private static function groupeRanges($interval)
    {
        $groupeRanges = array(
            60   => array('unite'  => 'i', 'values' => range(0, 59, 1)),
            120  => array('unite'  => 'i', 'values' => range(0, 58, 2)),
            180  => array('unite'  => 'i', 'values' => range(0, 57, 3)),
            240  => array('unite'  => 'i', 'values' => range(0, 56, 4)),
            300  => array('unite'  => 'i', 'values' => range(0, 55, 5)),
            600  => array('unite'  => 'i', 'values' => range(0, 50, 10)),
            900  => array('unite'  => 'i', 'values' => range(0, 45, 15)),
            1800 => array('unite'  => 'i', 'values' => range(0, 30, 30)),
            3600 => array('unite'  => 'i', 'values' => range(0,  0, 60)),
        );

        return $groupeRanges[$interval];
    }


    /**
     * Récupération des plages d'interval pour les requêtes
     * de calcul des volumes des 'buy' et des 'sell'
     *
     * @param       integer     $interval           Interval souhaité exprimé en secondes
     */
    private static function plages($interval)
    {
        //$dateTime   = gmdate('Y-m-d H:i:s');
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

        //$d = new \DateTime($dateTime);
        $d = new \DateTime();
        $timestamp = $d->getTimestamp();

        // range en secondes
        if ($range['unite'] == 's') {
            if ($uniteSupAdd == 0) {
                $maxTimestamp = $timestamp + ($max - $unite);
            } else {
                $maxTimestamp = $timestamp + ($max - $unite) + 60;
            }
        }

        // Range en minutes
        if ($range['unite'] == 'i') {
            if ($uniteSupAdd == 0) {
                $maxTimestamp = $timestamp + (($max - $unite) * 60);
            } else {
                $maxTimestamp = $timestamp + (($max - $unite) * 60) + 3600;
            }

            $maxTimestamp -= $d->format('s');
        }

        $d->setTimestamp($maxTimestamp);
        $maxDateTime = $d->format('Y-m-d H:i:s');

        // Création des 10 intervals de temps en tenant compte du timezone du client
        $intervals = array();

        for ($i=0; $i<12; $i++) {

            // dateTime début UTC
            $deb = $maxTimestamp - ($interval * ($i + 1));
            $d->setTimestamp($deb);
            $debDateTimeUTC = $d->format('Y-m-d H:i');

            // dateTime fin UTC
            $end = $maxTimestamp - ($interval * $i);
            $d->setTimestamp($end);
            $endDateTimeUTC = $d->format('Y-m-d H:i');

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
                // $seriePriceColor     = '#038392';
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
                $yAxisLabelsColor       = '#ccc';
                $yAxisTickColor         = '#ccc';
                $yAxisTitlesColor       = '#ccc';

                $xAxisLabelsColor       = '#ccc';
                $xAxisLineColor         = 'rgba(155, 155, 155, 0.3)';
                $xAxisTickColor         = 'rgba(155, 155, 155, 0.3)';

                $midPlotLineColor       = '#666';
                $midPlotTextColor       = '#ddd';

                $serieBidsColor         = '#2ecd57';
                $serieAsksColor         = '#cd2e2e';
                // $seriePriceColor     = '#038392';
                $seriePriceColor        = 'rgba(3, 131, 146, 0.4)';

                break;
        }

        $js = <<<eof

            //
            // Affichage du graphique
            //
            function obv_affHighcharts(data)
            {
                var graphHeight = $(window).height() - 244;
                if (graphHeight < 360) {
                    graphHeight = 360;
                }

                Highcharts.setOptions({
                    global: {
                        useUTC: false
                    }
                });

                Highcharts.chart('obv_container', {
                    chart: {
                        backgroundColor: {
                            $chartBackground
                        },
                        borderRadius: '$chartBorderRadius',
                        // marginLeft: 20,
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

                            // Tooltip orderBook
                            if (this.series.yAxis.options.index == 0) {

                                var nbDecimal = 8;
                                if (data.yAxisUnit == 'USDT') {
                                    nbDecimal = 2;
                                }

                                myTooltip += Highcharts.numberFormat(this.y, nbDecimal) + ' ' + data.yAxisUnit;

                            // Tooltip Prix
                            } else {

                                var nbDecimal = 8;
                                if (data.cryptoRef == 'USDT') {
                                    nbDecimal = 2;
                                }

                                myTooltip += Highcharts.numberFormat(this.y, nbDecimal) + ' ' + data.cryptoRef;
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
                            name: 'Vol. bids',
                            color: '$serieBidsColor',
                            data: data.bids
                        }, {
                            name: 'Vol. asks',
                            color: '$serieAsksColor',
                            data: data.asks
                        }, {
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
            // Modification de la configuration de l'orderBookVol
            //
            function obv_confGraph(interval, range)
            {
                $('#obv_myInterval').val(interval);
                $('#obv_myRange').val(range);

                obv_selectRange();
                obv_selectIntervals();

                ajaxOrderBookVol();
            }


            //
            // Selection des Intervals de temps de l'orderBookVol
            //
            function obv_selectIntervals()
            {
                var linkIntervals = {
                    60   : '1min',
                    120  : '2min',
                    180  : '3min',
                    240  : '4min',
                    300  : '5min',
                    600  : '10min',
                    900  : '15min',
                    1800 : '30min',
                    3600 : '1h',
                };

                if ($('#obv_btnInterval').length == 0) {

                    $('#obv_toolsOrderBookVol').append('<div id="obv_btnInterval" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="obv_interval"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#obv_btnInterval').append(btnSelect);
                    $('#obv_btnInterval').append('<ul id="obv_optionsInterval" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkIntervals) {
                    if ($('#obv_myInterval').val() == i) {
                        var affInterval = 'Interval : <span class="btnToolsOn">' + linkIntervals[i] + '</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkIntervals[i] + '</span></a></li>';
                    } else {
                        var jsFunction = 'obv_confGraph(' + i + ', $(\'#obv_myRange\').val());';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkIntervals[i] + '</a></li>';
                    }
                }

                $('#obv_optionsInterval').html(options);
                $('#obv_interval').html(affInterval);
            }


            //
            // Sélection du range à étudier de l'orderBook
            //
            function obv_selectRange()
            {
                var linkRange = new Array(0.2, 0.4, 0.6, 0.8, 1, 1.5, 2, 3, 4, 5, 10, 25, 50, 75, 100);

                if ($('#obv_btnRange').length == 0) {

                    $('#obv_toolsOrderBookVol').append('<div id="obv_btnRange" class="btn-group" role="group"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">';
                    btnSelect    +=    '<span id="obv_rangeLib"></span> <span class="caret"></span>';
                    btnSelect    += '</button>';

                    $('#obv_btnRange').append(btnSelect);
                    $('#obv_btnRange').append('<ul id="obv_optionsRange" class="dropdown-menu"></ul>');
                }

                var options = '';

                for (var i in linkRange) {
                    if ($('#obv_myRange').val() == linkRange[i]) {
                        var affRange = 'Range : <span class="btnToolsOn">' + linkRange[i] + '%</span>';
                        options += '<li><a><span class="btnToolsOn">' + linkRange[i] + ' %</span></a></li>';
                    } else {
                        var jsFunction = 'obv_confGraph($(\'#obv_myInterval\').val(), \'' + linkRange[i] + '\');';
                        options += '<li><a href="javascript:' + jsFunction + '">' + linkRange[i] + ' %</a></li>';
                    }
                }

                $('#obv_optionsRange').html(options);
                $('#obv_rangeLib').html(affRange);
            }


            //
            // Selection de la fonction play / pause
            //
            function obv_selectPlayGraph()
            {
                if ( $('#obv_btnPlayGraph').length == 0 ) {

                    $('#obv_toolsOrderBookVol').append('<div id="obv_btnPlayGraph" class="btn-group" role="group" title="Chart pause"></div>');

                    var btnSelect = '<button type="button" class="btn btn-default" aria-haspopup="true" aria-expanded="false" onclick="obv_selectPlayGraphClick();">';
                    btnSelect    +=    '<span id="obv_playGraphLib"><i class="fa fa-pause greyBold"></i></span>';
                    btnSelect    += '</button>';

                    $('#obv_btnPlayGraph').html(btnSelect);
                }
            }

            function obv_selectPlayGraphClick()
            {
                if ($('#obv_playGraph').val() == 1) {
                    $('#obv_playGraphLib').html('<i class="fa fa-play colorBold2"></i>');
                    $('#obv_playGraph').val(0);
                    $('#obv_btnPlayGraph').attr('data-original-title', 'Chart play');
                } else {
                    $('#obv_playGraphLib').html('<i class="fa fa-pause greyBold"></i>');
                    $('#obv_playGraph').val(1);
                    $('#obv_btnPlayGraph').attr('data-original-title', 'Chart pause');

                    obv_confGraph( $('#obv_myInterval').val(), $('#obv_myRange').val() );
                }

                $('#obv_btnPlayGraph button').blur();
            }


            //
            // Appel Ajax pour l'affichage de l'orderBookVol
            //
            function ajaxOrderBookVol()
            {
                $.post("/app/ajax/ajaxGraphOrderBookVol.php",
                {
                    exchange    : $('#exchange').val(),
                    marketName  : $('#marketName').val(),
                    timeZone    : getTimezoneName(),
                    interval    : $('#obv_myInterval').val(),
                    range       : $('#obv_myRange').val()
                },
                function success(data)
                {
                    //console.log(data);

                    //var title = 'Evolution de l\'orderBook par intervalle et par range | ' + data.exchange + ', ' + $('#marketName').val();
                    //$('#obv_title').html(title);

                    obv_affHighcharts(data)

                }, 'json');
            }


            //
            // Mise à jour de l'orderBookVol toutes les 10 secondes
            //
            setInterval(function() {
                if ($('#obv_playGraph').val() == 1 && $('#leftTabs_id0').attr('class') == 'active' && $('#chartsTabs_id2').attr('class') == 'active' && $(document).scrollTop().valueOf() < 50) {
                    ajaxOrderBookVol();
                }
            }, 3000);


            //
            // Mise à jour au click sur l'onglet
            //
            function majOrderBookVol()
            {
                obv_selectRange();      // Affichage du selecteur de range
                obv_selectIntervals();  // Affichage du selecteur d'intervals
                obv_selectPlayGraph();  // Selection de la fonction playGraph

                ajaxOrderBookVol();     // Actualisation de l'orderBookVol
            }

            $('#chartsTabs_id').on('click', '#chartsTabs_id2', function() {
                majOrderBookVol();
            });

            $('#leftTabs_id').on('click', '#leftTabs_id0', function() {
                if ( $('#chartsTabs_id2').attr('class') == 'active' ) {
                    majOrderBookVol();
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
