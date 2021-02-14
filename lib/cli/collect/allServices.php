<?php
namespace cryptos\cli\collect;

/**
 * Lancement et surveillance des API de collecte en Websocket & REST
 *
 * @author Daniel Gomes
 */
class allServices
{
    /**
	 * Attributs
	 */
    private $_dbh;                                      // Instances PDO de la BDD des Exchanges
    private $_dbh_services;                             // Instance PDO de la BDD contenant les API à surveiller

    private $_servicesList;                             // Liste des Services

    private $_colorCli;                                 // Gestion des couleurs en interface CLI


    /**
	 * Constructeur
	 */
	public function __construct()
	{
        // Instance PDO de la BDD contenant les API à surveiller
        $this->_dbh_services = \core\dbSingleton::getInstance('cryptos_pool');

        // Création des instances PDO pour tous les Exchanges à surveiller
        $this->pdoExchangeList();

        // Gestion des couleurs en interface CLI
        $this->_colorCli = new \core\cliColorText();
    }


    /**
     * Boucle
     */
    public function run()
    {
        // CLI Only
        if (PHP_SAPI !== 'cli') {
            return;
        }

        for ($i=0; $i==$i; $i++) {

            $this->actions();

            // Attente souhaitée : 10 minutes
            sleep(120);
        }
    }


    /**
     * Liste des actions
     */
    public function actions()
    {
        // Récupération de la liste des API WebSocket à surveiller
        $this->servicesList();

        // Vérification du bon fonctionnement de chaque API
        $this->servicesCheck();
    }


    /**
     * Récupération de la liste des Services à exécuter et surveiller
     */
    public function servicesList()
    {
        $req = "SELECT * FROM services WHERE activ = 1";
        $sql = $this->_dbh_services->query($req);

        $this->_servicesList = $sql->fetchAll();
    }


    /**
     * Vérification du bon fonctionnement de chaque API
     */
    private function servicesCheck()
    {
        // Refresh page
        system('clear');

        // Affichage de la date
        $msg = $this->_colorCli->getColor(' ' . date('Y-m-d H:i:s'), 'cyan');
        echo chr(10) . $msg . chr(10) . chr(10);

        foreach ($this->_servicesList as $service) {

            $exchange   = $service->exchange;
            $table_name = $service->table_name;
            $api        = $service->api;
            $type       = $service->type;

            // Récupération de l'instance PDO
            $db_exchange = $this->_dbh[$exchange];

            // Pas de champ date_modif dans les tables d'orderBook
            $chp = 'date_modif';
            if (mb_substr($table_name, 0, 2) == 'ob') {
                $chp = 'date_crea';
            }

            // On vérifie si la table existe
            $existTable = $this->checkExistTable($db_exchange, $exchange, $table_name);

            if ($existTable == 1) {

                // On vérifie si la dernière entrée ne date pas de plus de 30 secondes
                $req2 = "SELECT id FROM $table_name WHERE $chp > DATE_ADD(NOW(), INTERVAL -30 SECOND)";
                $sql2 = $db_exchange->query($req2);

                $recent = $sql2->rowCount();
            }

            $stopStartAPI = '';

            if ($existTable == 0 || (isset($recent) && $recent == 0) || $service->forceReload == 1) {

                if ($service->forceReload == 1) {
                    $msg = "API $exchange $api : Force reload";
                    $msg = $this->_colorCli->getColor($msg, 'white');
                } else {
                    $msg = "API $exchange $api : à relancer !";
                    $msg = $this->_colorCli->getColor($msg, 'white');
                }

                // Restart de l'API
                $stopStartAPI = $this->stopStartAPI($exchange, $api);

            } else {
                $msg = "Check $exchange $api : Ok !";
                $msg = $this->_colorCli->getColor($msg, 'green');
            }

            echo ' ' . $type . ' : ' . $stopStartAPI . $msg. chr(10);
        }
    }


    /**
     * On vérifie l'existance de la table
     */
    private function checkExistTable($db_exchange, $exchange, $table_name)
    {
        $req = "SELECT * FROM information_schema.tables WHERE table_schema = :table_schema AND table_name = :table_name";
        $sql = $db_exchange->prepare($req);
        $sql->execute(array(
            ':table_schema' => 'cryptos_ex_' . $exchange,
            ':table_name'   => $table_name,
        ));

        if ($sql->rowCount() == 1) {
            return true;
        }

        return false;
    }


    /**
     * Fermeture de toutes les API en wss
     */
    public function closeAll()
    {
        // Récupération de la liste des API Services à surveiller
        $this->servicesList();

        echo chr(10);

        $i=0;
        foreach ($this->_servicesList as $service) {
            $exchange    = $service->exchange;
            $table_name  = $service->table_name;
            $api         = $service->api;
            $type        = $service->type;

            $msg1 = "STOP de l'API $exchange $api !";
            $msg1 = $this->_colorCli->getColor($msg1, 'purple');

            // Stop de l'API
            // Récupération du PID
            $cmd = "ps aux | grep '$api' | grep '$exchange' | grep -v 'kill' | awk '{ print $2 }'";
            $pidGrep = exec($cmd, $output);

            // Le process est lancé, on le kill !
            if (count($output) > 0) {
                $i++;
                foreach($output as $pid) {
                    $cmd = "kill " . $pid;
                    exec($cmd, $output);

                    $msg2 = $this->_colorCli->getColor(' ' . $cmd . ' - ', 'purple');
                    echo ' ' . $type . ' : ' . $msg2 . $msg1 . chr(10);
                }
            }

            unset($output);
        }

        if ($i==0) {
            echo $this->_colorCli->getColor(' Tous les process sont déjà fermés', 'purple');
        }

        echo chr(10) . chr(10);
    }


    /**
     * Création des instances PDO pour tous les Exchanges à surveiller
     */
    private function pdoExchangeList()
    {
        $req = "SELECT DISTINCT(exchange) FROM services";
        $sql = $this->_dbh_services->query($req);

        while ($res = $sql->fetch()) {
            $this->_dbh[ $res->exchange ] = \core\dbSingleton::getInstance( 'cryptos_ex_' . $res->exchange );
        }
    }


    /**
     * Stop & Start API WebSocket
     */
    private function stopStartAPI($exchange, $api)
    {
        // Récupération du PID
        $cmd = "ps aux | grep '$api' | grep '$exchange' | grep -v 'kill' | awk '{ print $2 }'";
        $pidGrep = exec($cmd, $output);

        $msg = '';

        // Le process est lancé, on le kill !
        if (count($output) > 0) {
            foreach($output as $pid) {
                $cmd = "kill " . $pid;
                exec($cmd, $output);

                $msg = $this->_colorCli->getColor(' ' . $cmd . ' - ', 'purple');
            }
        }

        // On relance l'API
        $cmd = "/usr/bin/php -f /var/www/vw/cryptosCollect/crypto/cli/collect/$exchange/$api $exchange  &> /dev/null &";
        exec($cmd);

        return $msg;

        // echo $cmd . chr(10) . chr(10);
    }
}
