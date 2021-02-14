<?php
namespace cryptos\cli\collect\poloniex;

/**
 * Récupération et stockage de l'intégralité des ordersBooks
 *
 * @author Daniel Gomes
 */
class orderBookToBdd
{
    /**
	 * Attributs
	 */
    private $_exchange      = 'poloniex';               // Nom de l'exchange

    private $_dbh;                                      // Instance PDO de la BDD de l'Exchange
    private $_dbh2;                                     // Instance PDO de la BDD commune à la gestion des cryptos

    private $_nameBDD       = 'cryptos_pool';           // Nom de la base de données commune 'cryptos'
    private $_nameExBDD     = 'cryptos_ex_poloniex';    // Nom de la base de données de l'exchange

    private $_prefixeMarketTable    = 'market_';        // Préfixe des tables de market
    private $_prefixeOrderBookTable = 'ob_';            // Préfixe des tables d'orderBook

    private $_colorCli;                                 // Gestion des couleurs en interface CLI

    private $_marketList;                               // Liste des markets traités
    private $_obTableList;                              // Liste des tables d'orderBook

    private $_rotateDelta   = 6;                        // Temps de conservation des données en heures
    private $_rotateUnite   = 'HOUR';                   // Temps de conservation des données en heures

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

             // Attente souhaitée : 1 secondes
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
        $prefixeMarketTable    = $this->_prefixeMarketTable;
        $prefixeOrderBookTable = $this->_prefixeOrderBookTable;

        $bddExchange = $this->_nameExBDD;

        $this->_marketList  = array();      // Tableau contenant la liste des marketName traités : volume > 250 bitcoins
        $this->_obTableList = array();      // Tableau contenant toutes les tables d'orderBook

        $req = "SELECT  table_name AS exTable

                FROM    information_schema.tables

