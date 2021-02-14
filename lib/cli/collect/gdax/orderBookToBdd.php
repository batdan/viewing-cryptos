<?php
namespace cryptos\cli\collect\gdax;

/**
 * Récupération et stockage de l'intégralité des ordersBooks
 *
 * https://docs.gdax.com
 *
 * @author Daniel Gomes
 */
class orderBookToBdd
{
    /**
	 * Attributs
	 */
    private $_exchange      = 'gdax';                   // Nom de l'exchange

    private $_dbh;                                      // Instance PDO de la BDD de l'Exchange

    private $_nameExBDD     = 'cryptos_ex_gdax';        // Nom de la base de données de l'exchange

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

    private $_saveOrderBook = array();                  // Stockage de l'orderBook pour ne pas avoir à le récupérer à chaque boucle
    private $_saveOrderBookDateTime = array();          // Stockage de l'heure du dernier enregistrement pour n'avoir qu'une entrée par minute
    private $_saveOrderBookLastId = array();            // Stockage de l'id du dernier enregistrement

    private $_last = array();                           // Stockage des derniers prix (last) / market

    private $_poolChange = array();                     // Création d'un pool de change pour limiter le nombre de requêtes à 1 par seconde
    private $_changeSeconde = array();                  // Stockage de la minute en cours

    private $_pairs = '"BTC-USD", "BTC-EUR", "BTC-GBP",
                       "BCH-USD", "BCH-BTC", "BCH-EUR",
                       "ETH-USD", "ETH-BTC", "ETH-EUR",
                       "LTC-USD", "LTC-BTC", "LTC-EUR"';


    /**
	 * Constructeur
	 */
	public function __construct()
	{
        // Instance PDO de la BDD de l'Exchange
        $this->_dbh  = \core\dbSingleton::getInstance($this->_nameExBDD);

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

        // Lancement d'un websocket gérant les diffs. pour tous les marchés
        $this->websocket();
    }


    /**
     * Ensemble des actions pour récupérer et stocker les informations de chaque orderBook
     */
    private function websocket()
    {
        $pairs = $this->_pairs;

        echo $this->_colorCli->getColor(' GDAX | orderBook | Start WebSocket : ' . date('H:i:s'), 'light_green') . chr(10);

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

        $connector( "wss://ws-feed.gdax.com" )
        ->then(function(\Ratchet\Client\WebSocket $conn) use ($loop, $pairs) {

            // orderBook
            $conn->send('{
                "type": "subscribe",
                "product_ids": [' . $pairs . '],
                "channels": [
                    "level2",
                    "ticker",
                    "heartbeat"
                ]
            }');

            $conn->on('message', function(\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn, $loop) {

                // Permet de redémarrer le WebSocket toutes les 10 minutes
                // $minutes = range(0, 59, 1);
                // if (in_array(intval(date('i')), $minutes)) {
                //
                //     if ($this->_restartActiv == 0) {
                //
                //         $this->_restartActiv = 1;
                //
                //         // On stop le WebSocket
                //         echo chr(10);
                //         echo $this->_colorCli->getColor(' Stop WebSocket : ' . date('H:i:s'), 'light_red') . chr(10);
                //
                //         $loop->stop();
                //
                //         // On vide les cumuls de diffs
                //         $this->_poolChange = array();
                //         $this->_saveOrderBook = array();
                //
                //         // On relance le websocket
                //         $this->websocket();
                //     }
                // } else {
                //     $this->_restartActiv = 0;
                // }

                $odb = json_decode($msg);

                // Créa / Mise à jour des orderBooks
                $this->majOrderBook($odb);
            });

            $conn->on('close', function($code=null, $reason=null) use ($loop) {

                $message =  "Connection closed ({$code} - {$reason})";
                echo $this->_colorCli->getColor(' ' . $message, 'light_red') . chr(10);

                $loop->stop();

                // On vide les cumuls de diffs
                $this->_poolChange = array();
                $this->_saveOrderBook = array();

                // On relance le websocket
                $this->websocket();
            });

        }, function(\Exception $e) use ($loop) {

            $message = "Could not connect: {$e->getMessage()}";
            echo $this->_colorCli->getColor(' ' . $message, 'light_red') . chr(10);

            $loop->stop();

            // On vide les cumuls de diffs
            $this->_poolChange = array();
            $this->_saveOrderBook = array();

            sleep(1);

            // On relance le websocket
            $this->websocket();
        });

        // Lancement de la boucle du WebSocket
        $loop->run();
    }


    /**
     * Traitement du flux retourné par le WebSocket
     *
     * @param       array        $odb       Flux de l'ancien ordersBook
     */
    private function majOrderBook($odb)
    {
        // OrderBook complet
        if ($odb->type == 'snapshot') {
            $this->majOrderBookCrea($odb);
        }

        // OrderBook diff.
        if ($odb->type == 'l2update') {
            $this->majOrderBookDiff($odb);
        }

        // Stockage des derniers prix des markets
        if ($odb->type == 'ticker') {
            $marketName = $odb->product_id;
            $this->_last[$marketName] = $odb->price;
        }
    }


