<?php
namespace cryptos\trading;

/**
 * Functions de calcul liées au trading : Calcul de la Stochastic RSI
 *
 * @author Daniel Gomes
 */
class stochRsi
{
    /**
     * Calcul de la Stochastic RSI
     *
     * @param       string      $bddName        Nom de la base de données
     * @param       string      $bddTable       Nom de la table
     * @param       string      $timeUnit       Unité de temps du graphique à analyser : MINUTE|HOUR|DAY
     * @param       string      $delta          Nombre d'unités de temps
     * @param       integer     $nbCandles      Nombre de périodes pour le calcul de la RSI (bougies)
     * @param       integer     $nbRes          Nombre de cycles analysés
     * @param       string      $dataSetType    Calcul des clôtures sur un tableau 'fixe' ou 'glissant'
     *
     * @return      array                       Résultat de la RSI (tableau de cycles entre 0% et 100%)
     */
    public static function getStochRSI($bddName, $bddTable, $timeUnit, $delta, $nbCandles=14, $nbRes=10, $dataSetType='fixe')
    {
        // Il est nécéssaire de récupérer la valeur MIN et MAX des 14 dernirère RSI (bougie actuelle incluse, d'ou le -1)
        // On ajoute car il faudra cacluler 2 fois une moyenne sur les 3 derniers cycle (bougie actuelle incluse)
        $nbResRsi = ($nbCandles - 1) + $nbRes + 4;

        $rsi = rsi::getRSI($bddName, $bddTable, $timeUnit, $delta, $nbCandles, $nbResRsi, $dataSetType);

        if (isset($rsi['rsi']) && is_array($rsi['rsi'])) {
            $rsi = $rsi['rsi'];
        } else {
            return false;
        }

        $stochRsi  = array();
        $stochRsiK = array();
        $stochRsiD = array();

        foreach ($rsi as $k=>$v) {

            if ($k < ($nbCandles - 1)) {

                continue;

            } else {

                $s_rsi = array();
                for ($i=$k; $i>($k-$nbCandles); $i--) {
                    $s_rsi[] = $rsi[$i];
                }

                $stochRsi[$k]   = 100 * ($v - min($s_rsi)) / (max($s_rsi) - min($s_rsi));

                if ($k > $nbCandles) {
                     $stochRsiK[$k] = ($stochRsi[$k] + $stochRsi[$k-1] + $stochRsi[$k-2]) / 3;
                }

                if ($k > ($nbCandles + 2)) {
                     $stochRsiD[$k] = ($stochRsiK[$k] + $stochRsiK[$k-1] + $stochRsiK[$k-2]) / 3;
                }
            }
        }

        // On supprime les 2 premières valeurs et on réinitialise les clés de $stochRsiK
        unset($stochRsiK[($nbCandles+1)]);
        unset($stochRsiK[($nbCandles+2)]);
        $stochRsiK = array_values($stochRsiK);

        // On réinitialise les clés de $stochRsiD
        $stochRsiD = array_values($stochRsiD);

        // print_r($rsi);
        // print_r($stochRsi);
        // print_r($stochRsiK);
        // print_r($stochRsiD);

        return array(
            'stochRsiK' => $stochRsiK,
            'stochRsiD' => $stochRsiD,
        );
    }
}
