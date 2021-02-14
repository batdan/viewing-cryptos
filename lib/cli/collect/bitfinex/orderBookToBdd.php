<?php
namespace cryptos\cli\collect\bitfinex;

/**
 * Récupération et stockage de l'intégralité des ordersBooks
 *
 * https://docs.bitfinex.com
 *
 * @author Daniel Gomes
 */
class orderBookToBdd
{
    /**
	 * Attributs
	 */
    private $_exchange      = 'bitfinex';               // Nom de l'exchange

    private $_dbh;                                      // Instance PDO de la BDD de l'Exchange
    private $_dbh_wss;                                  // Instance PDO de la BDD contenant les locks table

    private $_nameExBDD     = 'cryptos_ex_bitfinex';    // Nom de la base de données de l'exchange

    private $_activCurlRest = true;

    private $_prefixeMarketTable    = 'market_';        // Préfixe des tables de market
    private $_prefixeOrderBookTable = 'ob_';            // Préfixe des tables d'orderBook

    private $_colorCli;                                 // Gestion des couleurs en interface CLI

    private $_marketList;                               // Liste des markets traités
    private $_obTableList;                              // Liste des tables d'orderBook

    private $_rotateDelta   = 12;                       // Temps de conservation des données en 'unite'
    private $_rotateUnite   = 'HOUR';                   // Unité pour la durée de rotation

    private $_listNetworkInterfaces;                    // Liste des interfaces réseau disponibles sur le serveur pour requêter
    private $_currentNetworkInterface;                  // Stockage de l'interface réseau courante pour changer le tour suivant

    private $_restartActiv = 0;                         // Boolean permettant de ne redémarrer le WebSocket qu'une seule fois à la minute 59
    private $_lastRestart  = 0;                         //

    private $_saveOrderBook = array();                  // Stockage de l'orderBook pour ne pas avoir à le récupérer à chaque boucle
    private $_saveOrderBookDateTime = array();          // Stockage de l'heure du dernier enregistrement pour n'avoir qu'une entrée par minute
    private $_saveOrderBookLastId = array();            // Stockage de l'id du dernier enregistrement

    private $_last = array();                           // Stockage des derniers prix (last) / market

    private $_poolChange = array();                     // Création d'un pool de change pour limiter le nombre de requêtes à 1 par seconde
    private $_changeSeconde = array();                  // Stockage de la minute en cours

    private $_market = array();                         // Infos market : id, nom de la pair


    /**
	 * Constructeur
	 */
	public function __construct()
	{
        // Instance PDO de la BDD de l'Exchange
        $this->_dbh  = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Instance PDO de la BDD contenant les API à surveiller
        $this->_dbh_wss = \core\dbSingleton::getInstance('cryptos_pool');

        // Gestion des couleurs en interface CLI
        $this->_colorCli = new \core\cliColorText();

        // Liste des IP disponibles sur le serveur pour requêter
        $this->_listNetworkInterfaces = \core\config::getConfig('ipServer');
    }


    /**
     * Execution du script de lancement
     */
    public function run()
    {
        // Récupération de la liste des tables de market en BDD
        $this->marketList();

        // Information de suivi du bot
        $this->infosBot();

        // Démarrage de la collecte
        $this->startCollect();
    }


    /**
     * Redémarrage du WebSocket
     * @param   object   $loop      \React\EventLoop\Factory
     */
    private function startCollect($loop = null)
    {
        if (!is_null($loop)) {
            $loop->stop();

            // On vide les cumuls de diffs
            $this->_poolChange      = array();
            $this->_saveOrderBook   = array();
        }

        // Appel en REST pour la récupération de tous les orderBook complets
        if ($this->_activCurlRest === true) {
            $this->callRestMethod();
            sleep(1);
        }

        // On relance le websocket
        $this->websocket();
    }


    /**
     * Récupération de tous les orderBooks en REST
     */
    private function callRestMethod()
    {
        // Sauvegarde du pid en BDD
        $exchange = $this->_exchange;

        $i=1;
        foreach ($this->_marketList as $marketName => $marketNameStd) {

            $networkInterface = $this->selectInterface();

            $cde = 'php -f botSaveOrderBookAux.php ' . $marketName . ' ' . $networkInterface;
            echo $this->_colorCli->getColor(' ' . str_pad($i, 2, '0', STR_PAD_LEFT) . ' : ' . $cde, 'light_blue') . chr(10);
            exec($cde . ' &> /dev/null &');

            $i++;
        }
    }


