<?php
namespace cryptos\graph;

/**
 * Lien vers les graphique de tradingView
 *
 * @author Daniel Gomes
 */
class graphTradingView
{
    /**
     * Création des groupes de range
     */
    public static function configurator($exchange, $marketName, $type='link', $studies=array(), $interval=1, $height=530, $idChart='tdv_chart', $btnPopup=true)
    {
        $expMarket = explode ('-', $marketName);

        if ($exchange == 'gdax') {
            $exchange = 'coinbase';
        }

        // Url du graphique tradingView
        $tradingViewUrl = 'https://s.tradingview.com/widgetembed/';

        $studies  = implode('_____', $studies);
        $exchange = strtoupper($exchange);

        $tradingViewGetParams = array(
            'symbol'            => $exchange . ':___market___',
            'interval'          => $interval,
            'hidesidetoolbar'   => 0,
            'symboledit'        => 1,
            'saveimage'         => 1,
            'toolbarbg'         => 'f4f7f9',
            'studies'           => $studies,
            'hideideas'         => 1,
            'theme'             => 'Dark',
            //'toolbarbg'         => 'rgba(0,0,0,0.5)',
            'padding'           => 0,
            'style'             => 1,
            //'timezone'          => 'Europe/Paris',
            'withdateranges'    => 1,
            //'showpopupbutton'   => 1,
            'overrides'         => '{}',
            'enabled_features'  => '[]',
            'disabled_features' => '[]',
            'locale'            => 'en',
            //'utm_source'        => 'bittrex.com',
            'utm_medium'        => 'widget',
            'utm_campaign'      => 'chart',
            'utm_term'          => $exchange . ':___market___',
        );

        $tradingViewGet = array();

        foreach ($tradingViewGetParams as $key => $val) {
            $tradingViewGet[] = $key . '=' . urlencode($val);
        }

        $tradingViewGet = implode('&', $tradingViewGet);
        $tradingViewGet = str_replace('_____', '%1F', $tradingViewGet);

        $tradingViewGraph   = $tradingViewUrl . '?' . $tradingViewGet;

        $tradingViewGraph   = str_replace('___market___', $expMarket[1] . $expMarket[0], $tradingViewGraph);

        if ($type == 'link') {
            return '<a href="' . $tradingViewGraph . '"  target="_blank">Graph ' . $marketName .'</a>';
        }

        if ($type == 'iframe') {

            $btnPopupRendu = '';
            if ($btnPopup) {
                $styleBtnPopup = "width:30px; height:30px; border:0px solid red; position:absolute; margin-left:calc(100% - 93px); margin-top:10px; cursor:pointer;";
                $onclickPopup  = "window.open('$tradingViewGraph', '', 'menubar=no, status=no, scrollbars=no, menubar=no, width=' + $(window).width() + ', height=' + $(window).height() );";
                $btnPopupRendu = '<div style="' . $styleBtnPopup . '" onclick="' . $onclickPopup . '"></div>';
            }

            $iframe = <<<eof
                        $btnPopupRendu
                        <iframe id="$idChart"
                                width="100%"
                                height="$height"
                                frameborder="0"
                                scrolling="no"
                                marginheight="0"
                                marginwidth="0"
                                style="margin-top:0px;"
                                src="$tradingViewGraph&showpopupbutton=1">
                        </iframe>
eof;
            return $iframe;
        }
    }
}
