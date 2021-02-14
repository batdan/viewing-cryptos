<?php
namespace cryptos\cli\collect\marketCap;

/**
 * Récupération des informations liés au marketCap pour chaque devises
 *
 * @author Daniel Gomes
 */
class getMarketCap
{
    /**
     * Récupération des informations par devises sur coinmarketcap.com
     */
    public static function apiMarketCap($addCurlopt=null)
    {
        $dbh = \core\dbSingleton::getInstance('cryptos_marketCap');

        $url = 'https://api.coinmarketcap.com/v1/ticker/?convert=BTC';

        $res = \core\curl::curlGet($url, $addCurlopt);
        $res = json_decode($res, true);

        if (! is_array($res)) {

            return false;

        } else {

            $deviseList = array();

            // Vérification de l'existence de l'entrée
            $reqChk = "SELECT COUNT(id) as count_id FROM devises_marketCap WHERE id_market = :id_market";
            $sqlChk = $dbh->prepare($reqChk);

            // Insertion d'une nouvelle devise
            $reqAdd = "INSERT INTO devises_marketCap
                       (
                           id_market,
                           name,
                           symbol,
                           rank,
                           price_usd,
                           price_btc,
                           24h_volume_usd,
                           market_cap_usd,
                           available_supply,
                           total_supply,
                           percent_change_1h,
                           percent_change_24h,
                           percent_change_7d,
                           last_updated,
                           24h_volume_btc,
                           market_cap_btc,
                           date_crea,
                           date_modif
                       )
                       VALUES
                       (
                           :id_market,
                           :name,
                           :symbol,
                           :rank,
                           :price_usd,
                           :price_btc,
                           :24h_volume_usd,
                           :market_cap_usd,
                           :available_supply,
                           :total_supply,
                           :percent_change_1h,
                           :percent_change_24h,
                           :percent_change_7d,
                           :last_updated,
                           :24h_volume_btc,
                           :market_cap_btc,
                           NOW(),
                           NOW()
                       )";
            $sqlAdd = $dbh->prepare($reqAdd);

            // Mise à jour d'une devise
            $reqMaj = "UPDATE       devises_marketCap

                       SET          rank                = :rank,
                                    price_usd           = :price_usd,
                                    price_btc           = :price_btc,
                                    24h_volume_usd      = :24h_volume_usd,
                                    market_cap_usd      = :market_cap_usd,
                                    available_supply    = :available_supply,
                                    total_supply        = :total_supply,
                                    percent_change_1h   = :percent_change_1h,
                                    percent_change_24h  = :percent_change_24h,
                                    percent_change_7d   = :percent_change_7d,
                                    last_updated        = :last_updated,
                                    24h_volume_btc      = :24h_volume_btc,
                                    market_cap_btc      = :market_cap_btc,
                                    date_modif          = NOW()

                       WHERE        id_market           = :id_market";
            $sqlMaj = $dbh->prepare($reqMaj);

            // Boucle sur les devises
            foreach ($res as $devise) {

                $deviseList[] = "'" . $devise['id'] . "'";

                // Vérification de l'existence de l'entrée
                $sqlChk->execute(array( ':id_market' => $devise['id'] ));
                $resChk = $sqlChk->fetch();

                print_r($devise);

                $majData = array(
                    ':id_market'            => $devise['id'],
                    ':rank'                 => $devise['rank'],
                    ':price_usd'            => $devise['price_usd'],
                    ':price_btc'            => $devise['price_btc'],
                    ':24h_volume_usd'       => $devise['24h_volume_usd'],
                    ':market_cap_usd'       => $devise['market_cap_usd'],
                    ':available_supply'     => $devise['available_supply'],
                    ':total_supply'         => $devise['total_supply'],
                    ':percent_change_1h'    => $devise['percent_change_1h'],
                    ':percent_change_24h'   => $devise['percent_change_24h'],
                    ':percent_change_7d'    => $devise['percent_change_7d'],
                    ':last_updated'         => $devise['last_updated'],
                    ':24h_volume_btc'       => $devise['24h_volume_btc'],
                    ':market_cap_btc'       => $devise['market_cap_btc'],
                    ':total_supply'         => $devise['total_supply'],
                );

                if ($resChk->count_id == 0) {

                    // Nouvelle entrée
                    $addData = array(
                        ':name'             => $devise['name'],
                        ':symbol'           => $devise['symbol'],
                    );
                    $addData = array_merge($addData, $majData);

                    $sqlAdd->execute($addData);

                } else {

                    // Mise à jour d'une entrée
                    $sqlMaj->execute($majData);
                }
            }

            // Suppression des devises n'existant plus
            $deviseListStr = implode(', ', $deviseList);

            $reqDel = "DELETE FROM devises_marketCap WHERE id_market NOT IN ($deviseListStr)";
            $sqlDel = $dbh->query($reqDel);
        }
    }


