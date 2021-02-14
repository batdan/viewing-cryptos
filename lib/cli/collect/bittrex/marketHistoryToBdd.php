<?php
namespace cryptos\cli\collect\bittrex;

/**
 * Récupération et stockage de l'intégralité des ordersBooks
 *
 * @author Daniel Gomes
 */
class marketHistoryToBdd
{
    /**
	 * Attributs
	 */
    private $_exchange      = 'bittrex';                // Nom de l'exchange

    private $_dbh;                                      // Instance PDO de la BDD de l'Exchange
    private $_dbh2;                                     // Instance PDO de la BDD commune à la gestion des cryptos

    private $_nameBDD       = 'cryptos_pool';           // Nom de la base de données commune 'cryptos'
    private $_nameExBDD     = 'cryptos_ex_bittrex';     // Nom de la base de données de l'exchange

    private $_prefixeMarketTable    = 'market_';        // Préfixe des tables de market
    private $_prefixeMarketHistoryTable = 'mh_';        // Préfixe des tables d'orderBook

    private $_colorCli;                                 // Gestion des couleurs en interface CLI

    private $_getMarket;                                // Instance de la classe cryptos\bittrex\market\getMarket

    private $_marketList;                               // Liste des markets traités
    private $_mhTableList;                              // Liste des tables de marketHistory

    private $_lastIdTable = array();                    // Stockage à la minute : ID du dernier INSERT pour savoir s'il faut faire un UPDATE

    private $_rotateDelta = 72;                         // Temps de conservation des données en heures
    private $_rotateUnite = 'HOUR';                     // Temps de conservation des données en heures

    private $_listNetworkInterfaces;                    // Liste des interfaces réseau disponibles sur le serveur pour requêter
    private $_currentNetworkInterface;                  // Stockage de l'interface réseau courante pour changer le tour suivant

    private $_timeInit;                                 // Permet de stocker le démarrage d'un tour pour en calculer le temps
    private $_timeEnd;                                  // Permet de stocker le démarrage d'un tour pour en calculer le temps


    /**
	 * Constructeur
	 */
	public function __construct()
	{
        // Instance PDO de la BDD de l'Exchange
        $this->_dbh  = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Instance PDO de la BDD commune à la gestion des cryptos
        $this->_dbh2 = \core\dbSingleton::getInstance($this->_nameBDD);

        // Instance de la classe cryptos\bittrex\orderBook\getMarket
        $this->_getMarket = new \cryptos\api\bittrex\getMarket();

        // Gestion des couleurs en interface CLI
        $this->_colorCli = new \core\cliColorText();

        // Liste des IP disponibles sur le serveur pour requêter
        $this->_listNetworkInterfaces = \core\config::getConfig('ipServer');
    }


    /**
     * Boucle permettant de récupérer les données des markets chaque seconde
     */
     public function run()
     {
         for ($i=0; $i==$i; $i++) {

             $this->actions();

             // Attente souhaitée : 2 secondes
             $timeExec = ($this->_timeEnd - $this->_timeInit) * 1000000;
             $uSleep = 2000000 - $timeExec;

             if ($uSleep > 0) {
                 usleep($uSleep);
             }
         }
     }


    /**
     * Ensemble des actions pour récupérer et stocker les informations de chaque market
     */
    public function actions()
    {
        // Début de la boucle
        $this->_timeInit = microtime(true);

        // Récupération de la liste des tables de market en BDD
        // Ainsi, seuls les markets de plus de 250 BTC seront analysés
        $this->marketList();

        // Appel des scripts qui se chargeront de récupérer tous les orderBooks
        $this->callScripts();

        // Fin de la boucle
        $this->_timeEnd = microtime(true);

        // Informaiton de suivi du bot
        $this->infosBot();
    }


    /**
     * Interface réseau à utiliser
     */
    private function selectInterface()
    {
        if (empty($this->_currentNetworkInterface) || $this->_currentNetworkInterface == end($this->_listNetworkInterfaces)) {

            $this->_currentNetworkInterface = $this->_listNetworkInterfaces[0];

        } else {

            $key = array_search($this->_currentNetworkInterface, $this->_listNetworkInterfaces);
            $this->_currentNetworkInterface = $this->_listNetworkInterfaces[ $key + 1 ];
        }

        return $this->_currentNetworkInterface;
    }


