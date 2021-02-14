<?php
namespace cryptos\cli\other\bittrex;

/**
 * Analyse d'un orderBook pour un affichage en interface CLI
 *
 * API Bittrex documentation :
 * https://bittrex.com/home/api
 *
 * @author Daniel Gomes
 */
class orderBookCLI
{
	/**
     * Extrapolation des données d'un order book
     *
     * @param       string      $marketName         Nom du market - ex : BTC-LTC
     * @param       string      $type               OrderBook souhaité : both|bid|ask
     *
     * @return      array
     *
     */
	private static function orderBookAnalysed($marketName, $type='both')
	{
		$orderBook = \cryptos\api\bittrex\getOrderBook::getOrderBook($marketName, $type);

		$buy  = array();
		$sell = array();

		foreach ($orderBook->buy as $value) {
		$buy["$value->Rate"] = $value->Quantity;
		}

		foreach ($orderBook->sell as $value) {
		$sell["$value->Rate"] = $value->Quantity;
		}

		$res = array(
			'buy' => $buy,
			'sell' => $sell,
		);

		return $res;
	}


    /**
     * Création des ranges pour repérer les moments d'achat et de vente
     *
     * @param       string      $marketName         Nom du market - ex : BTC-LTC
     * @param       string      $type               OrderBook souhaité : both|bid|ask
     *
     * @return      array
     *
     */
	public static function orderBookPrediction($marketName, $type='both')
    {
        // Ranges en dessous et au dessus de la valeur moyenne (middle) en %
        $rangePct = array(0.4, 0.5, 0.6, 0.7, 0.8, 0.9);

        // Récupération des données
		$orderBook = self::orderBookAnalysed($marketName, $type);

		// Récupération du cours actuel - extrapolation de la valeur max des achats et min des ventes
		$lastMoy = (max(array_keys($orderBook['buy'])) + min(array_keys($orderBook['sell']))) / 2;

		$getMarket = new \cryptos\bittrex\market\getMarket;

		$getMarketSummary = $getMarket->getTicker($marketName);
		$last = $getMarketSummary->Last;

		$getMarketSummary = $getMarket->getTicker('USDT-BTC');
		$lastBTC = $getMarketSummary->Last;

		//$last = $lastMoy;

		// Calcul volumes par range des achats et des ventes
		$volBuyRange  = array();
		$volSellRange = array();

		for ($i=0; $i<count($rangePct); $i++) {
			$volBuyRange[]  = 0;
			$volSellRange[] = 0;
		}

        foreach ($orderBook['buy'] as $k => $v) {

            // Volume achats range
            for ($i=0; $i<count($rangePct); $i++) {
                if ($k > ($last * (1 - ($rangePct[$i] / 100)))) {
                    $volBuyRange[$i] += $v;
                }
            }

            // Suppression des ordres d'achats sortant du 5ème range
            if ($k < ($last * (1 - ($rangePct[4] / 100)))) {
                if (isset($buy[$k])) {
                    unset($buy[$k]);
                }
            }
        }

        foreach ($orderBook['sell'] as $k => $v) {

    		// Volume ventes range
    		for ($i=0; $i<count($rangePct); $i++) {
    			if ($k < ($last * (1 + ($rangePct[$i] / 100)))) {
    				$volSellRange[$i] += $v;
    			}
    		}

    		// Suppression des ordres de ventes sortant du 5ème range
    		if ($k > ($last * (1 + ($rangePct[4] / 100)))) {
				if (isset($sell[$k])) {
					unset($sell[$k]);
				}
			}
		}

		$pctBuyRange  	= array();
		$pctSellRange 	= array();
		$res 			= array();

		for ($i=0; $i<count($rangePct); $i++) {

			// Valeurs en pourcentage
			if (($volBuyRange[$i] + $volSellRange[$i]) > 0) {
				$pctBuyRange[$i]  = $volBuyRange[$i]  * (100 / ($volBuyRange[$i] + $volSellRange[$i]));
			} else {
				$pctBuyRange[$i]  = 0;
			}
			$pctBuyRange[$i]  = number_format($pctBuyRange[$i], 2, '.', '');
			$pctBuyRange[$i]  = str_pad($pctBuyRange[$i], 5, "0", STR_PAD_LEFT);

			if (($volBuyRange[$i] + $volSellRange[$i]) > 0) {
				$pctSellRange[$i] = $volSellRange[$i] * (100 / ($volBuyRange[$i] + $volSellRange[$i]));
			} else {
				$pctSellRange[$i] = 0;
			}

			$pctSellRange[$i] = number_format($pctSellRange[$i], 2, '.', '');
			$pctSellRange[$i] = str_pad($pctSellRange[$i], 5, "0", STR_PAD_LEFT);

			$res[$i] = array(
				'lastBTC'	=> $lastBTC,
				'lastMoy'	=> $lastMoy,
				'last'		=> $last,
				'pct'		=> $rangePct[$i],
				'buy'		=> $pctBuyRange[$i],
				'sell' 		=> $pctSellRange[$i],
				'buyVol'	=> $volBuyRange[$i],
				'sellVol'	=> $volSellRange[$i],
			);
		}

		return $res;
	}


