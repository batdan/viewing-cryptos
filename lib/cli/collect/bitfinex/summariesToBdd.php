<?php
namespace cryptos\cli\collect\bitfinex;

/**
 * Récupération et stockage de l'intégralité des markets GDAX
 *
 * https://docs.bitfinex.com
 *
 * @author Daniel Gomes
 */
class summariesToBdd
{
    /**
	 * Attributs
	 */
    private $_exchange      = 'bitfinex';               // Nom de l'exchange

    private $_dbh;                                      // Instance PDO de la BDD de l'Exchange

    private $_nameExBDD     = 'cryptos_ex_bitfinex';    // Nom de la base de données de l'exchange
    private static $_prefixeTable  = 'market_';         // Préfixe des tables de market

    private $_colorCli;                                 // Gestion des couleurs en interface CLI

    private $_marketSummaries;                          // Stockage des informations sur tous les markets

    private $_tablesList;                               // Liste des tables de market en BDD
    private $_marketList;                               // Liste des tables de market en BDD

    private $_lastIdTable   = array();                  // Stockage à la minute : ID du dernier INSERT pour savoir s'il faut faire un UPDATE
    private $_minute        = array();                  // Stockage de la minute de démarrage du script

    private $_rotateTime    = 72;                       // Temps de conservation des données en heures
    private $_marketVolMin  = 100;                      // Volume minimum pour qu'un market soit traité

    private $_listNetworkInterfaces;                    // Liste des interfaces réseau disponibles sur le serveur pour requêter
    private $_currentNetworkInterface;                  // Stockage de l'interface réseau courante pour changer le tour suivant

    private $_timeInit;                                 // Permet de stocker le démarrage d'un tour pour en calculer le temps
    private $_timeEnd;                                  // Permet de stocker le démarrage d'un tour pour en calculer le temps

    private $_timeCurlInit;                             // Permet de stocker le démarrage d'un tour pour en calculer le temps
    private $_timeCurlEnd;                              // Permet de stocker le démarrage d'un tour pour en calculer le temps

    private $_restartActiv = 0;                         // Boolean permettant de ne redémarrer le WebSocket qu'une seule fois à la minute 59

    private $_market = array();                         // Infos market : id, nom de la pair

    private static $_autorizeRefMarket = array(         // Permet de ne pas collecter les marchés non rattachés à ces monnaies
        'btc',
        'usd'
    );


    /**
	 * Constructeur
	 */
	public function __construct()
	{
        // Instance PDO de la BDD de l'Exchange
        $this->_dbh  = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Gestion des couleurs en interface CLI
        $this->_colorCli = new \core\cliColorText();
    }


    /**
     * Boucle permettant de récupérer les données des markets chaque seconde
     */
    public function run()
    {
        // Récupération de la liste des tables de market en BDD
        $this->tableList();

        // Information de suivi du bot
        $this->infosBot();

        // Ouverture du WebSocket
        $this->websocket();
    }