    /**
     * Récupération des diffs. d'orderBook avec la méthode WebSocket
     *
     * @param       array       $entry       OrderBook Complet
     */
    private function majOrderBookCrea($entry)
    {
        // Mise en forme du json pour qu'il respecte un standard multiplateformes
        $getOrderBook = $this->JsonOrderBookModel($entry);

        // Récupération du marketName
        $marketName = $entry->product_id;

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
        $minute    = date('Y-m-d H:i');

        if ($oldJson != $newJson && $newJson != '[]') {
            $req = "INSERT INTO $tableName (marketName, jsonOrderBook, date_crea) VALUES (:marketName, :jsonOrderBook, :date_crea)";
            $sql = $this->_dbh->prepare($req);
            $sql->execute(array(
                'marketName'        => $marketName,
                'jsonOrderBook'     => $newJson,
                'date_crea'         => $date_crea,
            ));

            $lastInsertId = $this->_dbh->lastInsertId();

            // Stockage de l'orderBook
            $this->_saveOrderBook[$marketName]          = $newJson;
            $this->_saveOrderBookDateTime[$marketName]  = $minute;
            $this->_saveOrderBookLastId[$marketName]    = $lastInsertId;

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
    }


    /**
     * Récupération des diffs. d'orderBook avec la méthode WebSocket
     *
      * @param       array       $entry       OrderBook Diff.
     */
    private function majOrderBookDiff($entry)
    {
        if (! isset($entry->product_id) || ! isset($this->_last[$entry->product_id])) {
            return;
        }

        // Récupération du marketName
        $marketName = $entry->product_id;

        // Changes / diff à merger avec l'orderBook
        $changes = $entry->changes;

        // Seconde actuelle
        $seconde = date('H:i:s');

        // Empilage de diff. de cette seconde pour ce market
        if (! isset($this->_poolChange[$marketName][$seconde])) {
            $this->_poolChange[$marketName][$seconde] = array();
        }

        $this->_poolChange[$marketName][$seconde][] = $changes;

        // $this->_poolChange[$marketName][$seconde] = array_merge($this->_poolChange[$marketName][$seconde], $changes);

        $this->execAllDiffs();
    }


    /**
     * Sauvegarde en Base de données des cumuls de diff. par seconde
     */
    private function execAllDiffs()
    {
        $seconde = date('H:i:s');

        // Boucle sur les markets contenant des enregistrements
        foreach ($this->_poolChange as $marketName => $val) {

            // Nom de la table pour stocker l'orderBook
            $tableName = $this->tableOrderBookName($marketName);

            // Vérification de l'existence de la table en BDD
            $this->checkExistTable($tableName);

            // Boucle sur les enregistrement d'un market / seconde
            foreach ($val as $secondeDiffs => $changeExec) {

                // On a changé de seconde, on execute les modifications en attente
                if ($secondeDiffs != $seconde) {

                    // Mise en forme des diff. "bids" e58t "asks"
                    $bidsDiffs = array();
                    $asksDiffs = array();

                    $i=0;
                    $j=0;
                    foreach($changeExec as $val) {
                        if ($val[0] == 'buy') {
                            $rate = strval($val[1]);
                            $bidsDiffs[$i][0] = $rate;
                            $bidsDiffs[$i][1] = $val[2];
                            $i++;
                        }
                        if ($val[0] == 'sell') {
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
            $bidsOrderBookFormat["$rate"] = strval($val[1]);
        }

        // Mise en forme du diff des bids
        $bidsDiffsFormat = array();
        if (count($bidsDiffs) > 0) {
            foreach ($bidsDiffs as $val) {
                $rate = number_format($val[0], 8, '.', '');
                $bidsDiffsFormat["$rate"] = strval($val[1]);
            }
        }

        // Passage du tableau des asks en key/val (rate/qty)
        $asksOrderBookFormat = array();
        foreach ($orderBook->asks as $val) {
            $rate = number_format($val[0], 8, '.', '');
            $asksOrderBookFormat["$rate"] = strval($val[1]);
        }

        // Mise en forme du diff des asks
        $asksDiffsFormat = array();
        if (count($asksDiffs) > 0) {
            foreach ($asksDiffs as $val) {
                $rate = number_format($val[0], 8, '.', '');
                $asksDiffsFormat["$rate"] = strval($val[1]);
            }
        }

        // Merge et triage des bids
        if (count($bidsDiffs) > 0) {
            $bidsOrderBookFormat = array_merge($bidsOrderBookFormat, $bidsDiffsFormat);
            krsort($bidsOrderBookFormat);
        }

        // Récupération du bid le plus haut
        // $bidsOrderBookFormatKeys = array_keys($bidsOrderBookFormat);
        // $bidMaxRate = end($bidsOrderBookFormatKeys);

        // Merge et triage des asks
        if (count($asksDiffs) > 0) {
            $asksOrderBookFormat = array_merge($asksOrderBookFormat, $asksDiffsFormat);
            ksort($asksOrderBookFormat);
        }

        // Récupération du ask le plus bas
        // $asksOrderBookFormatKeys = array_keys($asksOrderBookFormat);
        // $askMinRate = $asksOrderBookFormatKeys[0];

        // Remise en forme du tableau des bids
        $bidsOrderBook = array();
        $i=0;
        foreach ($bidsOrderBookFormat as $k => $v) {
            // if ($v==0 || $k > $this->_last[$marketName]) {
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
            // if ($v==0 || $k < $this->_last[$marketName]) {
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
        $marketName = strtolower($marketName);
        $expMarket  = explode('-', $marketName);
        $marketName = $expMarket[1] . '_' . $expMarket[0];

        return $this->_prefixeOrderBookTable . $marketName;
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
            $orderBookStd['bids'][$key][0] = $val[0];
            $orderBookStd['bids'][$key][1] = $val[1];
        }

        foreach ($getOrderBook->asks as $key => $val) {
            $orderBookStd['asks'][$key][0] = $val[0];
            $orderBookStd['asks'][$key][1] = $val[1];
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

        echo $texte;
    }
}
