<?php
namespace cryptos\trading;

/**
 * Functions de calcul liées au trading :
 * Tableau des prix de cloture des x dernières bougies (selon un interval)
 *
 * @author Daniel Gomes
 */
class tickerData
{
    /**
     * Calcul des clôtures de bougies avec un temps glissant
     *
     * @param       string      $bddName        Nom de la base de données
     * @param       string      $bddTable       Nom de la table
     * @param       string      $timeUnit       Unité de temps du graphique à analyser : MINUTE|HOUR|DAY
     * @param       string      $interval       Intervalle de temps
     * @param       integer     $nbRes          Nombre de bougies analysés
     * @param       integer     $coefMulti      Coéfficient multiplicateur car les fonctions trader n'accèptent pas les nombres trop petits
     *
     * @return      array                       Retourne un tableau avec le prix de cloture de chaque période
     */
    public static function glissant($bddName, $bddTable, $timeUnit, $interval, $nbRes=1, $coefMulti=1)
    {
        // Instance PDO
        $dbh = \core\dbSingleton::getInstance($bddName);

        // Prise en charge des markets virtuels
        $virtualMarket = false;
        if (strstr($bddTable, 'virtual') !== false) {
            $bddTable = self::virtualBddTable($bddTable);
            $virtualMarket = true;
        }

        $allInterval = $interval * ($nbRes + 1);

        switch ($timeUnit)
        {
            case 'MINUTE' : $timeUnitInSecond = 60;     break;
            case 'HOUR'   : $timeUnitInSecond = 3600;   break;
            case 'DAY'    : $timeUnitInSecond = 86400;  break;
            default       : return false;
        }

        try {

            // Récupération du timestamp de la dernière entrée
            $req = "SELECT last, UNIX_TIMESTAMP(date_modif) AS lastTimestamp, date_modif FROM $bddTable ORDER BY id DESC LIMIT 1";
            $sql = $dbh->query($req);

            if ($sql->rowCount() == 0) {
                error_log('Aucune entrée en base pour ce market');
                return false;
            }

            $res = $sql->fetch();
            $lastEnd       = $res->last;
            $lastTimestamp = $res->lastTimestamp;
            $lastDateModif = $res->date_modif;

            $req = "SELECT      last, UNIX_TIMESTAMP(date_modif) AS recupTimestamp, date_modif
                    FROM        $bddTable
                    WHERE       date_crea >= DATE_ADD('$lastDateModif', INTERVAL -$allInterval $timeUnit)
                    ORDER BY    id ASC";
            $sql = $dbh->query($req);

            // Temps flottant
            $d  = new \DateTime();

            // Temps de la dernière entrée dans cette table
            $d2 = new \DateTime();
            $d2->setTimestamp($lastTimestamp);

            $timestampInit = $lastTimestamp - ($timeUnitInSecond * $allInterval);

            $d->setTimestamp($timestampInit);
            //error_log($timestampInit . ' = ' . $d->format('Y-m-d H:i:s') . ' | ' . $lastTimestamp . ' = ' . $d2->format('Y-m-d H:i:s'));

            // Récupération des paliers de temps
            $timeBack = $lastTimestamp;
            $timeStep = array();
            while ($timeBack > $timestampInit) {
                $d->setTimestamp($timeBack);
                $timeStep[] = $timeBack . ' = ' . $d->format('Y-m-d H:i:s');
                $timeBack -= $interval * $timeUnitInSecond;
            }

            // On remplace la dernière entrée avec l'heure actuelle pour obtenir le dernier palier
            $timeStep = array_reverse($timeStep);
            if (time() > end($timeStep)) {
                $d->setTimestamp(time());
                $lastKey = count($timeStep) - 1;
                $timeStep[$lastKey] = time() . ' = ' . $d->format('Y-m-d H:i:s');
            }

            // Tableau des clôtures
            $dataSet = array();

            // Récupération de la dernière valeur de chaque palier
            while ($res = $sql->fetch()) {

                $last           = $res->last * $coefMulti;
                $recupTimestamp = $res->recupTimestamp;
                $date_modif     = $res->date_modif;

                $countTimeStep  = count($timeStep);

                for($i=0; $i<$countTimeStep; $i++) {

                    if ($recupTimestamp > $timeStep[$i] && $recupTimestamp <= $timeStep[$i+1]) {
                        // $dataSet[$i] = $last . ' . ' . $res->recupTimestamp . ' - ' . $date_modif; // Debug
                        $dataSet[$i] = $last;
                        break;
                    }
                }
            }

            // Le tableau doit toujours démarrer avec une clé à 0
            $keys = array_keys($dataSet);
            $initKey = $keys[0];

            $replaceKeyDataSet = array();

            if ($initKey > 0) {
                foreach($dataSet as $k => $v) {
                    $replaceKeyDataSet[$k-$initKey] = $v;
                }

                $dataSet = $replaceKeyDataSet;
            }

            // On comble les trous dans le tableau, s'il y n'y a aucune valeur dans une plage, la valeur de la bougie précédente est concervée
            $keys   = array_keys($dataSet);
            $endKey = end($keys);

            $addKeyDataSet = array();

            for ($i=0; $i<=$endKey; $i++) {
                if (isset($dataSet[$i])) {
                    $addKeyDataSet[$i] = $dataSet[$i];
                } else {
                    if (isset($dataSet[$i-1])) {
                        //$addKeyDataSet[$i] = $dataSet[$i-1] . ' -> repeat'; // Debug
                        $addKeyDataSet[$i] = $dataSet[$i-1];
                    }
                    if (isset($addKeyDataSet[$i-1])) {
                        //$addKeyDataSet[$i] = $addKeyDataSet[$i-1] . ' -> repeat'; // Debug
                        $addKeyDataSet[$i] = $addKeyDataSet[$i-1];
                    }
                }
            }

            // On ne conserve que les résultats voulus
            $dataSet = array_values($addKeyDataSet);

            // Dans le cas d'un market virtuel, toutes les clôtures sont converties en USDT
            if ($virtualMarket === true) {
                $dataSet = self::BtcToUsdtPrice($bddName, 'market_usdt_btc', $dataSet);
            }

            return array(
                'dataSet' => $dataSet,
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
     * Calcul des clôtures de bougies avec des plages fixes (idem Tradingviw)
     *
     * @param       string      $bddName        Nom de la base de données
     * @param       string      $bddTable       Nom de la table
     * @param       string      $timeUnit       Unité de temps du graphique à analyser : MINUTE|HOUR|DAY
     * @param       string      $interval       Intervalle de temps - MINUTE : 1,3,5,15,30 - HOUR : 1,2,3,4 - DAY : 1
     * @param       integer     $nbRes          Nombre de bougies analysés
     * @param       integer     $coefMulti      Coéfficient multiplicateur car les fonctions trader n'accèptent pas les nombres trop petits
     *
     * @return      array                       Retourne un tableau avec le prix de cloture de chaque période
     */
    public static function fixe($bddName, $bddTable, $timeUnit, $interval, $nbRes=1, $coefMulti=1)
    {
        // Instance PDO
        $dbh = \core\dbSingleton::getInstance($bddName);

        // Prise en charge des markets virtuels
        $virtualMarket = false;
        if (strstr($bddTable, 'virtual') !== false) {
            $bddTable = self::virtualBddTable($bddTable);
            $virtualMarket = true;
        }

        $allInterval = $interval * $nbRes;

        try {

            // Récupération du timestamp de la dernière entrée
            $req = "SELECT last, date_modif FROM $bddTable ORDER BY id DESC LIMIT 1";
            $sql = $dbh->query($req);

            if ($sql->rowCount() == 0) {
                error_log('Aucune entrée en base pour ce market');
                return false;
            }

            $res           = $sql->fetch();
            $lastEnd       = $res->last;
            $lastDateModif = $res->date_modif;

            $req = "SELECT      last, UNIX_TIMESTAMP(date_modif) AS recupTimestamp, date_modif
                    FROM        $bddTable
                    WHERE       date_crea >= DATE_ADD('$lastDateModif', INTERVAL -$allInterval $timeUnit)
                    ORDER BY    id ASC";
            $sql = $dbh->query($req);

            // Préparation d'un range par interval de temps
            switch (substr($timeUnit,0,1) . $interval)
            {
                case 'M1'   : $range = range(0,  59,  1);    break;
                case 'M3'   : $range = range(2,  59,  3);    break;
                case 'M5'   : $range = range(4,  59,  5);    break;
                case 'M15'  : $range = range(14, 59, 15);    break;
                case 'M30'  : $range = range(29, 59, 30);    break;
                case 'H1'   : $range = range(0,  23,  1);    break;
                case 'H2'   : $range = range(1,  23,  2);    break;
                case 'H3'   : $range = range(2,  23,  3);    break;
                case 'H4'   : $range = range(3,  23,  4);    break;
                case 'D1'   : $range = range(1,  31,  1);    break;

                default     : return false;
            }

            // Tableau des clôtures
            $dataSet = array();

            // Récupération de la dernière valeur de chaque palier
            $i=0;
            while ($res = $sql->fetch()) {

                $dataSet[$i]['date'] = $res->recupTimestamp;
                $dataSet[$i]['last'] = $res->last * $coefMulti;

                $i++;
            }

            // Filtrage des résultats pour ne conserver que la clôture chaque bougie
            $countRes = count($dataSet) - 1;

            $dataSet2 = array();

            $d = new \DateTime();

            $j=0;
            for ($i=$countRes; $i>0; $i--) {

                $d->setTimestamp($dataSet[$i]['date']);
                $dateTime = $d->format('Y-m-d H:i:s');

                if ($i == $countRes) {

                    // $dataSet2[$j] = $dateTime . ' : ' . $dataSet[$i]['last'];
                    $dataSet2[$j] = $dataSet[$i]['last'];
                    $j++;

                    $hour = $d->format('H');
                    $day  = $d->format('d');

                } else {

                    // Filtrage pour les intervalles à la minute
                    if ($timeUnit == 'MINUTE' && in_array($d->format('i'), $range)) {
                        // $dataSet2[$j] = $dateTime . ' : ' . $dataSet[$i]['last'];
                        $dataSet2[$j] = $dataSet[$i]['last'];
                        $j++;
                    }

                    // Filtrage pour les intervalles à l'heure
                    if ($timeUnit == 'HOUR' && in_array($d->format('H'), $range)) {
                        if (isset($hour) && $hour != $d->format('H')) {
                            // $dataSet2[$j] = $dateTime . ' : ' . $dataSet[$i]['last'];
                            $dataSet2[$j] = $dataSet[$i]['last'];
                            $j++;
                        }
                        $hour = $d->format('H');
                    }

                    // Filtrage pour les intervalles à la journée
                    if ($timeUnit == 'DAY' && in_array($d->format('d'), $range)) {
                        if (isset($day) && $day != $d->format('d')) {
                            // $dataSet2[$j] = $dateTime . ' : ' . $dataSet[$i]['last'];
                            $dataSet2[$j] = $dataSet[$i]['last'];
                            $j++;
                        }
                        $day = $d->format('d');
                    }
                }
            }

            $dataSet = array_reverse($dataSet2);

            // Dans le cas d'un market virtuel, toutes les clôtures sont converties en USDT
            if ($virtualMarket === true) {
                $dataSet = self::BtcToUsdtPrice($bddName, 'market_usdt_btc', $dataSet);
            }

            return array(
                'dataSet'   => $dataSet,
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
     * Pour un market vituel, cette fonction retourne le nom de la table 'Market' de la monnaie indexée au BTC
     */
    public static function virtualBddTable($bddTable)
    {
        $extTable = explode('_', $bddTable);

        return 'market_btc_' . $extTable[2];
    }


    /**
     * Conversion d'un prix ou d'un tableau de prix de BTC vers UDST pour les marché virtuels
     */
    public static function BtcToUsdtPrice($bddName, $bddTable, $dataSet)
    {
        // Récupération du ticker USDT-BTC
        $ticker = self::ticker($bddName, $bddTable);

        // Conversion des prix BTC en USDT
        if (is_array($dataSet)) {

            $newDataSet = array();

            foreach($dataSet as $val) {
                $newDataSet[] = $val * $ticker;
            }

        } else {
            $newDataSet = $dataSet * $ticker;
        }


        return $newDataSet;
    }


    /**
     * Réucpération du ticker d'un market
     */
    public static function ticker($bddName, $bddTable, $bddTable_usdt_btc='market_usdt_btc', $api_exchange=null)
    {
        // Instance PDO
        $dbh = \core\dbSingleton::getInstance($bddName);

        // Prise en charge des markets virtuels
        $virtualMarket = false;
        if (strstr($bddTable, 'virtual') !== false) {
            $bddTable       = self::virtualBddTable($bddTable);
            $tickerCoin     = self::ticker($bddName, $bddTable);
            $virtualMarket  = true;
            $bddTable       = $bddTable_usdt_btc;
        }

        $req = "SELECT last, UNIX_TIMESTAMP(date_modif) AS date_modif_ts FROM $bddTable ORDER BY id DESC LIMIT 1";
        $sql = $dbh->query($req);
        $res = $sql->fetch();

        // Si les données en BDD ne sont pas assez fraiches, on appelle l'API Binance
        if ((time() - $res->date_modif_ts) > 10 && !is_null($api_exchange)) {

            // Récupération du prix avec l'API de binance
            if ($bddName == 'cryptos_ex_binance') {

                $ticker = $api_exchange->prices();

                if (isset($ticker['code'])) {

                    $message  = '_____ Class tickerData / Method BtcToUsdtPrice ERROR _____' . chr(10) . chr(10);
                    $message .= 'Code : ' . $order['code'] . chr(10);
                    $message .= 'Message : ' . $order['msg'] . chr(10) . chr(10);

                    error_log($message, 3, $this->_logFileName);
                    $this->telegramMsg($message);

                } else {

                    $expMarket  = explode('_', $bddTable);
                    $marketName = $expMarket[2] . $expMarket[1];
                    $marketName = strtoupper($marketName);

                    $ticker = $ticker[$marketName];
                }
            }
        } else {

            $ticker = $res->last;
        }

        // Prise en charge des markets virtuels
        if ($virtualMarket === true) {
            $ticker *= $tickerCoin;
        }

        return $ticker;
    }
}