    /**
     * Récupération de tous les orderBooks en REST - méthode auxiliaire
     *
     * @param       string      $marketName                     Nom du market
     * @param       string      $networkInterface               IP de l'interface réseau
     */
    public function callRestMethodAux($marketName, $networkInterface)
    {
        $curlOptInterface = array(CURLOPT_INTERFACE => $networkInterface);

        // Récupération de l'orderBook
        $getOrderBook = \cryptos\api\bitfinex\getOrderBook::getOrderBook(strtoupper($marketName), $curlOptInterface);

        // Problème avec l'API, on stop le process
        if ($getOrderBook === false) {
            return;
        }

        // Mise en forme du json pour qu'il respecte un standard multiplateformes
        $getOrderBook = $this->JsonOrderBookModel($getOrderBook);

        // Nom de la table pour stocker l'orderBook
        $tableName = $this->tableOrderBookName($marketName);

        // Vérification de l'existence de la table en BDD
        $this->checkExistTable($tableName);

        $this->lockTable($tableName, 1);

        // On récupère la date et heure du denier enregistrement
        $req = "SELECT id, date_crea FROM $tableName ORDER BY id DESC LIMIT 1";
        $sql = $this->_dbh->query($req);

        // Dernier Json enregistré
        if ($sql->rowCount() > 0) {
            $res         = $sql->fetch();
            $lastId      = $res->id;
            $oldDateCrea = substr($res->date_crea, 0, -3);
        }

        // Nouveau Json
        $newJson = json_encode($getOrderBook);

        // Requete d'ajout
        $date_crea = date('Y-m-d H:i:s');

        // Minute actuelle
        if (date('Y-m-d H:i') == $oldDateCrea) {

            $req = "UPDATE      $tableName
                    SET         jsonOrderBook = :jsonOrderBook,
                                date_crea     = NOW()
                    WHERE       id            = :id";

            $sql = $this->_dbh->prepare($req);
            $sql->execute(array(
                ':jsonOrderBook'    => $newJson,
                ':id'               => $lastId,
            ));

        } else {

            $req = "INSERT INTO $tableName (marketName, jsonOrderBook, date_crea) VALUES (:marketName, :jsonOrderBook, :date_crea)";
            $sql = $this->_dbh->prepare($req);
            $sql->execute(array(
                'marketName'        => $marketName,
                'jsonOrderBook'     => $newJson,
                'date_crea'         => $date_crea,
            ));

            $lastId = $this->_dbh->lastInsertId();
        }

        $this->lockTable($tableName, 0);

        // Suppression des entrées dépassant le nombre d'heures de conservation des données
        $rotateDelta = $this->_rotateDelta;
        $rotateUnite = $this->_rotateUnite;

        // Rotate : suppression des entrées trop anciennes
        $lastDateCrea = mb_substr($date_crea, 0, 17);
        $req = "DELETE FROM $tableName WHERE date_crea < DATE_ADD(NOW(), INTERVAL -$rotateDelta $rotateUnite)";

        $sql = $this->_dbh->query($req);
    }