    /**
     * Récupération de la liste des tables des markets 'market_%' en BDD
     */
    private function marketList()
    {
        $prefixeMarketTable         = $this->_prefixeMarketTable;
        $prefixeMarketHistoryTable  = $this->_prefixeMarketHistoryTable;

        $bddExchange = $this->_nameExBDD;

        $this->_marketList  = array();      // Tableau contenant la liste des marketName traités : volume > 250 bitcoins
        $this->_mhTableList = array();      // Tableau contenant toutes les tables de marketHistory

        $req = "SELECT  table_name AS exTable

                FROM    information_schema.tables

                WHERE   (table_name LIKE ('$prefixeMarketTable%') OR table_name LIKE ('$prefixeMarketHistoryTable%'))
                AND     table_schema = '$bddExchange'";

        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {

            $expTable = explode('_', $res->exTable);

            if ($expTable[0] == 'mh') {

                // Récupération de la liste des tables de marketHistory
                $this->_mhTableList[] = $res->exTable;

            } else {

                // Récupération de la liste de marketName
                $this->_marketList[]  = $expTable[1] . '-' . $expTable[2];
            }
        }
    }


    /**
     * Appel des scripts qui se chargeront de récupérer tous les orderBooks
     */
    private function callScripts()
    {
        // Requête de suppression du suvi d'un process dans la table pid
        $req_del = "DELETE FROM pid_pool WHERE id = :id";
        $sql_del = $this->_dbh2->prepare($req_del);

        // Requête pour vérifier s'il ne reste pas un processus de récupération d'orderBook non terminé
        $exchange = $this->_exchange;

        $req = "SELECT * FROM pid_pool WHERE plateforme = '$exchange' AND service = 'marketHistory'";
        $sql = $this->_dbh2->query($req);

        // Tableau listant les process encore en cours de traitement
        $listMarketHistoryProcessActiv  = array();

        while ($res = $sql->fetch()) {

            // On vérifie la durée de vie du PID - max 60s
            $date = new \DateTime($res->date_crea);
            $timestamp = $date->getTimestamp();

            // Process fantôme, on le kill
            if ((time() - $timestamp) >= 60) {

                exec('kill -9 ' . $res->pid);
                $sql_del->execute(array( ':id' => $res->id ));

            // Process en cours d'exécution dans un délai encore acceptable, on le laisse finir
            } else {
                $listMarketHistoryProcessActiv[] = $res->market;
            }
        }

        // Affichage des scripts non terminées dans la bouble précédente
        if (count($listMarketHistoryProcessActiv) > 0) {
            echo $this->_colorCli->getColor(' Scripts non terminés : ', 'light_red') . chr(10) . chr(10);
            $i=0;
            foreach ($listMarketHistoryProcessActiv as $marketName) {
                echo $this->_colorCli->getColor(' ' . str_pad($i, 2, '0', STR_PAD_LEFT) . ' : ' . $marketName, 'light_red') . chr(10);
                $i++;
            }
            echo chr(10);
        }

        // Boucle pour appeler tous les scripts de récupération des orderBooks
        $i=1;
        foreach ($this->_marketList as $marketName) {

            // if ($marketName != 'usdt-btc') {
            //     continue;
            // }

            // Traitement de la récupération de marketHistory
            if (! in_array($marketName, $listMarketHistoryProcessActiv)) {
                $networkInterface = $this->selectInterface();
                $cde = 'php -f botSaveMarketHistoryAux.php ' . $marketName . ' ' . $networkInterface;
                echo $this->_colorCli->getColor(' ' . str_pad($i, 2, '0', STR_PAD_LEFT) . ' : ' . $cde, 'yellow') . chr(10);
                exec($cde . ' &> /dev/null &');
                $i++;
            }
        }

        // Suppression des tables obsolètes
        $this->deleteTables();
    }


