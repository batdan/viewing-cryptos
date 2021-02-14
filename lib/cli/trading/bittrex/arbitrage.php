<?php
namespace cryptos\cli\trading\bittrex;

/**
 * Trading multi-devises
 * Utilise les temps de latence des différents cours
 *
 * API Bittrex documentation :
 * https://bittrex.com/home/api
 *
 * @author Daniel Gomes | Pierre-Yves Minier
 */
class arbitrage
{
    /**
	 * Attributs
	 */
    private $_exchange; // place de marché (bittrex, poloniex, bitfinex, ...)

    private $_marketSummaries;
    private $_tradePossibilities;

    private $_addBidPct = 0.01;     // Pourcentage ajouté au Bid le plus haut
    private $_addAskPct = 0.01;     // Pourcentage retiré au Ask le plus bas

    private $_strategies_BTC; // Tableau des strategies pour un gain en BTC
    private $_results;
    private $_results_details;
    private $_fees;

    /**
	 * Constructeur
	 */
	public function __construct()
	{
        $this->_results = array();
        $this->_results_details = array();

        $this->_exchange = 'bittrex';

        $this->_fees = array(
            'bittrex'   => array('buy' => 0.25, 'sell' => 0.25),
            'poloniex'  => array('buy' => 0.25, 'sell' => 0.15),
        );

        $this->_strategies_BTC = array(
            'BTC_XXX_ETH_BTC',
            'BTC_XXX_USDT_BTC',
            'BTC_ETH_XXX_BTC',
            'BTC_USDT_XXX_BTC',

            'BTC_XXX_ETH_USDT_BTC',
            'BTC_XXX_USDT_ETH_BTC',
            'BTC_ETH_XXX_USDT_BTC',
            'BTC_USDT_XXX_ETH_BTC',
            'BTC_ETH_USDT_XXX_BTC',
            'BTC_USDT_ETH_XXX_BTC',

            'BTC_XXX_ETH_YYY_BTC',
            'BTC_XXX_USDT_YYY_BTC',
            'BTC_YYY_ETH_XXX_BTC',
            'BTC_YYY_USDT_XXX_BTC',

            'BTC_USDT_XXX_ETH_YYY_BTC',
            'BTC_ETH_XXX_USDT_YYY_BTC',
            'BTC_USDT_YYY_ETH_XXX_BTC',
            'BTC_ETH_YYY_USDT_XXX_BTC',

            'BTC_XXX_ETH_YYY_USDT_BTC',
            'BTC_XXX_USDT_YYY_ETH_BTC',
            'BTC_YYY_ETH_XXX_USDT_BTC',
            'BTC_YYY_USDT_XXX_ETH_BTC',
        );
    }

    public function analyse()
    {
        // Récupération de marketSummaries
        $this->getMarketSummaries();

        /**
         * test de base avec exclusion des stratégies USDT
         */
        //$this->strategies_check('BTC', 1, 'ETC', 'XRP', 'USDT');

        /**
         * test en boucle
         */
        $last_crypto = '';
        foreach ($this->_marketSummaries as $key => $val) {
            $crypto = explode('-', $key);

            /**
             * test sur les monnaies tradées en USDT
             */
            // if ($crypto[0] == 'USDT' and $crypto[1] != 'BTC' and $crypto[1] != 'ETH') {
            //     echo '<h3>'.$crypto[1].'</h3>';
            //     echo '<br>YYY = '.$last_crypto;
            //
            //     $this->strategies_check('BTC', 1, $crypto[1], $last_crypto, '');
            //     $last_crypto = $crypto[1];
            // }

            /**
             * test sur les monnaies tradées en ETH avec exclusion des stratégies USDT
             */
            if ($crypto[0] == 'ETH' and $crypto[1] != '1ST') { // echappement de la monnaie 1ST car bug dans fonction strategies_check
                // echo '<h3>'.$crypto[1].'</h3>';
                // echo '<br>YYY = '.$last_crypto;

                $this->strategies_check('BTC', 1, $crypto[1], $last_crypto, 'USDT');
                $last_crypto = $crypto[1];
            }
        }
        echo '<pre>';
        echo '<h3>'.count($this->_results).' stratégies gagnantes sur '.count($this->_results_details).'</h3>';
        arsort($this->_results);
        print_r($this->_results);
        echo '</pre>';
    }


    /**
     *
     */
    private function getMarketSummaries()
    {
        $getMarket = new \cryptos\api\bittrex\getMarket();
        $marketSummaries = $getMarket->getMarketSummaries();

        $this->_marketSummaries = array();
        foreach ($marketSummaries as $key => $val) {

            $this->_marketSummaries[$val->MarketName] = array(
                'High'          => $val->High,
                'Low'           => $val->Low,
                'Volume'        => $val->Volume,
                'Last'          => $val->Last,
                'BaseVolume'    => $val->BaseVolume,
                'TimeStamp'     => $val->TimeStamp,
                'Bid'           => $val->Bid,
                'Ask'           => $val->Ask,
                'OpenBuyOrders' => $val->OpenBuyOrders,
                'OpenSellOrders'=> $val->OpenSellOrders,
                'PrevDay'       => $val->PrevDay,
                'Created'       => $val->Created,
            );
        }
    }