    /**
     * Ensemble des actions pour récupérer et stocker les informations de chaque orderBook
     */
    private function websocket()
    {
        echo $this->_colorCli->getColor(' Bitfinex | orderBook | Start WebSocket : ' . date('H:i:s'), 'light_green') . chr(10);

        // Récupération de tous les markets
        $pairs = summariesToBdd::pairs();
        // $pairs = array('btcusd');

        $loop = \React\EventLoop\Factory::create();
        $reactConnector = new \React\Socket\Connector($loop, [
            'dns' => '8.8.8.8',
            'timeout' => 10
        ]);
        $connector = new \Ratchet\Client\Connector($loop, $reactConnector);

        // Récupération de tous les marchés pour la variable GET "streams" de wss
        $getList = array();
        foreach ($this->_marketList as $k => $v) {
            $getList[] = $k . '@depth';
        }
        $getList = implode('/', $getList);

        // $connector( "wss://api.bitfinex.com/ws/2" )
        $connector( "wss://api.bitfinex.com/ws/1" )
        ->then(function(\Ratchet\Client\WebSocket $conn) use ($loop, $pairs) {

            // orderBook
            //$jsonSend = '{"event": "subscribe", "channel": "book", "freq": "F0", "pair": "t___pair___", "len": 1}';
            $jsonSend = '{"event": "subscribe", "channel": "book", "freq": "F0", "pair": "___pair___", "len": 100}';

            foreach ($pairs as $pair) {
                $jsonSendPair = str_replace('___pair___', strtoupper($pair), $jsonSend);
                $conn->send($jsonSendPair);
            }

            $conn->on('message', function(\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn, $loop) {

                // echo $msg . chr(10);

                // Permet de redémarrer le WebSocket toutes les 1 minutes
                // if ($this->_lastRestart == 0) {
                //     $this->_lastRestart = date('H:i');
                // }
                //
                // if ($this->_lastRestart != date('H:i')) {
                //     $this->_lastRestart = date('H:i');
                //
                //     // On stop le WebSocket
                //     echo chr(10);
                //     echo $this->_colorCli->getColor(' Stop WebSocket : ' . date('H:i:s'), 'light_red') . chr(10);
                //
                //     $this->startCollect($loop);
                // }

                // Créa / Mise à jour des orderBooks
                $this->majOrderBook($msg);
            });

            $conn->on('close', function($code=null, $reason=null) use ($loop) {

                $message = "Connection closed ({$code} - {$reason})";
                echo $this->_colorCli->getColor(' ' . $message, 'light_red') . chr(10);

                $this->startCollect($loop);
            });

        }, function(\Exception $e) use ($loop) {

            $message = "Could not connect: {$e->getMessage()}";
            echo $this->_colorCli->getColor(' ' . $message, 'light_red') . chr(10);

            $this->startCollect($loop);
        });

        // Lancement de la boucle du WebSocket
        $loop->run();
    }


    /**
     * Traitement du flux retourné par le WebSocket
     *
     * @param       json        $json       Flux de l'ancien ordersBook
     */
    private function majOrderBook($json)
    {
        $json = json_decode($json);

        // Récupération du channel Id du market pour l'interprétation des résultats
        if (is_object($json) && $json->event == 'subscribed') {
            $this->_market[$json->chanId] = $json->pair;
        } else {

            if (is_array($json) && $json[1] != 'hb') {

                $chanId = $json[0];
                $marketName = $this->_market[$chanId];

                // Premier retour contenant les 100 derniers update
                if (is_array($json[1])) {

                    foreach ($json[1] as $diff) {
                        $this->orderBookDiff($marketName, $diff);
                    }

                // Diffs unitaires
                } else {
                    if (isset($json[2]) && isset($json[3])) {
                        $diff = [$json[1], $json[2], $json[3]];
                        $this->orderBookDiff($marketName, $diff);
                    }
                }
            }
        }
    }


    /**
     * Récupération des diffs. d'orderBook avec la méthode WebSocket
     *
     * @param   array   $diff       Flux de l'ordersBook
     */
    private function orderBookDiff($marketName, $diff)
    {
        // Nom de la table pour stocker l'orderBook
        $tableName = $this->tableOrderBookName($marketName);

        // On vérifie si la table est vérrouillée
        $req = "SELECT      id
                FROM        lock_table
                WHERE       exchange    = :exchange
                AND         table_name  = :table_name
                AND         lock_activ  = 1";
        $sql = $this->_dbh_wss->prepare($req);
        $sql->execute(array(
            ':exchange'     => $this->_exchange,
            ':table_name'   => $tableName
        ));

        if ($sql->rowCount() == 1) {
            return;
        }

        // Vérification de l'existence de la table en BDD
        $this->checkExistTable($tableName);

        // Minute actuelle
        $minute = date('Y-m-d H:i');

        // Récupération du dernier enregistrement s'il n'existe pas dans l'attribut '$this->_saveOrderBook'
        if (! isset($this->_saveOrderBook[$marketName])) {

            $req_last = "SELECT id, jsonOrderBook, DATE_FORMAT(date_crea, '%Y-%m-%d %H:%i') AS lastDateCrea FROM $tableName ORDER BY id DESC LIMIT 1";
            $sql_last = $this->_dbh->query($req_last);

            // Dernier Json enregistré
            if ($sql_last->rowCount() > 0) {

                $res_last = $sql_last->fetch();

                $this->_saveOrderBook[$marketName]          = $res_last->jsonOrderBook;
                $this->_saveOrderBookLastId[$marketName]    = $res_last->id;
                $this->_saveOrderBookDateTime[$marketName]  = $minute;

            } else {

                $networkInterface = $this->selectInterface();
                $this->callRestMethodAux($marketName, $networkInterface);
                return;
            }
        }

        // Seconde actuelle
        $seconde = date('H:i:s');

        // Mise dans une forme stantard des changements (bids et asks cumulés)
        $changes = array();

        $rate   = $diff[0];
        $count  = $diff[1];
        $amount = $diff[2];

        if ($amount > 0) {
            $side = 'bids';
        } elseif ($amount < 0) {
            $side = 'asks';
            $amount = $amount * -1;
        }

        if ($count == 0 && $amount == 1) {
            $side = 'bids';
            $amount = 0;
        }

        if ($count == 0 && $amount == -1) {
            $side = 'asks';
            $amount = 0;
        }

        // Format : array('bids|asks', RATE, AMOUNT)
        // $changes[] = array($side, $rate, $amount);;

        // Empilage de diff. de cette seconde pour ce market
        if (! isset($this->_poolChange[$marketName][$seconde])) {
            $this->_poolChange[$marketName][$seconde] = array();
        }

        $this->_poolChange[$marketName][$seconde][] = array($side, $rate, $amount);;

        // $this->_poolChange[$marketName][$seconde] = array_merge($this->_poolChange[$marketName][$seconde], $changes);

        $this->execAllDiffs($marketName);
    }


