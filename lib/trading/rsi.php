<?php
namespace cryptos\trading;

/**
 * Functions de calcul liées au trading : Calcul de la RSI
 * Le RSI (Relative Strength Index) est un indicateur technique très utilisé par les traders.
 *
 * @author Daniel Gomes
 */
class rsi
{
    /**
     * Calcul de la RSI
     *
     * @param       string      $bddName        Nom de la base de données
     * @param       string      $bddTable       Nom de la table
     * @param       string      $timeUnit       Unité de temps du graphique à analyser : MINUTE|HOUR|DAY
     * @param       string      $interval       Intervalle de temps
     * @param       integer     $nbCandles      Nombre de bougies pour le calcul de la RSI
     * @param       integer     $nbRes          Nombre de cycles analysés
     * @param       string      $dataSetType    Calcul des clôtures sur un tableau 'fixe' ou 'glissant'
     *
     * @return      array                       Résultat de la RSI (tableau de cycles entre 0% et 100%)
     */
    public static function getRSI($bddName, $bddTable, $timeUnit, $interval, $nbCandles=14, $nbRes=3, $dataSetType='glissant')
    {
        // Nombre de cycles en plus nécessaire pour calculer une RSI
        $nbResPlus = $nbRes + 60;

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
            $rsi = trader_rsi($dataSet, $nbCandles);

            // On ne conserve que les résultats voulus
            $countRSI = count($rsi);

            $rsi = array_values($rsi);

            for ($i=0; $i<$countRSI; $i++) {
                if ($i < ($countRSI - $nbRes)) {
                    unset($rsi[$i]);
                }
            }

            $rsi = array_values($rsi);

            return array(
                // 'table'     => $bddTable,
                // 'last'      => $lastEnd,
                // 'dataSet'   => $dataSet,
                'rsi'       => $rsi,
                'result'    => true,
            );

        } catch (\Exception $e) {

            return array(
                'exception' => $e->getMessage(),
                'result'    => false,
            );
        }
    }
}