    /**
     * Récupération des données de marketCap sur ce marché
     */
    public static function getThisMarketData($exchange, $marketName)
    {
        $dataSetAll = self::getAllMarketsData($exchange, $marketName);

        $expMarket  = explode('-', $marketName);
        $cryptoRef  = $expMarket[0];
        $cryptoTde  = $expMarket[1];

        $cryptoTde  = \cryptos\graph\graphOrderBook::genericName($exchange, $cryptoTde);

        $dataSet = array();

        $i=0;
        if (count($dataSetAll) > 0) {
            foreach ($dataSetAll as $key => $val) {
                if ($val['pair'] == $cryptoRef.'-'.$cryptoTde || $val['pair'] == $cryptoTde.'-'.$cryptoRef) {

                    $dataSet[$i] = $val;
                    $dataSet[$i]['rank'] = $i + 1;

                    $i++;
                }
            }
        }

        return $dataSet;
    }


    /**
     * Récupération des données de marketCap sur tous les exchange et marchés ayant cette monnaie
     */
    public static function getAllMarketsData($exchange, $marketName)
    {
        $dbh = \core\dbSingleton::getInstance('cryptos_marketCap');

        $expMarket  = explode('-', $marketName);
        $cryptoTde  = $expMarket[1];

        $cryptoTde  = \cryptos\graph\graphOrderBook::genericName($exchange, $cryptoTde);

        $req = "SELECT id_market, symbol FROM devises_marketCap WHERE symbol = :symbol";
        $sql = $dbh->prepare($req);
        $sql->execute(array( ':symbol' => $cryptoTde ));

        if ($sql->rowCount() > 0) {

            $res = $sql->fetch();
            $id_market  = $res->id_market;
            $symbol     = $res->symbol;

            // Vérification de l'existence de en base du marketCap appelé
            $reqChk = "SELECT json FROM devises_marketCapSave WHERE symbol = :symbol AND date_modif > DATE_ADD(NOW(), INTERVAL -5 MINUTE)";
            $sqlChk = $dbh->prepare($reqChk);
            $sqlChk->execute(array( ':symbol' => $symbol ));

            if ($sqlChk->rowCount() > 0) {

                $resChk  = $sqlChk->fetch();
                $dataSet = json_decode($resChk->json, true);

            } else {

                $dataSet = self::curlAllMarketsData($dbh, $id_market, $symbol);
            }

            return $dataSet;
        }
    }


    /**
     * Récupération du marketCap d'une devise avec la methode cURL
     * Stockage du résultat en BDD
     */
    private static function curlAllMarketsData($dbh, $id_market, $symbol)
    {
        // Récupération de l'IP pour la requête cURL
        $curlOpt = self::selectInterfaceAndUserAgent($dbh);

        $url  = "https://coinmarketcap.com/currencies/$id_market/#markets";
        $html = \core\curl::curlGet($url, $curlOpt);

        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOBLANKS);