    /**
     * Sauvegarde en Base de données des cumuls de diff. par seconde
     *
     * @param       string      $marketNameDiff         Nom du market qui est à l'origine de l'éxecution des diffs
     */
    private function execAllDiffs($marketNameDiff)
    {
        $seconde = date('H:i:s');

        // Boucle sur les markets contenant des enregistrements
        foreach ($this->_poolChange as $marketName => $val) {

            // Nom de la table pour stocker l'orderBook
            $tableName = $this->tableOrderBookName($marketName);

            // On ne gère que les markets ayant la même 1ère lettre de monnaie traidée pour étaler les requêtes
            $tde = $this->CryptoRefTde($marketName);
            $tde = $tde['tde'];

            $tdeDiff = $this->CryptoRefTde($marketNameDiff);
            $tdeDiff = $tdeDiff['tde'];

            if (substr($tde, 0, 1) != substr($tdeDiff, 0, 1)) {
                continue;
            }

            // Boucle sur les enregistrement d'un market / seconde
            foreach ($val as $secondeDiffs => $changeExec) {

                // On a changé de seconde, on execute les modifications en attente
                if ($secondeDiffs != $seconde) {

                    // Mise en forme des diff. "bids" et "asks"
                    $bidsDiffs = array();
                    $asksDiffs = array();

                    $i=0;
                    $j=0;
                    foreach($changeExec as $val) {
                        if ($val[0] == 'bids') {

                            $rate = strval($val[1]);
                            $bidsDiffs[$i][0] = $rate;
                            $bidsDiffs[$i][1] = $val[2];
                            $i++;
                        }
                        if ($val[0] == 'asks') {
                            $rate = strval($val[1]);
                            $asksDiffs[$j][0] = $rate;
                            $asksDiffs[$j][1] = $val[2];
                            $j++;
                        }
                    }

                    // Minute actuelle
                    $minute = date('Y-m-d H:i');

                    // Mise à jour du JSON
                    $majOrderBook = $this->jsonUpdate($this->_saveOrderBook[$marketName], $bidsDiffs, $asksDiffs, $marketName);

                    // Mise à jour
                    if ($minute == $this->_saveOrderBookDateTime[$marketName]) {
                        $req = "UPDATE      $tableName
                                SET         jsonOrderBook = :jsonOrderBook,
                                            date_crea     = NOW()
                                WHERE       id            = :id";

                        $sql = $this->_dbh->prepare($req);
                        $sql->execute(array(
                            ':jsonOrderBook'    => $majOrderBook,
                            ':id'               => $this->_saveOrderBookLastId[$marketName],
                        ));

                        $this->_saveOrderBook[$marketName] = $majOrderBook;

                    // Nouvelle entrée
                    } else {

                        $req = "INSERT INTO $tableName (marketName, jsonOrderBook, date_crea) VALUES (:marketName, :jsonOrderBook, NOW())";
                        $sql = $this->_dbh->prepare($req);
                        $sql->execute(array(
                            ':marketName'       => $marketName,
                            ':jsonOrderBook'    => $majOrderBook,
                        ));

                        $this->_saveOrderBook[$marketName]          = $majOrderBook;
                        $this->_saveOrderBookDateTime[$marketName]  = $minute;
                        $this->_saveOrderBookLastId[$marketName]    = $this->_dbh->lastInsertId();
                    }

                    // Rotate : suppression des entrées trop anciennes
                    $rotateDelta = $this->_rotateDelta;
                    $rotateUnite = $this->_rotateUnite;

                    $req = "DELETE FROM $tableName WHERE date_crea < DATE_ADD(NOW(), INTERVAL -$rotateDelta $rotateUnite)";
                    $sql = $this->_dbh->query($req);

                    unset($this->_poolChange[$marketName][$secondeDiffs]);
                }
            }
        }
    }


