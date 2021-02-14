<?php
namespace cryptos\trading;

/**
 * Functions de calcul liées au trading : Calcul de la Moyenne Mobile Exponnentielle
 *
 * @author Daniel Gomes
 */
class ema
{
    /**
     * Calcul de la EMA
     *
     * @param       string      $bddName        Nom de la base de données
     * @param       string      $bddTable       Nom de la table
     * @param       string      $timeUnit       Unité de temps du graphique à analyser : MINUTE|HOUR|DAY
     * @param       string      $interval       Nombre d'unités de temps
     * @param       integer     $nbCandlesEma   Nombre de bougies pour le calcul de la EMA 1
     * @param       integer     $nbRes          Nombre de cycles analysés
     * @param       string      $dataSetType    Calcul des clôtures sur un tableau 'fixe' ou 'glissant'
     *
     * @return      array                       Résultat de la EMA (Moyenne Mobile Exponnentielle)
     */
    public static function getEma($bddName, $bddTable, $timeUnit, $interval, $nbCandlesEma=55, $nbRes=1, $dataSetType='glissant')
    {
        // Nombre de cycles en plus nécessaire pour calculer une RSI
        $nbResPlus = $nbRes + 120;

        // Coefficient multiplicateur car les fonctions 'trader' de PHP n'accèptent pas les nombres trop petits
        $coefMulti = 100000;

        // Récupération du tableau de clôtures de chaque intervalle (bougies) - Calcul des clôtures sur un tableau 'fixe' ou 'glissant'
        if ($dataSetType == 'glissant') {
            $dataSet = \cryptos\trading\tickerData::glissant($bddName, $bddTable, $timeUnit, $interval, $nbResPlus, $coefMulti);
        } else {
            $dataSet = \cryptos\trading\tickerData::fixe($bddName, $bddTable, $timeUnit, $interval, $nbResPlus, $coefMulti);
        }

        $dataSet = $dataSet['dataSet'];

        try {

            // Installation des fonctions traders sur php7, suivre tuto dans les docs serveur
            // $trader_ema = trader_ema($dataSet, $nbCandlesEma);
            $trader_ema = \MathPHP\Statistics\Average::exponentialMovingAverage($dataSet, $nbCandlesEma);

            $ema = array();
            foreach($trader_ema as $val) {
                $ema[] = $val / 100000;
            }

            // On ne conserve que les résultats voulus
            $countEma = count($ema);

            $ema = array_values($ema);

            for ($i=0; $i<$countEma; $i++) {
                if ($i < ($countEma - $nbRes)) {
                    unset($ema[$i]);
                }
            }

            $ema = array_values($ema);

            return array(
                // 'table'     => $bddTable,
                // 'last'      => $lastEnd,
                // 'dataSet'   => $dataSet,
                'ema'       => $ema,
                'result'    => true,
            );

        } catch (\Exception $e) {

            return array(
                'exception' => $e->getMessage(),
                'result'    => false,
            );
        }
    }


    /**
     * Calcul de 3 EMA avec le même dataSet (tickers)
     *
     * @param       string      $bddName        Nom de la base de données
     * @param       string      $bddTable       Nom de la table
     * @param       string      $timeUnit       Unité de temps du graphique à analyser : MINUTE|HOUR|DAY
     * @param       string      $interval       Nombre d'unités de temps
     * @param       integer     $nbCandlesEma1  Nombre de bougies pour le calcul de la EMA 1
     * @param       integer     $nbCandlesEma2  Nombre de bougies pour le calcul de la EMA 2
     * @param       integer     $nbCandlesEma3  Nombre de bougies pour le calcul de la EMA 3
     * @param       integer     $nbRes          Nombre de cycles analysés
     * @param       string      $dataSetType    Calcul des clôtures sur un tableau 'fixe' ou 'glissant'
     * @param       integer     $tickers        0|1 - Permet de choisir un retour du dataSet des Tickers
     *
     * @return      array                       Résultat de la EMA (Moyenne Mobile Exponnentielle)
     */
    public static function get3Ema($bddName, $bddTable, $timeUnit, $interval, $nbCandlesEma1=8, $nbCandlesEma2=21, $nbCandlesEma3=55, $nbRes=1, $dataSetType='glissant', $tickers=1)
    {
        // Nombre de cycles en plus nécessaire pour calculer une RSI
        $nbResPlus = $nbRes + 120;

        // Coefficient multiplicateur car les fonctions 'trader' de PHP n'accèptent pas les nombres trop petits
        $coefMulti = 100000;

        // Récupération du tableau de clôtures de chaque intervalle (bougies) - Calcul des clôtures sur un tableau 'fixe' ou 'glissant'
        if ($dataSetType == 'glissant') {
            $dataSet = \cryptos\trading\tickerData::glissant($bddName, $bddTable, $timeUnit, $interval, $nbResPlus, $coefMulti);
        } else {
            $dataSet = \cryptos\trading\tickerData::fixe($bddName, $bddTable, $timeUnit, $interval, $nbResPlus, $coefMulti);
        }

        $dataSet = $dataSet['dataSet'];

        try {

            $emaList = array($nbCandlesEma1, $nbCandlesEma2, $nbCandlesEma3);
            $ema     = array();

            foreach($emaList as $key => $nbCandlesEma) {

                // Installation des fonctions traders sur php7, suivre tuto dans les docs serveur
                // $trader_ema = trader_ema($dataSet, $nbCandlesEma);
                if (count($dataSet) > 0) {

                    $trader_ema = \MathPHP\Statistics\Average::exponentialMovingAverage($dataSet, $nbCandlesEma);

                    foreach($trader_ema as $val) {
                        $ema[$nbCandlesEma][] = $val / 100000;
                    }

                    // On ne conserve que les résultats voulus
                    $countEma = count($ema[$nbCandlesEma]);

                    $ema[$nbCandlesEma] = array_values($ema[$nbCandlesEma]);

                    for ($i=0; $i<$countEma; $i++) {
                        if ($i < ($countEma - $nbRes)) {
                            unset($ema[$nbCandlesEma][$i]);
                        }
                    }

                    $ema[$nbCandlesEma] = array_values($ema[$nbCandlesEma]);
                }
            }

            $return = array(
                // 'table'     => $bddTable,
                // 'last'      => $lastEnd,
                'ema'       => $ema,
                'result'    => true,
            );

            if ($tickers == 1) {

                $return['dataSet'] = array();

                foreach($dataSet as $val) {
                    $return['dataSet'][] = $val / 100000;
                }

                // On ne conserve que les résultats voulus
                $countTickers = count($return['dataSet']);

                for ($i=0; $i<$countTickers; $i++) {
                    if ($i < ($countTickers - $nbRes)) {
                        unset($return['dataSet'][$i]);
                    }
                }

                $return['dataSet'] = array_values($return['dataSet']);
            }

            return $return;

        } catch (\Exception $e) {

            return array(
                'exception' => $e->getMessage(),
                'result'    => false,
            );
        }
    }
}
