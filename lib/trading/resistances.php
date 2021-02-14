<?php
namespace cryptos\trading;

/**
 * Permet de repérer les zones d'appui et de résistance
 *
 * @author Daniel Gomes
 */
class resistances
{
    /**
     * Récupération des resistances et appuis
     *
     * @return      array
     */
    public static function getResistance($bddName, $marketTable)
    {
        // Nombre d'heures analysées
        $nbHour = 24;

        // Nom de la table de market
        $mhTable = str_replace('market_', 'mh_', $marketTable);

        // Instance PDO
        $dbh = \core\dbSingleton::getInstance($bddName);

        $req = "SELECT date_modif, last FROM $marketTable WHERE date_modif >= DATE_ADD(NOW(), INTERVAL -$nbHour HOUR) ORDER BY id ASC ";
        $sql = $dbh->query($req);

        $topLast = 0;
        $data = array();

        while ($res = $sql->fetch()) {
            $minute = substr($res->date_modif, 0, -3);

            $last = $res->last;

            if ($last > $topLast) {
                $topLast = $last;
            }

            $data["$minute"]['last'] = $last;
        }

        // echo '<pre>';
        // echo '</pre>';

        $req = "SELECT date_modif, orderType, total FROM $mhTable WHERE date_modif >= DATE_ADD(NOW(), INTERVAL -$nbHour HOUR) ORDER BY id ASC";
        $sql = $dbh->query($req);

        while ($res = $sql->fetch()) {

            $minute = substr($res->date_modif, 0, -3);

            if (isset($data["$minute"])) {
                $data["$minute"]['orderType'] = $res->orderType;
                $data["$minute"]['total']     = $res->total;
            }
        }

        // print_r($data);

        $percent = $topLast / 200;

        $volumePrice = array();

        foreach($data as $key => $val) {

            $tranche = floor($val['last'] / $percent);

            if (!isset($volumePrice["$tranche"])) {

                if (!isset($val['total'])) {
                    continue;
                }

                $volumePrice["$tranche"]['min'] = $tranche * $percent;
                $volumePrice["$tranche"]['max'] = $volumePrice["$tranche"]['min'] + $percent;

                if (isset($volumePrice["$tranche"]['totalVol']))      { $volumePrice["$tranche"]['totalVol']     += $val['total']; }
                else                                                { $volumePrice["$tranche"]['totalVol']      = $val['total']; }

                if (($val['orderType'] == 'BUY')) {
                    if (isset($volumePrice["$tranche"]['totalVolBuy']))   { $volumePrice["$tranche"]['totalVolBuy']  += $val['total']; }
                    else                                                { $volumePrice["$tranche"]['totalVolBuy']   = $val['total']; }
                }

                if (($val['orderType'] == 'SELL')) {
                    if (isset($volumePrice["$tranche"]['totalVolSell']))  { $volumePrice["$tranche"]['totalVolSell'] += $val['total']; }
                    else                                                { $volumePrice["$tranche"]['totalVolSell']  = $val['total']; }
                }

            } else {

                if (!isset($val['total'])) {
                    continue;
                }

                if (isset($volumePrice["$tranche"]['totalVol']))      { $volumePrice["$tranche"]['totalVol']     += $val['total']; }
                else                                                { $volumePrice["$tranche"]['totalVol']      = $val['total']; }

                if (($val['orderType'] == 'BUY')) {
                    if (isset($volumePrice["$tranche"]['totalVolBuy']))   { $volumePrice["$tranche"]['totalVolBuy']  += $val['total']; }
                    else                                                { $volumePrice["$tranche"]['totalVolBuy']   = $val['total']; }
                }

                if (($val['orderType'] == 'SELL')) {
                    if (isset($volumePrice["$tranche"]['totalVolSell']))  { $volumePrice["$tranche"]['totalVolSell'] += $val['total']; }
                    else                                                { $volumePrice["$tranche"]['totalVolSell']  = $val['total']; }
                }
            }
        }

        $topVol = 0;
        foreach($volumePrice as $key => $val) {
            if ($val['totalVol'] > $topVol) {
                $topVol = $val['totalVol'];
            }
        }

        $percentVol = $topVol / 100;

        foreach($volumePrice as $key => $val) {
            $volumePrice[$key]['pctVol'] = floor($val['totalVol'] / $percentVol);
        }

        // Tableau trié sur les pourcentage
        $rang = array();
        if (count($volumePrice) > 0) {
            foreach ($volumePrice as $key => $val) {
                $rang[$key]  = $val['pctVol'];
            }

            // Trie les données par rang décroissant sur la colonne 'pct'
            array_multisort($rang, SORT_ASC, $volumePrice);
        }

        print_r($volumePrice);
    }
}
