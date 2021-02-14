<?php
namespace cryptos\scanners;

/**
 * Gère toutes les actions nécessaires pour réaliser la sélection d'un market
 *
 * @author Daniel Gomes
 */
class selectMarket
{
    /**
     * Préparation des informations nécessaire à la sélection d'un exchange et d'un market
     *
     * @param       string      $exchange       Nom de la place de marché
     * @param       string      $marketName     Paire de devises du marché
     */
    public static function selectMarket($exchange, $marketName)
    {
        $dbh = \core\dbSingleton::getInstance('cryptos_marketCap');

        // Récupération du profil utilisateur
        $infosUser = \cryptos\generiques\infosUser::getInfos($_SESSION['auth']['id']);

        // Onglet 0 : candleStick Tradingview
        $tdv_studies = array();
        if (! empty($infosUser['settings_charts']['tdv_studies1'])) {
        	$tdv_studies[] = $infosUser['settings_charts']['tdv_studies1'];
        }
        if (! empty($infosUser['settings_charts']['tdv_studies2']) && ! in_array($infosUser['settings_charts']['tdv_studies2'], $tdv_studies)) {
        	$tdv_studies[] = $infosUser['settings_charts']['tdv_studies2'];
        }
        if (! empty($infosUser['settings_charts']['tdv_studies3']) && ! in_array($infosUser['settings_charts']['tdv_studies3'], $tdv_studies)) {
        	$tdv_studies[] = $infosUser['settings_charts']['tdv_studies3'];
        }

        $tdv_interval = $infosUser['settings_charts']['tdv_interval'];

        $chartTradinView = \cryptos\graph\graphTradingView::configurator($exchange, $marketName, 'iframe', $tdv_studies, $tdv_interval);

        // Récupération des titres du marketCap pour allMarkets et globalData
        $expMarket = explode('-', $marketName);

        $cryptoTde = $expMarket[1];
        $cryptoTde = \cryptos\graph\graphOrderBook::genericName($exchange, $cryptoTde);

        $req = "SELECT name FROM devises_marketCap WHERE symbol = :symbol";
        $sql = $dbh->prepare($req);
        $sql->execute(array( ':symbol' => $cryptoTde ));
        if ($sql->rowCount() > 0) {
            $res = $sql->fetch();
            $cryptoName = $res->name;
        } else {
            $cryptoName = $cryptoTde;
        }

        $tableTitleAllMarkets = $cryptoName . ' markets';
        $tableTitleGlobalData = 'Global data for ' . $cryptoName;

        return array(
            'exchange'          => $exchange,
            'marketName'        => $marketName,
            'cryptoName'        => $cryptoName,

            'tradingView'       => $chartTradinView,

            'thisMarketTitle'   => $marketName . ' markets',
            'allMarketsTitle'   => $tableTitleAllMarkets,
            'globalDataTitle'   => $tableTitleGlobalData,
        );
    }


    /**
     * Code Javascript nécessaire à la sélection d'un exchange et d'un market
     */
    public static function jsSelectMarket()
    {
        $js = <<<eof
            //
            // Appel Ajax pour l'affichage d'un market et d'un exchange dans la partie de gauche
            //
            function ajaxAffMarket(newExchange, newMarketName)
            {
                $.post("/app/ajax/ajaxSelectMarket.php",
                {
                    exchange    : newExchange,
                    marketName  : newMarketName,
                },
                function success(data)
                {
                    // console.log(data);

                    $('title').html( data.marketName + ' | ' + capitalize(data.exchange) + ' | Cryptoview' );

                    // Nouvel exchange, marketName et cryptoName dans les input hidden
                    $('#exchange').val(data.exchange);
                    $('#marketName').val(data.marketName);
                    $('#cryptoName').val(data.cryptoName);

                    infosNavbar();

                    // Infos Currency navbar
                    $('#navExchange').html( capitalize(data.exchange) );
                    $('#navPair').html( data.marketName );

                    // Refresh du graph orderBook
                    $('#ob_myCrypto').val('');
                    $('#ob_toolsOrderBook').empty();
                    if ( $('#leftTabs_id0').attr('class') == 'active' && $('#chartsTabs_id1').attr('class') == 'active' ) {
                        majOrderBook();
                    }

                    // Refresh du graph orderBookVol
                    $('#obv_toolsOrderBookVol').empty();
                    $('#ob_orderBookTM').val(0);
                    $('#ob_compareEx').val(0);
                    if ( $('#leftTabs_id0').attr('class') == 'active' && $('#chartsTabs_id2').attr('class') == 'active' ) {
                        majOrderBookVol();
                    }

                    // Refresh du graph marketHistory
                    $('#mh_myCrypto').val('');
                    $('#mh_toolsMarketHistory').empty();
                    if ( $('#leftTabs_id0').attr('class') == 'active' && $('#chartsTabs_id3').attr('class') == 'active' ) {
                        majMarketHistory();
                    }

                    // Refresh du graph tradingView
                    $('#chartsTabs_tab0').empty();
                    $('#chartsTabs_tab0').append(data.tradingView);
                    var heightChartTDV = $(window).height() - 200;
                    $('#tdv_chart').attr('height', heightChartTDV);

                    // Refresh du marketCap : thisMarket
                    if ( $('#leftTabs_id1').attr('class') == 'active' && $('#marketCapTabs_id0').attr('class') == 'active' ) {
                        majMarketCapThisMarket();
                    }

                    // Refresh du marketCap : allMarkets
                    if ( $('#leftTabs_id1').attr('class') == 'active' && $('#marketCapTabs_id1').attr('class') == 'active' ) {
                        majMarketCapAllMarkets();
                    }

                    // Refresh du marketCap : globalData
                    if ( $('#leftTabs_id1').attr('class') == 'active' && $('#marketCapTabs_id2').attr('class') == 'active' ) {
                        majMarketCapGlobalData();
                    }

                    // Suivi Piwik
                    _paq.push(['setDocumentTitle', data.marketName + ' | ' + capitalize(data.exchange)]);
                    _paq.push(['trackPageView']);

                }, 'json');
            }
eof;

        return $js;
    }
}