    /**
     * Sauvegarde d'un marketHistory avec le process auxiliaire
     */
    public function saveMarketHistory($marketName, $networkInterface)
    {
        // Récupération de la liste des tables de market en BDD
        // Ainsi, seuls les markets de plus de 250 BTC seront analysés
        $this->marketList();

        // Récupération du PID du process courant
        $pid = getmypid();

        // Sauvegarde du pid en BDD
        $exchange = $this->_exchange;

        $req = "INSERT INTO pid_pool (plateforme, service, market, pid, date_crea) VALUES ('$exchange', 'marketHistory', '$marketName', $pid, NOW())";
        echo $req . chr(10);

        $sql = $this->_dbh2->query($req);

        // Requête qui supprime le suivi du process dans la table PID si abandonné ou terminé
        $req_del = "DELETE FROM pid_pool WHERE pid = " . $pid;

        $curlOptInterface = array(CURLOPT_INTERFACE => $networkInterface);

        // Récupération du Trade History
        $getMarketHistory = $this->_getMarket->getMarketHistory(strtoupper($marketName), $curlOptInterface);

        // Problème avec l'API, on kill le process et on nettoie pid_pool
        if ($getMarketHistory === false || ! is_array($getMarketHistory)) {
            $this->_dbh2->query($req_del);
            exec('kill ' . $pid);
            return;
        }

        // Nom de la table pour stocker le marketHistory
        $tableName = $this->tableMarketHistoryName($marketName);

        // Vérification de l'existence de la table en BDD
        $this->checkExistTable($tableName);

        // Récupération du maw_id et des id, quantity et total des 5 dernières minutes pour les BUY et SELL
        $req = "SELECT id, id_ex, quantity, total, orderType, timestampEx FROM $tableName WHERE timestampEx >= DATE_ADD((SELECT MAX(timestampEx) FROM $tableName), INTERVAL -5 MINUTE) ORDER BY id ASC";
        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {

            $id             = $res->id;
            $id_ex          = $res->id_ex;
            $quantity       = $res->quantity;
            $total          = $res->total;
            $orderType      = $res->orderType;

            $timestampEx    = $res->timestampEx;
            $lastMinute     = substr($timestampEx, 0, 16);

            $this->_lastIdTable[$tableName][$lastMinute][$orderType] = array(
                'id'        => $id,
                'quantity'  => $quantity,
                'total'     => $total,
            );

            // L'enregistrement ayant l'id_ex le plus grand n'est pas forcément le dernier (BUY ou SELL)
            if (!isset($this->_lastIdTable[$tableName]['id_ex']) || $id_ex > $this->_lastIdTable[$tableName]['id_ex']) {
                $this->_lastIdTable[$tableName]['id_ex'] = $id_ex;
            }
        }

        // Conversion de l'objet API en Array
        $getMarketHistory = json_decode(json_encode($getMarketHistory), TRUE);

        // Triage du tableau de résultats sur le timeStamp
        $rang = array();
        if (count($getMarketHistory) > 0) {
            foreach ($getMarketHistory as $key => $val) {
                $rang[$key]  = $val['Id'];
            }

            // Trie les données par rang décroissant sur la colonne 'gain'
            array_multisort($rang, SORT_ASC, $getMarketHistory);
        }

        // Requête d'insertion des achats et des ventes
        $req = "INSERT INTO $tableName  ( id_ex,  quantity,  rate,  total,  fillType,  orderType,  timestampEx,  millisecondes,  date_crea,  date_modif)
                VALUES                  (:id_ex, :quantity, :rate, :total, :fillType, :orderType, :timestampEx, :millisecondes,  NOW(),      NOW())";
        $sql = $this->_dbh->prepare($req);

        // Requête de mise à jour
        $req_maj = "UPDATE          $tableName

                    SET             id_ex           = :id_ex,
                                    quantity        = :quantity,
                                    total           = :total,
                                    timestampEx     = :timestampEx,
                                    date_modif      =  NOW()

                    WHERE           id              = :id";
        $sql_maj = $this->_dbh->prepare($req_maj);

        foreach ($getMarketHistory as $marketHistory) {

            $id_ex      = $marketHistory['Id'];
            $quantity   = $marketHistory['Quantity'];
            //$rate     = $marketHistory['Price'];
            $total      = $marketHistory['Total'];
            //$fillType = $marketHistory['FillType'];
            $orderType  = $marketHistory['OrderType'];

            // Conversion de la date
            $timestampEx = $this->convertDateTime($marketHistory['TimeStamp']);
            $timestampEx = $timestampEx['dateTime'];

            // Minute en cours
            $lastMinute  = substr($timestampEx, 0, 16);

            // On ne créé pas de doublon
            if (isset($this->_lastIdTable[$tableName]['id_ex']) && $id_ex <= $this->_lastIdTable[$tableName]['id_ex']) {
                continue;
            } else {

                if ( !isset($this->_lastIdTable[$tableName][$lastMinute][$orderType]) ) {

                    try {
                        $sql->execute(array(
                            ':id_ex'            => $id_ex,
                            ':quantity'         => $quantity,
                            ':rate'             => 0,
                            ':total'            => $total,
                            ':fillType'         => 'FILL',
                            ':orderType'        => $orderType,
                            ':timestampEx'      => $timestampEx,
                            ':millisecondes'    => 0,
                        ));

                        $this->_lastIdTable[$tableName][$lastMinute][$orderType] = array(
                            'id'        => $this->_dbh->lastInsertId(),
                            'quantity'  => $quantity,
                            'total'     => $total,
                        );

                        $this->_lastIdTable[$tableName]['id_ex'] = $id_ex;

                    } catch (\Exception $e) {
                        echo chr(10) . $e . chr(10);
                    }

                } else {

                    $this->_lastIdTable[$tableName][$lastMinute][$orderType]['quantity'] += $quantity;
                    $this->_lastIdTable[$tableName][$lastMinute][$orderType]['total']    += $total;

                    try {
                        $sql_maj->execute(array(
                            ':id_ex'            => $id_ex,
                            ':quantity'         => $this->_lastIdTable[$tableName][$lastMinute][$orderType]['quantity'],
                            ':total'            => $this->_lastIdTable[$tableName][$lastMinute][$orderType]['total'],
                            ':timestampEx'      => $timestampEx,
                            ':id'               => $this->_lastIdTable[$tableName][$lastMinute][$orderType]['id'],
                        ));

                        $this->_lastIdTable[$tableName]['id_ex'] = $id_ex;

                    } catch (\Exception $e) {
                        echo chr(10) . $e . chr(10);
                    }
                }
            }
        }

        // Suppression des entrées dépassant le nombre d'heures de conservation des données
        $rotateDelta = $this->_rotateDelta;
        $rotateUnite = $this->_rotateUnite;

        // Rotate : suppression des entrées trop anciennes
        try {
            $req = "DELETE FROM $tableName WHERE date_crea < DATE_ADD(NOW(), INTERVAL -$rotateDelta $rotateUnite)";
            $sql = $this->_dbh->query($req);
        } catch (\Exception $e) {
            echo chr(10) . $e . chr(10);
        }

        // Nettoyage de pid_pool
        try {
            $this->_dbh2->query($req_del);
        } catch (\Exception $e) {
            echo chr(10) . $e . chr(10);
        }

        // Le script est terminé
        exec('kill ' . $pid);

        return;
    }