    /**
     * Mise à jour d'un orderBook avec un diff.
     *
     * @param       object      $orderBook          Objet contenant l'ancien orderBook
     * @param       array       $bidsDiffs          Tableau du diff des bids
     * @param       array       $asksDiffs          Tableau du diff des asks
     * @param       string      $marketName         Nom du market
     *
     * @return      json
     */
    private function jsonUpdate($orderBook, $bidsDiffs, $asksDiffs, $marketName)
    {
        $orderBook = json_decode($orderBook);

        // Passage du tableau des bids en key/val (rate/qty)
        $bidsOrderBookFormat = array();
        foreach ($orderBook->bids as $val) {
            $rate = number_format($val[0], 8, '.', '');
            $bidsOrderBookFormat["$rate"] = $val[1];
        }

        // Mise en forme du diff des bids
        $bidsDiffsFormat = array();
        if (count($bidsDiffs) > 0) {
            foreach ($bidsDiffs as $val) {
                $rate = number_format($val[0], 8, '.', '');
                $bidsDiffsFormat["$rate"] = $val[1];
            }
        }

        // Passage du tableau des asks en key/val (rate/qty)
        $asksOrderBookFormat = array();
        foreach ($orderBook->asks as $val) {
            $rate = number_format($val[0], 8, '.', '');
            $asksOrderBookFormat["$rate"] = $val[1];
        }

        // Mise en forme du diff des asks
        $asksDiffsFormat = array();
        if (count($asksDiffs) > 0) {
            foreach ($asksDiffs as $val) {
                $rate = number_format($val[0], 8, '.', '');
                $asksDiffsFormat["$rate"] = $val[1];
            }
        }

        // Merge et triage des bids
        $bidsOrderBookFormat = array_merge($bidsOrderBookFormat, $bidsDiffsFormat);
        krsort($bidsOrderBookFormat);

        // Récupération du bid le plus haut
        $bidsOrderBookFormatKeys = array_keys($bidsOrderBookFormat);
        $bidMaxRate = end($bidsOrderBookFormatKeys);

        // Merge et triage des asks
        $asksOrderBookFormat = array_merge($asksOrderBookFormat, $asksDiffsFormat);
        ksort($asksOrderBookFormat);

        // Récupération du ask le plus bas
        $asksOrderBookFormatKeys = array_keys($asksOrderBookFormat);
        $askMinRate = $asksOrderBookFormatKeys[0];

        // Récupération du dernier prix pour ce market
        // $last = $this->recupLast($marketName);
        //
        // if ($last !== false) {
        //     $limitPriceAsk = $last;
        //     $limitPriceBid = $last;
        // } else {
        //     $limitPriceAsk = $askMinRate;
        //     $limitPriceBid = $bidMaxRate;
        // }

        // Remise en forme du tableau des bids
        $bidsOrderBook = array();
        $i=0;
        foreach ($bidsOrderBookFormat as $k => $v) {
            // if ($v==0 || $k > $limitPriceAsk) {
            if ($v==0) {
                continue;
            }
            $bidsOrderBook[$i][0] = strval($k);
            $bidsOrderBook[$i][1] = strval($v);
            $i++;
        }

        // Remise en forme du tableau des asks
        $asksOrderBook = array();
        $i=0;
        foreach ($asksOrderBookFormat as $k => $v) {
            // if ($v==0 || $k < $limitPriceBid) {
            if ($v==0) {
                continue;
            }
            $asksOrderBook[$i][0] = strval($k);
            $asksOrderBook[$i][1] = strval($v);
            $i++;
        }

        // Compilation des bids et des asks
        $majOrderBook = array(
            'bids' => $bidsOrderBook,
            'asks' => $asksOrderBook,
        );

        $majOrderBook = json_encode($majOrderBook);

        return $majOrderBook;
    }


