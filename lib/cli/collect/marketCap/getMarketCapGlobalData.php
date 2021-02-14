<?php
namespace cryptos\cli\collect\marketCap;

/**
 * Récupération des informations liés au marketCap pour chaque devises
 * stocké par jour sur une longue période
 *
 * @author Daniel Gomes
 */
class getMarketCapGlobalData
{
    /**
     * Attributs
     */
    private $_dbh;                                      // Instance PDO
    private $_bdd = 'cryptos_marketCap';                // Nom de la BDD

    private $_networkInterface;                         // Sélection d'une IP différente à chaque appel


    /**
     * Constructeur
     */
    public function __construct()
    {
        // Instance PDO
        $this->_dbh  = \core\dbSingleton::getInstance($this->_bdd);

        // Récupération de la liste des tables de global marketCap en BDD
        $this->tableList();
    }


    public function init()
    {
        // Récupération de la liste des devises à traiter
        $req = "SELECT id_market FROM devises_marketCap";
        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {

            $id_market = $res->id_market;

            echo $id_market . ' | ';

            // Vérification de l'existence de la table
            $checkExistTable = $this->checkExistTable($id_market);

            $d = new \DateTime();
            $d->setTimestamp(time() - 86400);                   // 86400 =  nb de secondes dans une journée
            $yesterdayDate = $d->format('Ymd');

            if ($checkExistTable == 'create') {

                $url = "https://coinmarketcap.com/currencies/$id_market/historical-data/?start=20100101&end=$yesterdayDate";
                $dataSet = $this->curlData($url);

                if (count($dataSet) > 0 && count($dataSet[0]) > 1) {
                    $this->newInsertMarketCap($id_market, $dataSet);
                }

            } else {

                $table  = $this->tableName($id_market);

                // Récupération de la dernière date enregistrée
                $reqChk = "SELECT date_cloture FROM $table ORDER BY id DESC LIMIT 0,1";
                $sqlChk = $this->_dbh->query($reqChk);

                if ($sqlChk->rowCount() > 0) {

                    $resChk = $sqlChk->fetch();

                    $d = new \DateTime($resChk->date_cloture);
                    $lastDateTimestamp = $d->getTimestamp();
                    $d->setTimestamp($lastDateTimestamp + 86400);   // 86400 =  nb de secondes dans une journée
                    $dateDeb = $d->format('Ymd');

                    // Il y a au moins une journée à rattraper, on ajoute l'entrée
                    if ($dateDeb <= $yesterdayDate) {

                        $url = "https://coinmarketcap.com/currencies/$id_market/historical-data/?start=$dateDeb&end=$yesterdayDate";
                        $dataSet = $this->curlData($url);

                        if (count($dataSet) > 0 && count($dataSet[0]) > 1) {
                            $this->newInsertMarketCap($id_market, $dataSet);
                        }
                    }
                }
            }
        }
    }


    /**
     * Sauvegarde des nouvelles entrées
     */
    private function newInsertMarketCap($id_market, $dataSet)
    {
        // Inversion du tableau pour entrée les données les plus ancienne en premier
        $dataSet = array_reverse($dataSet);

        // Nom table
        $table  = $this->tableName($id_market);

        // Requête d'ajout
        $req = "INSERT INTO $table
                ( date_cloture,  open,  high,  low,  close,  volume,  marketCap, date_crea)
                VALUES
                (:date_cloture, :open, :high, :low, :close, :volume, :marketCap, NOW())";
        $sql = $this->_dbh->prepare($req);

        foreach ($dataSet as $entry) {

            $sql->execute(array(
                ':date_cloture' => $entry['date'],
                ':open'         => $entry['open'],
                ':high'         => $entry['high'],
                ':low'          => $entry['low'],
                ':close'        => $entry['close'],
                ':volume'       => $entry['volume'],
                ':marketCap'    => $entry['marketCap'],
            ));
        }
    }


    /**
     * Récupération du marketCap par jour d'une devise avec la methode cURL
     */
    private function curlData($url)
    {
        // Récupération de l'IP pour la requête cURL
        $curlOpt = $this->selectInterfaceAndUserAgent();

        echo $curlOpt[CURLOPT_INTERFACE] . chr(10);

        $html = \core\curl::curlGet($url, $curlOpt);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOBLANKS);

        $xpath = new \DOMXPath($dom);

        $req		= '//table[@class="table"]/tbody/tr';
        $entries    = $xpath->query($req);

        $i=0;
        $dataSet = array();