    /**
     * [check_couple_bidask description]
     * @param  [type] $cur1       [description]
     * @param  [type] $cur2       [description]
     * @param  [type] $cur1_units [description]
     * @return [type]             [description]
     */
    private function check_couple_bidask($cur1, $cur2, $cur1_units)
    {
        $r = array();
        if ($cur1=='USDT')
        {
            $r['direction']     = $cur1.' -> '.$cur2;
            $r['couple']        = 'USDT-'.$cur2;
            $r['check_bid_ask'] = 'Bid'; // on regarde dans les ventes en attente
            $r['best_bid_ask']  = $this->_marketSummaries[$r['couple']][$r['check_bid_ask']];
            $r['bid_ask']       = $this->bidask_top($r['check_bid_ask'], $r['best_bid_ask']);
            $r['units_total']   = round($cur1_units / $r['bid_ask'], 8);
            $r['fees']          = round($r['units_total'] * $this->_fees[$this->_exchange]['buy']/100, 8);
            $r['units']         = round($r['units_total'] - $r['fees'], 8);
        }
        elseif ($cur2=='USDT')
        {
            $r['direction']     = $cur1.' -> '.$cur2;
            $r['couple']        = 'USDT-'.$cur1;
            $r['check_bid_ask'] = 'Ask';
            $r['best_bid_ask']  = $this->_marketSummaries[$r['couple']][$r['check_bid_ask']];
            $r['bid_ask']       = $this->bidask_top($r['check_bid_ask'], $r['best_bid_ask']);
            $r['units_total']   = round($cur1_units * $r['bid_ask'], 8);
            $r['fees']          = round($r['units_total'] * $this->_fees[$this->_exchange]['sell']/100, 8);
            $r['units']         = round($r['units_total'] - $r['fees'], 8);
        }
        elseif ($cur1=='BTC')
        {
            $r['direction']     = $cur1.' -> '.$cur2;
            $r['couple']        = 'BTC-'.$cur2;
            $r['check_bid_ask'] = 'Bid';
            $r['best_bid_ask']  = $this->_marketSummaries[$r['couple']][$r['check_bid_ask']];
            $r['bid_ask']       = $this->bidask_top($r['check_bid_ask'], $r['best_bid_ask']);
            $r['units_total']   = round($cur1_units / $r['bid_ask'], 8);
            $r['fees']          = round($r['units_total'] * $this->_fees[$this->_exchange]['buy']/100, 8);
            $r['units']         = round($r['units_total'] - $r['fees'], 8);
        }
        elseif ($cur2=='BTC')
        {
            $r['direction']     = $cur1.' -> '.$cur2;
            $r['couple']        = 'BTC-'.$cur1;
            $r['check_bid_ask'] = 'Ask';
            $r['best_bid_ask']  = $this->_marketSummaries[$r['couple']][$r['check_bid_ask']];
            $r['bid_ask']       = $this->bidask_top($r['check_bid_ask'], $r['best_bid_ask']);
            $r['units_total']   = round($cur1_units * $r['bid_ask'], 8);
            $r['fees']          = round($r['units_total'] * $this->_fees[$this->_exchange]['sell']/100, 8);
            $r['units']         = round($r['units_total'] - $r['fees'], 8);
        }
        elseif ($cur1=='ETH')
        {
            $r['direction']     = $cur1.' -> '.$cur2;
            $r['couple']        = 'ETH-'.$cur2;
            $r['check_bid_ask'] = 'Bid';
            $r['best_bid_ask']  = $this->_marketSummaries[$r['couple']][$r['check_bid_ask']];
            $r['bid_ask']       = $this->bidask_top($r['check_bid_ask'], $r['best_bid_ask']);
            $r['units_total']   = round($cur1_units / $r['bid_ask'], 8);
            $r['fees']          = round($r['units_total'] * $this->_fees[$this->_exchange]['buy']/100, 8);
            $r['units']         = round($r['units_total'] - $r['fees'], 8);
        }
        elseif ($cur2=='ETH')
        {
            $r['direction']     = $cur1.' -> '.$cur2;
            $r['couple']        = 'ETH-'.$cur1;
            $r['check_bid_ask'] = 'Ask';
            $r['best_bid_ask']  = $this->_marketSummaries[$r['couple']][$r['check_bid_ask']];
            $r['bid_ask']       = $this->bidask_top($r['check_bid_ask'], $r['best_bid_ask']);
            $r['units_total']   = round($cur1_units * $r['bid_ask'], 8);
            $r['fees']          = round($r['units_total'] * $this->_fees[$this->_exchange]['sell']/100, 8);
            $r['units']         = round($r['units_total'] - $r['fees'], 8);
        }

        // echo '<pre>';
        // print_r($r);
        // echo '</pre>';

        return $r;
    }