    /**
     * Récupération du dernier prix pour ce Market
     */
    private function recupLast($marketName)
    {
        // On essai dans un premier temps de récupérer le last avec WebSocket
        if (isset($this->_last[$marketName])) {

            // if ($marketName == 'BTCUSD') {
            //     echo $marketName;
            //     echo chr(10);
            //     echo $marketName . ' ' .$this->_last[$marketName];
            //     echo chr(10);
            // }

            return $this->_last[$marketName];

        } else {

            // Si le last n'existe pas encore en WebSocket, on tente de le récupérer dans la table du market
            $tableMarket = $this->tableMarket($marketName);

            $req = "SELECT last FROM $tableMarket ORDER BY id DESC LIMIT 1";
            $sql = $this->_dbh->query($req);

            if ($sql->rowCount() > 0) {
                $res = $sql->fetch();
                return $res->last;

            } else {

                return false;
            }
        }
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

        echo $req;
        echo chr(10);
        echo chr(10);

        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {

            $expTable = explode('_', $res->exTable);

            if ($expTable[0] == 'ob') {

                // Récupération de la liste des tables d'orderBook
                $this->_obTableList[] = $res->exTable;

            } else {

                // Récupération de la liste de marketName
                $marketName     = $expTable[2] . $expTable[1];
                $marketNameStd  = $expTable[1] . '-' . $expTable[2];

                $this->_marketList[$marketName] = $marketNameStd;
            }
        }
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

            foreach ($this->_marketList as $key => $val) {
                $tableList[] = "'" . $this->tableOrderBookName($key) . "'";
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
     * Récupère le nom de la table associée à l'ordersBook
     *
     * @param       string      $marketName         Nom du marketName
     */
    private function tableOrderBookName($marketName)
    {
        return summariesToBdd::tableMarketName($marketName, $this->_prefixeOrderBookTable);
    }


    /**
     * Récupère le nom de la table associée à un marketName
     *
     * @param       string      $marketName         Nom du marketName
     */
    private function tableMarket($marketName)
    {
        return summariesToBdd::tableMarketName($marketName, $this->_prefixeMarketTable);
    }


    /**
     * Récupère le nom de la monnaie de référence et le nom de la monnaie tradée
     */
    private function CryptoRefTde($marketName)
    {
        return array(
            'ref' => mb_substr($marketName, -3, 3),
            'tde' => mb_substr($marketName, 0, 3),
        );
    }


    /**
     * Mise en forme du json pour qu'il respecte un standard multiplateformes
     *
     * {"bids":[["4410.00000000", 0.49794082], == {"Bids":[[rate, quantity],
     * {"asks":[["4410.00000000", 0.49794082], == {"asks":[[rate, quantity],
     *
     * @param       string      $getOrderBook       OrderBook retournée par l'API
     */
    private function JsonOrderBookModel($getOrderBook)
    {
        $orderBookStd = array();

        foreach ($getOrderBook->bids as $key => $val) {
            $orderBookStd['bids'][$key][0] = $val->price;
            $orderBookStd['bids'][$key][1] = $val->amount;
        }

        foreach ($getOrderBook->asks as $key => $val) {
            $orderBookStd['asks'][$key][0] = $val->price;
            $orderBookStd['asks'][$key][1] = $val->amount;
        }

        return $orderBookStd;
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

        $texte .= $hr;
        $texte .= chr(10) . chr(10);

        echo $texte;
    }


    /**
     * Lock la table durant la méthode REST
     * @param  string   $tableName      Nom de la table
     * @param  integer  $lockActiv      value : 0|1
     */
    private function lockTable($tableName, $lockActiv)
    {
        $req = "SELECT  id
                FROM    lock_table
                WHERE   exchange    = :exchange
                AND     table_name  = :table_name";
        $sql = $this->_dbh_wss->prepare($req);
        $sql->execute(array(
            ':exchange'     => $this->_exchange,
            ':table_name'   => $tableName
        ));

        if ($sql->rowCount() == 1) {
            $req = "UPDATE  lock_table
                    SET     lock_activ  = :lock_activ
                    WHERE   exchange    = :exchange
                    AND     table_name  = :table_name";
            $sql = $this->_dbh_wss->prepare($req);
            $sql->execute(array(
                ':exchange'     => $this->_exchange,
                ':table_name'   => $tableName,
                ':lock_activ'   => $lockActiv
            ));
        } else {
            $req = "INSERT INTO lock_table  ( exchange,  table_name,  lock_activ)
                    VALUES                  (:exchange, :table_name, :lock_activ)";
            $sql = $this->_dbh_wss->prepare($req);
            $sql->execute(array(
                ':exchange'     => $this->_exchange,
                ':table_name'   => $tableName,
                ':lock_activ'   => $lockActiv
            ));
        }
    }
}
