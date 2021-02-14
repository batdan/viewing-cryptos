<?php
namespace cryptos\cli\collect\bittrex\old;

/**
 * Récupération et stockage de l'intégralité des markets Bittrex
 *
 * @author Daniel Gomes
 */
class summariesToBdd
{
    /**
	 * Attributs
	 */
    private $_exchange      = 'bittrex';                // Nom de l'exchange

    private $_dbh;                                      // Instance PDO de la BDD de l'Exchange

    private $_nameExBDD     = 'cryptos_ex_bittrex';     // Nom de la base de données de l'exchange
    private $_prefixeTable  = 'market_';                // Préfixe des tables de market

    private $_getMarket;                                // Instance de la classe cryptos\bittrex\getMarket

    private $_marketSummaries;                          // Stockage des informations sur tous les markets

    private $_tablesList;                               // Liste des tables de market en BDD

    private $_lastIdTable   = array();                  // Stockage à la minute : ID du dernier INSERT pour savoir s'il faut faire un UPDATE
    private $_minute        = array();                  // Stockage de la minute de démarrage du script

    private $_rotateTime    = 72;                       // Temps de conservation des données en heures
    private $_marketVolMin  = 90;                       // Volume minimum pour qu'un market soit traité

    private $_listNetworkInterfaces;                    // Liste des interfaces réseau disponibles sur le serveur pour requêter
    private $_currentNetworkInterface;                  // Stockage de l'interface réseau courante pour changer le tour suivant

    private $_timeInit;                                 // Permet de stocker le démarrage d'un tour pour en calculer le temps
    private $_timeEnd;                                  // Permet de stocker le démarrage d'un tour pour en calculer le temps

    private $_timeCurlInit;                             // Permet de stocker le démarrage d'un tour pour en calculer le temps
    private $_timeCurlEnd;                              // Permet de stocker le démarrage d'un tour pour en calculer le temps


    /**
	 * Constructeur
	 */
	public function __construct()
	{
        // Instance PDO de la BDD de l'Exchange
        $this->_dbh  = \core\dbSingleton::getInstance($this->_nameExBDD);

        // Instance de la classe cryptos\bittrex\getMarket
        $this->_getMarket = new \cryptos\api\bittrex\getMarket();

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
            $uSleep = 1000000 - $timeExec;

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

        // Récupération de getMarketSummaries (ensemble des market)
        $this->_timeCurlInit = microtime(true);
        $this->_marketSummaries = $this->_getMarket->getMarketSummaries( $this->selectInterface() );
        $this->_timeCurlEnd  = microtime(true);

        if ($this->_marketSummaries === false || ! is_array($this->_marketSummaries)) {
            return;
        }

        // Récupération de la liste des tables de market en BDD
        $this->tableList();

        // Création et suppression des tables de market si nécessaire
        $this->majBdd();

        // Fin de la boucle
        $this->_timeEnd = microtime(true);

        // Informations de suivi du bot
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

        return  array(
            CURLOPT_INTERFACE => $this->_currentNetworkInterface,
        );
    }


    /**
     * Récupération de la liste des tables des markets 'market_%' en BDD
     */
    private function tableList()
    {
        $prefixeTable = $this->_prefixeTable;
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
     * Sauvegarde des informations de chaque market dans sa table respective
     */
    private function majBdd()
    {
        // Tableau contenant la liste des markets
        $marketList = array();

        foreach ($this->_marketSummaries as $key => $val) {

            $marketName     = $val->MarketName;
            $volume         = $val->Volume;
            $baseVolume     = $val->BaseVolume;
            $high           = $val->Last;
            $low            = $val->Last;
            $open           = $val->Last;
            $last           = $val->Last;
            $bid            = $val->Bid;
            $ask            = $val->Ask;
            $timestampEx    = gmdate('Y-m-d H:i:s');
            $openBuyOrders  = $val->OpenBuyOrders;
            $openSellOrders = $val->OpenSellOrders;
            $prevDay        = $val->PrevDay;

            // Vérification pour savoir si le volume minimum est atteint
            if ($this->checkVolume($marketName, $baseVolume, $last) !== true) {
                continue;
            }

            // Stockage du market dans un tableau pour la suppression des tables obsolètes
            $marketList[] = $marketName;

            // Vérification de l'existence de la table en BDD
            $this->checkExistTable($marketName);

            $tableMarketName = $this->tableMarketName($marketName);

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
                    ':open'              => $open,
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
        }

        // Suppression des tables obsolètes
        $this->deleteTables($marketList);
    }


    /**
     * Si la table du marketName n'existe pas, elle est créée
     *
     * @param       string      $marketName        Nom du marketName
     */
    private function checkExistTable($marketName)
    {
        $tableMarketName = $this->tableMarketName($marketName);

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
     */
    private function deleteTables($marketList)
    {
        // Mise en forme de la liste des tables pour la requête
        if (count($marketList) > 0) {

            $marketListPipe = array();

            foreach ($marketList as $market) {
                $marketListPipe[] = "'" . $this->tableMarketName($market) . "'";
            }

            $marketList = implode(', ', $marketListPipe);

            $prefixeTable = $this->_prefixeTable;
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
     * Vérification du volume de chaque market pour savoir si le sueil mini est atteint
     *
     * @param       string      $marketName         Market a vérifier
     * @param       float       $baseVolume         Volume du market
     * @param       float       $last               Montant du dernier ordre enregistré
     *
     * @return      boolean
     */
    private function checkVolume($marketName, $baseVolume, $last)
    {
        // Récupération des deux monnais de marketName
        $expMarket = explode('-', $marketName);

        // On exprime le volume en Bitcoin
        switch ($expMarket[0])
        {
            case 'USDT' : $volBTC = $baseVolume / $last;    break;
            case 'ETH'  : $volBTC = $baseVolume * $last;    break;
            default     : $volBTC = $baseVolume;
        }

        // Vérification
        if ($volBTC >= $this->_marketVolMin) {
            return true;
        }
    }


    /**
     * Convertion des dates et heures en GMT 0
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

        // $timeZone = "Europe/Paris";
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


    /**
     * Récupère le nom de la table associée à un marketName
     *
     * @param       string      $marketName         Nom du marketName
     */
    private function tableMarketName($marketName)
    {
        return $this->_prefixeTable . str_replace('-', '_', strtolower($marketName));
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

        $colorCli = new \core\cliColorText();

        $hr = chr(10) . chr(10) . '________________________________' . chr(10) . chr(10) . chr(10);
        $hr = $colorCli->getColor($hr, 'dark_gray');

        // Titre ---------------------------------------------------------------
        $texte  = chr(10);
        $texte .= $colorCli->getColor(' Exchange : ' . ucfirst($this->_exchange), 'white') . chr(10);
        $texte .= $colorCli->getColor(' Sauvegarde : marketSummaries', 'white') . chr(10) . chr(10);
        $texte .= $colorCli->getColor(' ' . date('Y-m-d H:i:s'), 'yellow');

        // Date & Interface réseau ---------------------------------------------
        $texte .= $hr;
        $texte .= $colorCli->getColor(' Interface réseau : ' . $this->_currentNetworkInterface, 'light_cyan');

        // Durées d'exécution --------------------------------------------------
        $texte .= $hr;
        $texte .= $colorCli->getColor(' Durée : ' . round(($this->_timeEnd - $this->_timeInit), 3) . 's', 'light_gray') . chr(10);
        $texte .= $colorCli->getColor(' Durée cURL : ' . round(($this->_timeCurlEnd - $this->_timeCurlInit), 3) . 's', 'light_gray');

        echo $texte;
    }
}