    /**
     * [strategies_check description]
     * @param  string   $trade_base         monnaie de référence (BTC, ETH ou USDT)
     * @param  floatval $trade_units        nombre d'unités de la monnaie de référence misés
     * @param  string   $xxx                monnaie intermédiaire 1 (doit apparaitre dans les monnaies tradées avec la référence)
     * @param  string   $yyy                monnaie intermédiaire 2 (optionnelle, doit apparaitre dans les monnaies tradées avec toutes les références)
     * @param  string   $exclude_usdt_eth   exclusion optionnelle d'une monnaie de référence
     * @return array                        tableau des résultats triés par gains décroissants
     */
    private function strategies_check($trade_base, $trade_units, $xxx, $yyy='', $exclude_usdt_eth='')
    {
        $strategies = array();
        /**
         * Remplacement des monnaies XXX et YYY
         *
         * == a corriger bug sur remplacement XXX avec monnaies alphanum comme 1ST
         */
        foreach ($this->_strategies_BTC as $key => $strat)
        {
            if (preg_match('/YYY/',$strat) and empty($yyy))
            {
                unset($this->_strategies_BTC[$key]);
            }
            elseif (!empty($exclude_usdt_eth) and preg_match('/'.$exclude_usdt_eth.'/',$strat))
            {
                unset($this->_strategies_BTC[$key]);
            }
            else
            {
                $strat = preg_replace('/([0-9A-Z_]*)(XXX)([0-9A-Z_]*)/', '$1'.$xxx.'$3', $strat);
                $strat = preg_replace('/([0-9A-Z_]*)(YYY)([0-9A-Z_]*)/', '$1'.$yyy.'$3', $strat);
                $strategies[$key] = $strat;
            }
        }

        // echo '<pre>';
        // echo '<br>XXX = '.$xxx;
        // echo '<br>YYY = '.$yyy.'<br>';
        // print_r($strategies);
        // echo '</pre>';

        /**
         * Découpage et test des stratégies BTC
         * => à améliorer avec meilleur rapport prix/volume avec fonctions asksTop/bidsTop
         * priorités de positionnement bittrex : USDT > BTC > ETH (intégrés dans la fonction check_couple_bidask)
         */
        $recap = '';
        foreach ($strategies as $key => $strat)
        {
            $strat = explode('_',$strat);
            $frais = 0;
            $etapes = array();

            /**
             * Etape 1 - Conversion base vers cible 1 de la stratégie en trouvant le ask le plus bas ou bid le plus haut
             */
            $etapes[1] = $this->check_couple_bidask($strat[0], $strat[1], $trade_units);

            /**
             * Etape 2 - Revente de la monnaie 1 à celle de l'étape 2
             */
            $etapes[2] = $this->check_couple_bidask($strat[1], $strat[2], $etapes[1]['units']);

            /**
             * Etape 3 - Retour sur BTC, passage à XXX, ou passage à ETH/USDT pour continuer sur YYY
             */
            $etapes[3] = $this->check_couple_bidask($strat[2], $strat[3], $etapes[2]['units']);
            $units_end = $etapes[3]['units'];

            /**
             * Etape 4 - optionnelle
             */
            if (!empty($strat[4]))
            {
                $etapes[4] = $this->check_couple_bidask($strat[3], $strat[4], $etapes[3]['units']);
                $units_end = $etapes[4]['units'];
            }
            /**
             * Etape 5 - optionnelle
             */
            if (!empty($strat[5]))
            {
                $etapes[5] = $this->check_couple_bidask($strat[4], $strat[5], $etapes[4]['units']);
                $units_end = $etapes[5]['units'];
            }

            $profit_loss = $units_end - $trade_units;
            $profit_loss_pct = $profit_loss / $trade_units * 100;
            $color = $profit_loss>0?'green':'red';

            $this->_results_details[$strategies[$key]]['etapes'] = $etapes;
            $this->_results_details[$strategies[$key]]['profit_loss'] = $profit_loss;
            $this->_results_details[$strategies[$key]]['profit_loss_pct'] = $profit_loss_pct;

            if ($profit_loss>0)
            {
                $this->_results[$strategies[$key]] = $profit_loss_pct;
            }

            $recap .= '<tr style="color:'.$color.'; font_weight:bold;"><th>'.$strategies[$key].'</th><td>'.$profit_loss.'</td><td>'.round($profit_loss_pct,3).'%</td></tr>';
        }
        //echo '<table>'.$recap.'</table>';
    }


    /**
     * Achat (bids) en ajoutant un peu pour être au plus haut / sûr d'être pris
     *
     * == A améliorer pour répondre au meilleur rapport volume/prix
     */
    public function bidask_top($direction, $bidask)
    {
        // On retire X% au Bid le plus haut
        return $direction=='Ask' ? $bidask + $bidask * $this->_addAskPct/100 : $bidask + $bidask * $this->_addBidPct/100;
    }

} // end class