        foreach ($entries as $entry) {

            $j=0;
            foreach ($entry->childNodes as $child)  {

                if ($child->nodeName == 'td') {

                    switch ($j)
                    {
                        case 0 :
                            $date = trim($child->nodeValue);
                            $dataSet[$i]['date'] = $this->convertDateEn($date);
                            break;

                        case 1 :
                            $dataSet[$i]['open'] = floatval(trim($child->nodeValue));
                            break;

                        case 2 :
                            $dataSet[$i]['high'] = floatval(trim($child->nodeValue));
                            break;

                        case 3 :
                            $dataSet[$i]['low'] = floatval(trim($child->nodeValue));
                            break;

                        case 4 :
                            $dataSet[$i]['close'] = floatval(trim($child->nodeValue));
                            break;

                        case 5 :
                            $volume = trim($child->nodeValue);
                            $volume = str_replace(',', '', $volume);
                            $dataSet[$i]['volume'] = floatval($volume);
                            break;

                        case 6 :
                            $marketCap = trim($child->nodeValue);
                            $marketCap = str_replace(',', '', $marketCap);
                            $dataSet[$i]['marketCap'] = floatval($marketCap);
                            break;
                    }

                    $j++;
                }
            }

            $i++;
        }

        return $dataSet;
    }



    /**
     * Récupération de la liste des tables des marketCap 'global_%' en BDD
     */
    private function tableList()
    {
        $prefixeTable = 'global_';
        $bdd = $this->_bdd;

        $this->_tablesList = array();

        $req = "SELECT  table_name AS nameTable

                FROM    information_schema.tables

                WHERE   table_name LIKE ('$prefixeTable%')
                AND     table_schema = '$bdd'";

        $sql = $this->_dbh->query($req);

        while ($res = $sql->fetch()) {
            $this->_tablesList[] = $res->nameTable;
        }
    }


    /**
     * Si la table du marketCap globale de la devise n'existe pas, elle est créée
     *
     * @param       string      $id_market          Nom de la devise
     */
    private function checkExistTable($id_market)
    {
        $tableMarketCap = $this->tableName($id_market);

        if (! in_array($tableMarketCap, $this->_tablesList)) {

            $req = $this->tableStructure($tableMarketCap);
            $sql = $this->_dbh->query($req);

            return 'create';
        }

        return 'exist';
    }

    /**
     * Template pour les créations de tables
     *
     * @param       string      $tableMarketCap     Nom de la table à créer
     * @return      string
     */
    private function tableStructure($tableMarketCap)
    {
        $req = <<<eof
            SET SQL_MODE  = "NO_AUTO_VALUE_ON_ZERO";

            CREATE TABLE `___TABLE_NAME___` (
            `id`                int(11)         NOT NULL,
            `date_cloture`      date            NOT NULL,
            `open`              decimal(16,8)   NULL,
            `high`              decimal(16,8)   NULL,
            `low`               decimal(16,8)   NULL,
            `close`             decimal(16,8)   NULL,
            `volume`            decimal(20,0)   NULL,
            `marketCap`         decimal(20,0)   NULL,
            `date_crea`         datetime        NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;

            ALTER TABLE `___TABLE_NAME___`
            ADD PRIMARY KEY                 (`id`);

            ALTER TABLE `___TABLE_NAME___`
            MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
eof;

        return str_replace('___TABLE_NAME___', $tableMarketCap, $req);
    }


    private function tableName($id_market)
    {
        return 'global_' . str_replace('-', '_', $id_market);
    }


    private function convertDateEn($date)
    {
        $date = str_replace(',', '', $date);
        $expDate = explode(' ', $date);

        // Retour du mois en numérique
        switch ($expDate[0])
        {
            case 'Jan' : $m = '01';     break;
            case 'Feb' : $m = '02';     break;
            case 'Mar' : $m = '03';     break;
            case 'Apr' : $m = '04';     break;
            case 'May' : $m = '05';     break;
            case 'Jun' : $m = '06';     break;
            case 'Jul' : $m = '07';     break;
            case 'Aug' : $m = '08';     break;
            case 'Sep' : $m = '09';     break;
            case 'Oct' : $m = '10';     break;
            case 'Nov' : $m = '11';     break;
            case 'Dec' : $m = '12';     break;
            default    : $m = $expDate[0];
        }

        return $expDate[2] . '-' . $m . '-' . $expDate[1];
    }


    /**
     * Selecteur d'IP et de userAgent pour différencier les requêtes cURL
     */
    private function selectInterfaceAndUserAgent()
    {
        $listNetworkInterfaces = \core\config::getConfig('ipServer');
        $userAgents            = \core\config::getConfig('userAgentList');

        if (empty($this->_networkInterface)) {

            $key = 0;
            $this->_networkInterface = $listNetworkInterfaces[0];

        } else {

            if ($this->_networkInterface == end($listNetworkInterfaces)) {

                $key = 0;
                $this->_networkInterface = $listNetworkInterfaces[0];

            } else {

                $key = array_search($this->_networkInterface, $listNetworkInterfaces) + 1;
                $this->_networkInterface = $listNetworkInterfaces[$key];
            }
        }

        $curlOpt = array(
            CURLOPT_INTERFACE => $this->_networkInterface,
            CURLOPT_USERAGENT => $userAgents[$key],
        );

        return $curlOpt;
    }
}
