<?php
namespace cryptos\scanners;

/**
 * Récupération de la liste des exchanges
 *
 * @author Daniel Gomes
 */
class exchanges
{
    public static function getExchangesList()
    {
        // Instance PDO
        $dbh = \core\dbSingleton::getInstance();

        $exchangesList = array();

        $req = "SELECT name FROM exchanges ORDER BY name ASC";
        $sql = $dbh->query($req);

        while ($res = $sql->fetch()) {
            $exchangesList[] = $res->name;
        }

        return $exchangesList;
    }
}