    /**
     * Ensemble des actions pour récupérer et stocker les informations de chaque market
     */
    public function websocket()
    {
        echo $this->_colorCli->getColor(' Bitfinex | Summaries | Start WebSocket : ' . date('H:i:s'), 'light_green') . chr(10);

        // Récupération de tous les markets
        $pairs = self::pairs();
        //$pairs = array('btcusd');

        // Début de la boucle
        $loop = \React\EventLoop\Factory::create();

        $reactConnector = new \React\Socket\Connector($loop, [
            'dns' => '8.8.8.8',
            'timeout' => 10
        ]);
        $connector = new \Ratchet\Client\Connector($loop, $reactConnector);

        // $connector('wss://api-pub.bitfinex.com/ws/2')
        $connector('wss://api.bitfinex.com/ws/1')
        ->then(function(\Ratchet\Client\WebSocket $conn) use ($loop, $pairs) {

            // tickers
            $jsonSend = '{"event": "subscribe", "channel": "ticker", "pair": "___pair___"}';
            // $jsonSend = '{"event": "subscribe", "channel": "ticker", "symbol": "t___pair___"}';

            foreach ($pairs as $pair) {
                $jsonSendPair = str_replace('___pair___', strtoupper($pair), $jsonSend);
                // echo $jsonSendPair . chr(10);

                $conn->send($jsonSendPair);
            }

            $conn->on('message', function(\Ratchet\RFC6455\Messaging\MessageInterface $msg) use ($conn, $loop) {

                // echo $msg . chr(10) . chr(10);

                // Permet de redémarrer le WebSocket toutes les 20 minutes
                /*
                $minutes = array('08', '28', '48');
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

                // Création et suppression des tables de market si nécessaire
                $this->wssInfos($msg);
            });

            $conn->on('close', function($code=null, $reason=null) use ($loop) {

                $message = "Connection closed ({$code} - {$reason})";
                echo $this->_colorCli->getColor(' ' . $message, 'light_red') . chr(10);

                $loop->stop();

                sleep(1);

                // On relance le websocket
                $this->websocket();
            });

        }, function(\Exception $e) use ($loop) {

            $message = "Could not connect: {$e->getMessage()}\n";
            echo $this->_colorCli->getColor(' ' . $message, 'light_red') . chr(10);

            $loop->stop();

            sleep(1);

            // On relance le websocket
            $this->websocket();
        });

        // Lancement de la boucle du WebSocket
        $loop->run();
    }


    /**
     * Récupération des marchés adossés à des monnaies de référence autorisées
     * @return array
     */
    public static function pairs()
    {
        $pairs = \cryptos\api\bitfinex\getMarket::getSymbols();
        $pairs = array_keys($pairs);

        $cleanPairs = array();

        foreach ($pairs as $pair) {
            if (strlen($pair) == 6) {
                if (in_array(substr($pair, -3), self::$_autorizeRefMarket)) {
                    $cleanPairs[] = $pair;
                }
            } else {
                foreach (self::$_autorizeRefMarket as $ref) {
                    if (in_array(substr($pair, -strlen($ref)), self::$_autorizeRefMarket)) {
                        $cleanPairs[] = $pair;
                    }
                }
            }
        }

        return $cleanPairs;
    }


    private function wssInfos($json)
    {
        $json = json_decode($json);

        // Récupération de du channel Id du market pour l'interprétation des résultats
        if (is_object($json) && $json->event == 'subscribed') {
            $this->_market[$json->chanId] = $json->pair;
        }

        if (is_array($json) && $json[1] != 'hb') {
            $this->majBdd($json);
        }
    }

    /**
     * Sauvegarde des informations de chaque market dans sa table respective
     *
     * @param      json         $json           JSON contenant tous les marketSummaries à sauvegarder en BDD
     */
    private function majBdd($json)
    {
        $chanId = $json[0];
        $marketName = $this->_market[$chanId];

        // API V2
        // $high           = $json[1][8];
        // $low            = $json[1][9];
        // $open           = $json[1][6];
        // $last           = $json[1][6];
        //
        // $volume         = $json[1][7];
        // $baseVolume     = $volume * $last;
        // $bid            = $json[1][0];
        // $ask            = $json[1][2];
        // $timestampEx    = gmdate('Y-m-d H:i:s');
        // $openBuyOrders  = null;
        // $openSellOrders = null;
        // $prevDay        = null;

        // API V1
        $high           = $json[9];
        $low            = $json[10];
        $open           = $json[7];
        $last           = $json[7];

        $volume         = $json[8];
        $baseVolume     = $volume * $last;
        $bid            = $json[1];
        $ask            = $json[3];
        $timestampEx    = gmdate('Y-m-d H:i:s');
        $openBuyOrders  = null;
        $openSellOrders = null;
        $prevDay        = null;


        // Vérification de l'existence de la table en BDD
        $this->checkExistTable($marketName);

        $tableMarketName = self::tableMarketName($marketName);

        // echo '$marketName : ' . $marketName . chr(10);
        // echo '$tableMarketName : ' . $tableMarketName . chr(10);

        // Minute du premier enregistrement pour cette table
        if (!isset($this->_minute[$tableMarketName])) {
            $this->_minute[$tableMarketName] = date('Y-m-d H:i');
        }

        // Minute en cours
        $lastMinute = date('Y-m-d H:i');

        if (!isset($this->_lastIdTable[$tableMarketName]['id']) || $this->_minute[$tableMarketName] != $lastMinute) {

            $this->_minute[$tableMarketName] = $lastMinute;

            // Requête d'ajout
            $req = "INSERT INTO $tableMarketName
                        ( marketName,  volume,  baseVolume,  high,  low,  open,  last,  bid,  ask,  openBuyOrders,  openSellOrders,  prevDay,  timestampEx,  millisecondes, date_crea, date_modif)
                    VALUES
                        (:marketName, :volume, :baseVolume, :high, :low, :open, :last, :bid, :ask, :openBuyOrders, :openSellOrders, :prevDay, :timestampEx, :millisecondes, NOW(), NOW())";

            $sql = $this->_dbh->prepare($req);

            $sql->execute(array(
                ':marketName'       => $marketName,
                ':volume'           => $volume,
                ':baseVolume'       => $baseVolume,
                ':high'             => $high,
                ':low'              => $low,
                ':open'             => $open,
                ':last'             => $last,
                ':bid'              => $bid,
                ':ask'              => $ask,
                ':openBuyOrders'    => $openBuyOrders,
                ':openSellOrders'   => $openSellOrders,
                ':prevDay'          => $prevDay,
                ':timestampEx'      => $timestampEx,
                ':millisecondes'    => null,
            ));

            $this->_lastIdTable[$tableMarketName]['id']     = $this->_dbh->lastInsertId();
            $this->_lastIdTable[$tableMarketName]['high']   = $high;
            $this->_lastIdTable[$tableMarketName]['low']    = $low;

        } else {

            // Requête de mise à jour
            $req = "UPDATE          $tableMarketName

                    SET             volume          = :volume,
                                    baseVolume      = :baseVolume,
                                    high            = :high,
                                    low             = :low,
                                    last            = :last,
                                    bid             = :bid,
                                    ask             = :ask,
                                    openBuyOrders   = :openBuyOrders,
                                    openSellOrders  = :openSellOrders,
                                    prevDay         = :prevDay,
                                    timestampEx     = :timestampEx,
                                    date_modif      = NOW()

                    WHERE           id              = :id";

            $sql = $this->_dbh->prepare($req);

            // Calcul du high
            if ($last > $this->_lastIdTable[$tableMarketName]['high']) {
                $this->_lastIdTable[$tableMarketName]['high'] = $last;
            }

            // Calcul du low
            if ($last < $this->_lastIdTable[$tableMarketName]['low']) {
                $this->_lastIdTable[$tableMarketName]['low'] = $last;
            }

            $sql->execute(array(
                ':volume'           => $volume,
                ':baseVolume'       => $baseVolume,
                ':high'             => $this->_lastIdTable[$tableMarketName]['high'],
                ':low'              => $this->_lastIdTable[$tableMarketName]['low'],
                ':last'             => $last,
                ':bid'              => $bid,
                ':ask'              => $ask,
                ':openBuyOrders'    => $openBuyOrders,
                ':openSellOrders'   => $openSellOrders,
                ':prevDay'          => $prevDay,
                ':timestampEx'      => $timestampEx,
                ':id'               => $this->_lastIdTable[$tableMarketName]['id'],
            ));
        }

        // Suppression des entrées dépassant le nombre d'heures de conservation des données
        $rotateTime = '-' . $this->_rotateTime;

        $req = "DELETE FROM $tableMarketName WHERE date_crea < DATE_ADD(NOW(), INTERVAL $rotateTime HOUR)";
		$sql = $this->_dbh->query($req);

        // Suppression des tables obsolètes
        // $this->deleteTables($this->_marketList);
    }


    /**
     * Récupération de la liste des tables des markets 'market_%' en BDD
     */
    private function tableList()
    {
        $prefixeTable = self::$_prefixeTable;
        $bddExchange  = $this->_nameExBDD;

        $this->_tablesList = array();

        $req = "SELECT  table_name AS exTable

                FROM    information_schema.tables

                WHERE   table_name LIKE ('$prefixeTable%')
                AND     table_schema = '$bddExchange'";

        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {
            $this->_tablesList[] = $res->exTable;
        }
    }


    /**
     * Si la table du marketName n'existe pas, elle est créée
     *
     * @param       string      $marketName        Nom du marketName
     */
    private function checkExistTable($marketName)
    {
        $tableMarketName = self::tableMarketName($marketName);

        if (! in_array($tableMarketName, $this->_tablesList)) {
            $req = $this->tableStructure($tableMarketName);
            $sql = $this->_dbh->query($req);
        }
    }


    /**
     * Template pour les créations de tables
     *
     * @param       string      $tableMarketName    Nom de la table à créer
     * @return      string
     */
    private function tableStructure($tableMarketName)
    {
        $req = <<<eof
            SET SQL_MODE  = "NO_AUTO_VALUE_ON_ZERO";

            CREATE TABLE `___TABLE_NAME___` (
            `id`                int(11)         NOT NULL,
            `marketName`        varchar(20)     NOT NULL,
            `volume`            decimal(20,8)   NOT NULL,
            `baseVolume`        decimal(20,8)   NULL,
            `high`              decimal(16,8)   NULL,
            `low`               decimal(16,8)   NULL,
            `open`              decimal(16,8)   NULL,
            `last`              decimal(16,8)   NOT NULL,
            `bid`               decimal(16,8)   NOT NULL,
            `ask`               decimal(16,8)   NOT NULL,
            `openBuyOrders`     int(11)         NULL,
            `openSellOrders`    int(11)         NULL,
            `prevDay`           decimal(16,8)   NULL,
            `timestampEx`       datetime        NOT NULL,
            `millisecondes`     int(6)          NULL,
            `date_crea`         datetime        NOT NULL,
            `date_modif`        datetime        NULL
            ) ENGINE=MyISAM DEFAULT CHARSET=utf8;

            ALTER TABLE `___TABLE_NAME___`
            ADD PRIMARY KEY                 (`id`),
            ADD         KEY `id`            (`id`),
            ADD         KEY `last`          (`last`),
            ADD         KEY `baseVolume`    (`baseVolume`),
            ADD         KEY `timestampEx`   (`timestampEx`),
            ADD         KEY `date_crea`     (`date_crea`);

            ALTER TABLE `___TABLE_NAME___`
            MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
eof;

        return str_replace('___TABLE_NAME___', $tableMarketName, $req);
    }


    /**
     * Suppression des tables obsolètes
     *
     * @param       array       $marketList         Liste des markets
     */
    private function deleteTables($marketList)
    {
        // Mise en forme de la liste des tables pour la requête
        if (count($marketList) > 0) {

            $marketListPipe = array();

            foreach ($marketList as $market) {
                $marketListPipe[] = "'" . self::tableMarketName($market) . "'";
            }

            $marketList = implode(', ', $marketListPipe);

            $prefixeTable = self::$_prefixeTable;
            $bddExchange  = $this->_nameExBDD;

            // Récupération de la liste des tables obsolètes
            $tableList = array();

            $req = "SELECT  table_name AS exTable

            FROM    information_schema.tables

            WHERE   table_name NOT IN ($marketList)
            AND     table_name LIKE ('$prefixeTable%')
            AND     table_schema = '$bddExchange'";

            $sql = $this->_dbh->query($req);

            // Suppression
            while ($res = $sql->fetch()) {
                $this->_dbh->query("DROP TABLE " . $res->exTable);
            }
        }
    }


    /**
     * Récupère le nom de la table associée à un marketName
     *
     * @param       string      $marketName         Nom du marketName
     */
    public static function tableMarketName($marketName, $prefixe = null)
    {
        $marketName = strtolower($marketName);

        if (is_null($prefixe)) {
            $prefixe = self::$_prefixeTable;
        }

        if (strlen($marketName) == 6) {
            return $prefixe . substr($marketName, 3, 3) . '_' . substr($marketName, 0, 3);
        } else {
            if (strstr($marketName, ':')) {
                $exp = explode(':', $marketName);
                return $prefixe . $exp[1] . '_' . $exp[0];
            }
        }
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
        $texte .= $this->_colorCli->getColor(' Sauvegarde : marketSummaries', 'white') . chr(10) . chr(10);
        $texte .= $this->_colorCli->getColor(' ' . date('Y-m-d H:i:s'), 'yellow');

        // Date & Interface réseau ---------------------------------------------
        $texte .= $hr;
        // $texte .= $this->_colorCli->getColor(' Interface réseau : ' . $this->_currentNetworkInterface, 'light_cyan');

        // Durées d'exécution --------------------------------------------------
        // $texte .= $hr;
        // $texte .= $this->_colorCli->getColor(' Durée : ' . round(($this->_timeEnd - $this->_timeInit), 3) . 's', 'light_gray') . chr(10);
        // $texte .= $this->_colorCli->getColor(' Durée cURL : ' . round(($this->_timeCurlEnd - $this->_timeCurlInit), 3) . 's', 'light_gray');

        echo $texte;
    }
}