	/**
	 * Exploitation des ranges pour afficher le résultat
     */
	public static function orderBookTextCli()
	{
		// Récupération de la place de maché à surveiller - $marketName
		if (!isset($_SERVER['argv'][1])) {
			die(chr(10) . 'Argument marketName absent' . chr(10) . chr(10));
		} else {
			$marketName = $_SERVER['argv'][1];
		}

		// Gestion des couleurs : interface PHP CLI
		$colorCli = new \core\cliColorText();

		for ($i=0; $i==$i; $i++) {

		    $vueCLi = self::orderBookPrediction($marketName);

			// Récupération de la date et heure
		    $date = new \DateTime();
		    $date = $date->format('Y-m-d H:i:s');
		    $date = $colorCli->getColor($date, $foreground_color='light_blue', $background_color=null);

			// Couleur des séparateurs
		    $sep  = $colorCli->getColor(' | ', $foreground_color='red', $background_color=null);

			// Couple de devises
			echo $colorCli->getColor($marketName, $foreground_color='white', $background_color=null) . $sep;

			// Date et heure
			echo $date . $sep;

			$lastBTC[$i] = number_format(round($vueCLi[0]['lastBTC'], 0), 0, '.', '');
			$last[$i] = number_format($vueCLi[0]['last'], 8, '.', '');

			// Bouble pour afficher les Achats/Ventes en fonction des ranges définis
		    for ($j=0; $j<count($vueCLi); $j++) {

				// Last : valeur (réelle) du cours à la dernière opération
				if ($j==0) {
					if ($i>1 && $lastBTC[$i] > $lastBTC[$i-1]) {
						echo $colorCli->getColor($lastBTC[$i], $foreground_color='light_green', $background_color=null) . $sep;
					} elseif ($i>1 && $lastBTC[$i] < $lastBTC[$i-1]) {
						echo $colorCli->getColor($lastBTC[$i], $foreground_color='light_red', $background_color=null) . $sep;
					} else {
						echo $colorCli->getColor($lastBTC[$i], $foreground_color='light_gray', $background_color=null) . $sep;
					}


					if ($i>1 && $last[$i] > $last[$i-1]) {
						echo $colorCli->getColor($last[$i], $foreground_color='light_green', $background_color=null) . $sep;
					} elseif ($i>1 && $last[$i] < $last[$i-1]) {
						echo $colorCli->getColor($last[$i], $foreground_color='light_red', $background_color=null) . $sep;
					} else {
						echo $colorCli->getColor($last[$i], $foreground_color='light_gray', $background_color=null) . $sep;
					}
				}

				// Pourcentage du range
				if ($vueCLi[$j]['buy'] >= 40 && $vueCLi[$j]['buy' ] <= 60) {

					echo $colorCli->getColor($vueCLi[$j]['pct'] . '% : ', $foreground_color='light_gray', $background_color=null);
					echo $colorCli->getColor($vueCLi[$j]['buy'], $foreground_color='light_gray', $background_color=null);
					echo $colorCli->getColor(' / ', $foreground_color='light_gray', $background_color=null);
					echo $colorCli->getColor($vueCLi[$j]['sell'], $foreground_color='light_gray', $background_color=null);

				} elseif ($vueCLi[$j]['buy'] > $vueCLi[$j]['sell']) {

					echo $colorCli->getColor($vueCLi[$j]['pct'] . '% : ', $foreground_color='light_green', $background_color=null);

					if ($vueCLi[$j]['buy'] >= 85) {
						echo $colorCli->getColor($vueCLi[$j]['buy'], $foreground_color='white', $background_color='green');
					} else {
						echo $colorCli->getColor($vueCLi[$j]['buy'], $foreground_color='light_green', $background_color=null);
					}

					echo $colorCli->getColor(' / ', $foreground_color='light_green', $background_color=null);
					echo $colorCli->getColor($vueCLi[$j]['sell'], $foreground_color='light_green', $background_color=null);

				} elseif ($vueCLi[$j]['buy'] < $vueCLi[$j]['sell']) {

					echo $colorCli->getColor($vueCLi[$j]['pct'] . '% : ', $foreground_color='light_red', $background_color=null);
					echo $colorCli->getColor($vueCLi[$j]['buy'], $foreground_color='light_red', $background_color=null);
					echo $colorCli->getColor(' / ', $foreground_color='light_red', $background_color=null);

					if ($vueCLi[$j]['sell'] >= 85) {
						echo $colorCli->getColor($vueCLi[$j]['sell'], $foreground_color='white', $background_color='red');
					} else {
						echo $colorCli->getColor($vueCLi[$j]['sell'], $foreground_color='light_red', $background_color=null);
					}
				}

				// Séparation fin range
		        echo $sep;
		    }

		    echo chr(10);

		    sleep(3);
		}
	}
}