                WHERE   (table_name LIKE ('$prefixeMarketTable%') OR table_name LIKE ('$prefixeOrderBookTable%'))
                AND     table_schema = '$bddExchange'";

        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {

            $expTable = explode('_', $res->exTable);

            if ($expTable[0] == 'ob') {

                // Récupération de la liste des tables d'orderBook
                $this->_obTableList[] = $res->exTable;

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

        $req = "SELECT * FROM pid_pool WHERE plateforme = '$exchange' AND service = 'orderBook'";
        $sql = $this->_dbh2->query($req);

        // Tableau listant les process encore en cours de traitement
        $listOrderBookProcessActiv      = array();

        while ($res = $sql->fetch()) {

            // On vérifie la durée de vie du PID - max 60s
            $date = new \DateTime($res->date_crea);
            $timestamp = $date->getTimestamp();

            // Process fantome, on le kill
            if ((time() - $timestamp) >= 60) {

                exec('kill -9 ' . $res->pid);
                $sql_del->execute(array( ':id' => $res->id ));

            // Process en cours d'exécution dans un délai encore acceptable, on le laisse finir
            } else {
                $listOrderBookProcessActiv[] = $res->market;
            }
        }

        // Affichage des scripts non terminées dans la bouble précédente
        if (count($listOrderBookProcessActiv) > 0) {
            echo $this->_colorCli->getColor(' Scripts non terminés : ', 'light_red') . chr(10) . chr(10);
            $i=0;
            foreach ($listOrderBookProcessActiv as $marketName) {
                echo $this->_colorCli->getColor(' ' . str_pad($i, 2, '0', STR_PAD_LEFT) . ' : ' . $marketName, 'light_red') . chr(10);
                $i++;
            }
            echo chr(10);
        }

        // Boucle pour appeler tous les scripts de récupération des orderBooks
        $i=1;
        foreach ($this->_marketList as $marketName) {

            // Traitement de la récupération des orderBooks
            if (! in_array($marketName, $listOrderBookProcessActiv)) {
                $networkInterface = $this->selectInterface();
                $cde = 'php -f botSaveOrderBookAux.php ' . $marketName . ' ' . $networkInterface;
                echo $this->_colorCli->getColor(' ' . str_pad($i, 2, '0', STR_PAD_LEFT) . ' : ' . $cde, 'light_blue') . chr(10);
                exec($cde . ' &> /dev/null &');
                $i++;
            }
        }

        // Suppression des tables obsolètes
        $this->deleteTables();
    }


    /**
     * Sauvegarde d'un orderBook avec le process auxiliaire
     */
    public function saveOrderBook($marketName, $networkInterface)
    {
        // Récupération de la liste des tables de market en BDD
        // Ainsi, seuls les markets de plus de 250 BTC seront analysés
        $this->marketList();

        // Récupération du PID du process courant
        $pid = getmypid();

        // Sauvegarde du pid en BDD
        $exchange = $this->_exchange;

        $req = "INSERT INTO pid_pool (plateforme, service, market, pid, date_crea) VALUES ('$exchange', 'orderBook', '$marketName', $pid, NOW())";
        $sql = $this->_dbh2->query($req);

        // Requête qui supprime le suivi du process dans la table PID si abandonné ou terminé
        $req_del = "DELETE FROM pid_pool WHERE pid = " . $pid;

        $curlOptInterface = array(CURLOPT_INTERFACE => $networkInterface);

        // Récupération de l'orderBook
        $getOrderBook = \cryptos\api\poloniex\getOrderBook::getOrderBook(strtoupper($marketName), $curlOptInterface);

        // Problème avec l'API, on kill le process et on nettoie pid_pool
        if ($getOrderBook === false) {
            $this->_dbh2->query($req_del);
            return;
        }

        // Nom de la table pour stocker l'orderBook
        $tableName = $this->tableOrderBookName($marketName);

        // Vérification de l'existence de la table en BDD
        $this->checkExistTable($tableName);

        // On vérifie si l'orderBook a changé depuis le denier enregistrement
        $req_check = "SELECT jsonOrderBook FROM $tableName ORDER BY id DESC LIMIT 1";
        $sql_check = $this->_dbh->query($req_check);

        // Dernier Json enregistré
        $oldJson = '';
        if ($sql_check->rowCount() > 0) {
            $res_check  = $sql_check->fetch();
            $oldJson    = $res_check->jsonOrderBook;
        }

        // Nouveau Json
        $newJson = json_encode($getOrderBook);

        // Requete d'ajout
        $date_crea = date('Y-m-d H:i:s');
        if ($oldJson != $newJson) {
            $req = "INSERT INTO $tableName (marketName, jsonOrderBook, date_crea) VALUES (:marketName, :jsonOrderBook, :date_crea)";
            $sql = $this->_dbh->prepare($req);
            $sql->execute(array(
                'marketName'        => $marketName,
                'jsonOrderBook'     => $newJson,
                'date_crea'         => $date_crea,
            ));

            $lastInsertId = $this->_dbh->lastInsertId();

            // Suppression des entrées dépassant le nombre d'heures de conservation des données
            $rotateDelta = $this->_rotateDelta;
            $rotateUnite = $this->_rotateUnite;

            // Rotate : suppression des entrées trop anciennes
            $lastDateCrea = mb_substr($date_crea, 0, 17);
            $req = "DELETE FROM         $tableName
                    WHERE               (date_crea LIKE '$lastDateCrea%' AND id <> $lastInsertId)
                    OR                  date_crea < DATE_ADD(NOW(), INTERVAL -$rotateDelta $rotateUnite)";
            $sql = $this->_dbh->query($req);
        }

        // Nettoyage de pid_pool
        $this->_dbh2->query($req_del);

        // Fermeture des instances PDO
        // \core\dbSingleton::closeInstance($this->_nameBDD);
        // \core\dbSingleton::closeInstance($this->_nameExBDD);

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
        if (count($this->_obTableList) == 0 || ! in_array($tableName, $this->_obTableList)) {
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
        // Création d'une table d'orderBook'
        $req = <<<eof
            SET SQL_MODE  = "NO_AUTO_VALUE_ON_ZERO";

            CREATE TABLE `___TABLE_NAME___` (
                `id`                int(11)         NOT NULL,
                `marketName`        varchar(20)     NOT NULL,
                `jsonOrderBook`     mediumtext      NOT NULL,
                `date_crea`         datetime        NOT NULL
                ) ENGINE=MyISAM DEFAULT CHARSET=utf8;


            ALTER TABLE `___TABLE_NAME___`
            ADD PRIMARY KEY             (`id`),
            ADD         KEY `date_crea` (`date_crea`);

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
                $tableList[] = "'" . $this->tableOrderBookName($market) . "'";
            }

            $tableList = implode(', ', $tableList);

            $prefixeOrderBookTable = $this->_prefixeOrderBookTable;
            $bddExchange = $this->_nameExBDD;

            // Récupération de la liste des tables obsolètes
            $req = "SELECT  table_name AS exTable

                    FROM    information_schema.tables

                    WHERE   table_name NOT IN ($tableList)
                    AND     table_name LIKE ('$prefixeOrderBookTable%')
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
    private function tableOrderBookName($marketName)
    {
        return $this->_prefixeOrderBookTable . str_replace('-', '_', strtolower($marketName));
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
        $texte .= $this->_colorCli->getColor(' Sauvegarde : orderBooks', 'white') . chr(10) . chr(10);
        $texte .= $this->_colorCli->getColor(' ' . date('Y-m-d H:i:s'), 'yellow');

        // Durées d'exécution --------------------------------------------------
        $texte .= $hr;
        $texte .= $this->_colorCli->getColor(' Durée : ' . round(($this->_timeEnd - $this->_timeInit), 3) . 's', 'light_gray') . chr(10) . chr(10);

        echo $texte;
    }
}