        $xpath = new \DOMXPath($dom);

        $req		= '//table[@id="markets-table"]/tbody/tr';
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
                        $dataSet[$i]['rank'] = trim($child->nodeValue);
                        break;

                        case 1 :
                        $dataSet[$i]['exchange'] = trim($child->nodeValue);
                        break;

                        case 2 :
                        $expPair = explode('/', trim($child->nodeValue));
                        $dataSet[$i]['pair'] = $expPair[1] . '-' . $expPair[0];
                        break;

                        case 3 :
                        $vol24h = trim($child->nodeValue);

                        if (substr($vol24h, 0, 2) == '**') {
                            $vol24h = trim($vol24h, '*');
                            $vol24h = trim($vol24h);
                            $vol24h = '** ' . trim($vol24h);
                        }

                        $dataSet[$i]['vol24h'] = $vol24h;
                        break;

                        case 4 :
                        $price = trim($child->nodeValue);

                        if (substr($price, 0, 1) == '*') {
                            $price = trim($price, '*');
                            $price = trim($price);
                            $price = '* ' . trim($price);
                        }

                        $dataSet[$i]['price'] = $price;
                        break;

                        case 5 :
                        $dataSet[$i]['volPct'] = trim($child->nodeValue);
                        break;

                        case 6 :
                        $dataSet[$i]['Updated'] = trim($child->nodeValue);
                        break;
                    }

                    $j++;
                }
            }

            $i++;
        }

        $reqChk = "SELECT id FROM devises_marketCapSave WHERE symbol = :symbol";
        $sqlChk = $dbh->prepare($reqChk);
        $sqlChk->execute(array( ':symbol' => $symbol ));

        if ($sqlChk->rowCount() == 0) {

            $reqAdd = "INSERT INTO devises_marketCapSave (ip, symbol, json, date_crea, date_modif) VALUES (:ip, :symbol, :json, NOW(), NOW())";
            $sqlAdd = $dbh->prepare($reqAdd);
            $sqlAdd->execute(array(
                ':ip'       => $curlOpt[CURLOPT_INTERFACE],
                ':symbol'   => $symbol,
                ':json'     => json_encode($dataSet),
            ));

        } else {

            $reqMaj = "UPDATE       devises_marketCapSave

                       SET          ip          = :ip,
                                    json        = :json,
                                    date_modif  = NOW()

                       WHERE        symbol      = :symbol";

            $sqlMaj = $dbh->prepare($reqMaj);
            $sqlMaj->execute(array(
                ':ip'       => $curlOpt[CURLOPT_INTERFACE],
                ':symbol'   => $symbol,
                ':json'     => json_encode($dataSet),
            ));

        }

        return $dataSet;
    }


    /**
     * Selecteur d'IP et de userAgent pour différencier les requêtes cURL
     */
    private static function selectInterfaceAndUserAgent($dbh)
    {
        $listNetworkInterfaces = \tools\config::getConfig('ipServer');
        $userAgents            = \tools\config::getConfig('userAgentList');

        // Récupération de la dernière IP utilisée
        $req = "SELECT ip FROM devises_marketCapSave ORDER BY id DESC LIMIT 1";
        $sql = $dbh->query($req);

        if ($sql->rowCount() == 0) {

            $key = 0;
            $networkInterface = $listNetworkInterfaces[0];

        } else {

            $res = $sql->fetch();
            $lastIP = $res->ip;

            if ($lastIP == end($listNetworkInterfaces)) {

                $key = 0;
                $networkInterface = $listNetworkInterfaces[0];

            } else {

                $key = array_search($lastIP, $listNetworkInterfaces) + 1;
                $networkInterface = $listNetworkInterfaces[$key];
            }
        }

        $curlOpt = array(
            CURLOPT_INTERFACE => $networkInterface,
            CURLOPT_USERAGENT => $userAgents[$key],
        );

        return $curlOpt;
    }
}