    /**
     * Si la table du marketName n'existe pas, elle est créée
     *
     * @param       string      $marketName        Nom du marketName
     */
    private function checkExistTable($tableName)
    {
        if (count($this->_mhTableList) == 0 || ! in_array($tableName, $this->_mhTableList)) {
            $req = $this->tableStructure($tableName);
            $sql = $this->_dbh->query($req);
        }
    }


    /**
     * Template pour les créations de tables
     *
     * @param       string      $tableName     Nom de la table à créer
     * @return      string
     */
    private function tableStructure($tableName)
    {
        // Création d'une table de marketHistory
        $req = <<<eof
            SET SQL_MODE  = "NO_AUTO_VALUE_ON_ZERO";

            CREATE TABLE `___TABLE_NAME___` (
                `id`                int(11)         NOT NULL,
                `id_ex`             int(11)         NOT NULL,
                `quantity`          decimal(16,8)   NOT NULL,
                `rate`              decimal(16,8)   NOT NULL,
                `total`             decimal(16,8)   NOT NULL,
                `fillType`          varchar(12)     NOT NULL,
                `orderType`         varchar(4)      NOT NULL,
                `timestampEx`       datetime        NOT NULL,
                `millisecondes`     int(6)          NOT NULL,
                `date_crea`         datetime        NOT NULL,
                `date_modif`        datetime        NOT NULL
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8;


            ALTER TABLE `___TABLE_NAME___`
            ADD PRIMARY KEY                     (`id`),
            ADD UNIQUE  KEY `id_ex`             (`id_ex`),
            ADD         KEY `timestampEx`       (`timestampEx`),
            ADD         KEY `date_crea`         (`date_crea`),
            ADD         KEY `date_modif`        (`date_modif`);

            ALTER TABLE `___TABLE_NAME___`
            MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
eof;

        return str_replace('___TABLE_NAME___', $tableName, $req);
    }


    /**
     * Suppression des tables obsolètes
     */
    private function deleteTables()
    {
        // Mise en forme de la liste des tables pour la requête
        if (count($this->_marketList) > 0) {

            $tableList = array();

            foreach ($this->_marketList as $market) {
                $tableList[] = "'" . $this->tableMarketHistoryName($market) . "'";
            }

            $tableList = implode(', ', $tableList);

            $prefixeMarketHistoryTable = $this->_prefixeMarketHistoryTable;
            $bddExchange = $this->_nameExBDD;

            // Récupération de la liste des tables obsolètes
            $req = "SELECT  table_name AS exTable

                    FROM    information_schema.tables

                    WHERE   table_name NOT IN ($tableList)
                    AND     table_name LIKE ('$prefixeMarketHistoryTable%')
                    AND     table_schema = '$bddExchange'";

            $sql = $this->_dbh->query($req);

            // Suppression
            while ($res = $sql->fetch()) {
                $this->_dbh->query("DROP TABLE " . $res->exTable);
            }
        } else {
            die(chr(10) . 'Attention : Le script summariesToBdd doit toujours être lancé en premier !' . chr(10) . chr(10));
        }
    }


    /**
     * Récupère le nom de la table associée à un marketName
     *
     * @param       string      $marketName         Nom du marketName
     */
    private function tableMarketHistoryName($marketName)
    {
        return $this->_prefixeMarketHistoryTable . str_replace('-', '_', strtolower($marketName));
    }


    /**
     * Infomation de suivi du bot
     */
    private function infosBot()
    {
        // Affichage de suivi
        if (PHP_SAPI === 'cli') {
            system('clear');
        }

        $hr = chr(10) . chr(10) . '________________________________' . chr(10) . chr(10) . chr(10);
        $hr = $this->_colorCli->getColor($hr, 'dark_gray');

        // Titre ---------------------------------------------------------------
        $texte  = chr(10);
        $texte .= $this->_colorCli->getColor(' Exchange : ' . ucfirst($this->_exchange), 'white') . chr(10);
        $texte .= $this->_colorCli->getColor(' Sauvegarde : marketHistory', 'white') . chr(10) . chr(10);
        $texte .= $this->_colorCli->getColor(' ' . date('Y-m-d H:i:s'), 'yellow');

        // Durées d'exécution --------------------------------------------------
        $texte .= $hr;
        $texte .= $this->_colorCli->getColor(' Durée : ' . round(($this->_timeEnd - $this->_timeInit), 3) . 's', 'light_gray') . chr(10) . chr(10);

        echo $texte;
    }


    /**
     * Convertion des dates et heures GMT 0
     * Séparation du dateTime et des millisecondes
     *
     * @param       string      $timeStamp          Format dateTime + millisecondes
     * @return      array
     */
    private function convertDateTime($timeStamp)
    {
        $expDate = explode('.', $timeStamp);

        if (isset($expDate[1])) {
            $millisecondes = $expDate[1];
        } else {
            $millisecondes = 0;
        }

        // $timeZone       = "Europe/Paris";
        // $dateTimeZone   = new \DateTimeZone($timeZone);
        // $dateTime       = new \DateTime($expDate[0], $dateTimeZone);
        // $timestampParis = $dateTime->getTimestamp() + $dateTimeZone->getOffset($dateTime);
        // $dateTime->setTimestamp($timestampParis);

        $dateTime = new \DateTime($expDate[0]);

        return array(
            'dateTime'      => $dateTime->format('Y-m-d H:i:s'),
            'millisecondes' => $millisecondes,
        );
    }
}
