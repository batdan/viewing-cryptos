<?php
namespace cryptos\cli\collect\gdax;

/**
 * Récupération et stockage de l'historique de trades
 *
 * https://docs.gdax.com
 *
 * @author Daniel Gomes
 */
class marketHistoryToBdd
{
    /**
	 * Attributs
	 */
    private $_exchange      = 'gdax';                   // Nom de l'exchange

    private $_dbh;                                      // Instance PDO de la BDD de l'Exchange

    private $_nameExBDD     = 'cryptos_ex_gdax';        // Nom de la base de données de l'exchange

    private $_prefixeMarketTable    = 'market_';        // Préfixe des tables de market
    private $_prefixeMarketHistoryTable = 'mh_';        // Préfixe des tables d'orderBook

    private $_colorCli;                                 // Gestion des couleurs en interface CLI

    private $_marketList;                               // Liste des markets traités
    private $_mhTableList;                              // Liste des tables de marketHistory

    private $_lastIdTable = array();                    // Stockage à la minute : ID du dernier INSERT pour savoir s'il faut faire un UPDATE
    private $_minute      = array();                    // Stockage de la minute de démarrage du script

    private $_rotateDelta = 72;                         // Temps de conservation des données en 'unite'
    private $_rotateUnite = 'HOUR';                     // Unité pour la durée de rotation

    private $_restartActiv = 0;                         // Boolean permettant de ne redémarrer le WebSocket qu'une seule fois à la minute 59

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
        $this->_dbh = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Gestion des couleurs en interface CLI
        $this->_colorCli = new \core\cliColorText();
    }


    /**
     * Lancement du script
     */
    public function run()
    {
        // Récupération de la liste des tables de market en BDD
        $this->marketList();

        // Information de suivi du bot
        $this->infosBot();

        // Ouverture du WebSocket
        $this->websocket();
    }


    /**
     * Ensemble des actions pour récupérer et stocker les informations de chaque marketHistory
     */
    private function websocket()
    {
        $pairs = $this->_pairs;

        echo $this->_colorCli->getColor(' GDAX | marketHistory | Start WebSocket : ' . date('H:i:s'), 'light_green') . chr(10);

        $loop = \React\EventLoop\Factory::create();
        $reactConnector = new \React\Socket\Connector($loop, [
            'dns' => '8.8.8.8',
            'timeout' => 10
        ]);
        $connector = new \Ratchet\Client\Connector($loop, $reactConnector);

        // Récupération de tous les marchés pour la variable GET "streams" de wss
        $getList = array();
        foreach ($this->_marketList as $k => $v) {
            $getList[] = $k . '@aggTrade';
        }
        $getList = implode('/', $getList);

        $connector( "wss://ws-feed.gdax.com" )
        ->then(function(\Ratchet\Client\WebSocket $conn) use ($loop, $pairs) {

            // trades
            $conn->send('{
                            "type": "subscribe",
                            "product_ids": [' . $pairs . '],
                            "channels": [
                                "full",
                                "heartbeat",
                                {
                                    "name": "ticker",
                                    "product_ids": [' . $pairs . ']
                                }
                            ]
                        }');

            $conn->on('message', function(\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn, $loop) {

                // echo $msg . chr(10);

                // Permet de redémarrer le WebSocket toutes les 20 minutes
                /*
                $minutes = array('18', '38', '58');
                if (in_array(date('i'), $minutes)) {

                    if ($this->_restartActiv == 0) {

                        $this->_restartActiv = 1;

                        // On stop le WebSocket
                        echo chr(10);
                        echo $this->_colorCli->getColor(' Stop WebSocket : ' . date('H:i:s'), 'light_red') . chr(10);

                        $loop->stop();

                        // On relance le websocket
                        $this->websocket();
                    }
                } else {
                    $this->_restartActiv = 0;
                }
                */

                // Sauvegarde des marketHistory
                $this->marketHistoryToBdd($msg);
            });

            $conn->on('close', function($code=null, $reason=null) use ($loop) {

                $message = "Connection closed ({$code} - {$reason})";
                echo $this->_colorCli->getColor(' ' . $message, 'light_red') . chr(10);

                $loop->stop();

                sleep(1);

                // On relance le WebSocket en cas de fermeture
                $this->websocket();
            });

        }, function(\Exception $e) use ($loop) {

            $message = "Could not connect: {$e->getMessage()}";
            echo $this->_colorCli->getColor(' ' . $message, 'light_red') . chr(10);

            $loop->stop();

            sleep(1);

            // On relance le WebSocket en cas de fermeture
            $this->websocket();
        });

        // Lancement de la boucle du WebSocket
        $loop->run();
    }


    /**
     * Sauvegarde d'un marketHistory en BDD
     *
     * @param      json         $json           JSON du marketHistory à mettre à ajouter en BDD
     */
    public function marketHistoryToBdd($json)
    {
        $entry  = json_decode($json);

        if ($entry->type == 'match') {

            // echo json_encode($entry) . chr(10);

            // Récupération du marketName
            $marketName = $entry->product_id;

            // Nom de la table pour stocker le marketHistory
            $tableName = $this->tableMarketHistoryName($marketName);

            // Vérification de l'existence de la table en BDD
            $this->checkExistTable($tableName);

            // Informations retournées par le WebSocket
            $id_ex      = $entry->trade_id;
            $quantity   = $entry->size;
            $rate       = $entry->price;
            $total      = $quantity * $rate;

            switch( $entry->side ) {
                case 'buy'   : $orderType = 'SELL';  break;
                case 'sell'  : $orderType = 'BUY';   break;
            }

            // Minute en cours
            $lastMinute   = date('Y-m-d H:i');
            $lastDateTime = date('Y-m-d H:i:s');

            // Minute du premier enregistrement pour cette table
            if ( !isset($this->_minute[$tableName][$orderType]) ) {
                $this->_minute[$tableName][$orderType] = $lastMinute;
            }

            if ( !isset($this->_lastIdTable[$tableName][$orderType]['id'])  ||  $this->_minute[$tableName][$orderType] != $lastMinute) {

                $this->_minute[$tableName][$orderType] = $lastMinute;

                // Requête d'insertion des achats et des ventes
                $req = "INSERT INTO $tableName  ( id_ex,  quantity,  rate,  total,  fillType,  orderType,  timestampEx,  millisecondes,  date_crea,  date_modif)
                        VALUES                  (:id_ex, :quantity, :rate, :total, :fillType, :orderType, :timestampEx, :millisecondes, :date_crea, :date_modif)";
                $sql = $this->_dbh->prepare($req);

                try {
                    $sql->execute(array(
                        ':id_ex'            => $id_ex,
                        ':quantity'         => $quantity,
                        ':rate'             => 0,
                        ':total'            => $total,
                        ':fillType'         => 'FILL',
                        ':orderType'        => $orderType,
                        ':timestampEx'      => gmdate('Y-m-d H:i:s'),
                        ':millisecondes'    => 0,
                        ':date_crea'        => $lastDateTime,
                        ':date_modif'       => $lastDateTime,
                    ));

                    $this->_lastIdTable[$tableName][$orderType] = array(
                        'id'        => $this->_dbh->lastInsertId(),
                        'quantity'  => $quantity,
                        'total'     => $total,
                    );

                } catch (\Exception $e) {
                    error_log($e);
                }

            } else {

                // Requête de mise à jour
                $req = "UPDATE          $tableName

                        SET             id_ex           = :id_ex,
                                        quantity        = :quantity,
                                        total           = :total,
                                        timestampEx     = :timestampEx,
                                        date_modif      = :date_modif

                        WHERE           id              = :id";

                $sql = $this->_dbh->prepare($req);

                $this->_lastIdTable[$tableName][$orderType]['quantity'] += $quantity;
                $this->_lastIdTable[$tableName][$orderType]['total']    += $total;

                try {
                    $sql->execute(array(
                        ':id_ex'            => $id_ex,
                        ':quantity'         => $this->_lastIdTable[$tableName][$orderType]['quantity'],
                        ':total'            => $this->_lastIdTable[$tableName][$orderType]['total'],
                        ':timestampEx'      => gmdate('Y-m-d H:i:s'),
                        ':date_modif'       => $lastDateTime,
                        ':id'               => $this->_lastIdTable[$tableName][$orderType]['id'],
                    ));

                } catch (\Exception $e) {
                    error_log($e);
                }
            }

            // Suppression des entrées dépassant le nombre d'heures de conservation des données
            $rotateDelta = $this->_rotateDelta;
            $rotateUnite = $this->_rotateUnite;

            // Rotate : suppression des entrées trop anciennes
            $req = "DELETE FROM $tableName WHERE date_crea < DATE_ADD(NOW(), INTERVAL -$rotateDelta $rotateUnite)";
            $sql = $this->_dbh->query($req);
        }
    }


    /**
     * Récupération de la liste des tables des markets 'market_%' en BDD
     */
    private function marketList()
    {
        $prefixeMarketTable         = $this->_prefixeMarketTable;
        $prefixeMarketHistoryTable  = $this->_prefixeMarketHistoryTable;

        $bddExchange = $this->_nameExBDD;

        $this->_marketList  = array();      // Tableau contenant la liste des marketName
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

            foreach ($this->_marketList as $key => $val) {
                $tableList[] = "'" . $this->tableMarketHistoryName($key) . "'";
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
     * Convertion des dates et heures en GMT 0
     * Séparation du dateTime et des millisecondes
     *
     * @param       string      $timestamp          Format dateTime + millisecondes
     * @return      array
     */
    private function convertTimestamp($timestamp)
    {
        $timestamp = floatval($timestamp);

        $millisecondes = $timestamp - floor($timestamp);
        $millisecondes = floor($millisecondes * 1000);

        $timestamp = floor($timestamp);

        // Récupération du décallage horaire pour ramener timestamp sur le timeZone UTC
        $d = new \DateTime();
        $decallageSec = $d->format('Z');

        $timestamp = $timestamp - $decallageSec;

        $dateTime = new \DateTime();
        $dateTime->setTimestamp($timestamp);

        return array(
            'dateTime'      => $dateTime->format('Y-m-d H:i:s'),
            'millisecondes' => $millisecondes,
        );
    }


    /**
     * Récupère le nom de la table associée à un marketName
     *
     * @param       string      $marketName         Nom du marketName
     */
    private function tableMarketHistoryName($marketName)
    {
        $marketName = strtolower($marketName);
        $expMarket  = explode('-', $marketName);
        $marketName = $expMarket[1] . '_' . $expMarket[0];

        return $this->_prefixeMarketHistoryTable . $marketName;
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
        // $texte .= $this->_colorCli->getColor(' Durée : ' . round(($this->_timeEnd - $this->_timeInit), 3) . 's', 'light_gray') . chr(10) . chr(10);

        echo $texte;
    }
}
