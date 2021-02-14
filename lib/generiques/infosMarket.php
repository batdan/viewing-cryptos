<?php
namespace cryptos\generiques;

/**
 * Récupération des informations d'un market à afficher dans la navbar
 *
 * @author Daniel Gomes
 */
class infosMarket
{
    /**
     * Méthode cURL pour la création du tableau de données
     * Compilation des données déportée sur le serveur de collecte
     */
    public static function getInfosCurl($exchange, $marketName, $userId=null, $ipPublic=null, $userAgent=null, $demoTourTime=null, $httpHost=null)
    {
        // Récupération urls des serveurs de collecte
        $apiServers = \core\config::getConfig('apiServers');

        // Path du webservice
        $urlCurl = $apiServers[$exchange] . '/generiques/infosMarket.php';

        $postFields = array(
            'exchange'      => $exchange,
            'marketName'    => $marketName,
            'userId'        => $userId,
            'ipPublic'      => $ipPublic,
            'userAgent'     => $userAgent,
            'demoTourTime'  => $demoTourTime,
            'httpHost'      => $httpHost,
        );

        return \core\curl::curlPost($urlCurl, $postFields);
    }


    /**
     * Récupération des informations liées au market à afficher dans la navbar
     */
    public static function getInfos($exchange, $marketName, $userId=null, $ipPublic=null, $userAgent=null, $demoTourTime=null, $httpHost=null, $curlMethod=false)
    {
        // Nom de la base de données de l'Exchange
        $nameExBDD = 'cryptos_ex_' . $exchange;

        // Instance PDO
        $dbh = \core\dbSingleton::getInstance($nameExBDD);

        // Nom de la table du market
        $tableMarket = 'market_' . strtolower( str_replace('-', '_', $marketName) );

        $req = "SELECT * FROM $tableMarket ORDER BY id DESC LIMIT 2";
        $sql = $dbh->query($req);

        if ($sql->rowCount() < 2) {

            return json_encode(array(
                'result' => false,
            ));

        } else {

            $resAll = $sql->fetchAll();
            $res0 = $resAll[0];
            $res1 = $resAll[1];

            $expMarket = explode('-', $marketName);
            $cryptoRef = $expMarket[0];

            switch ($cryptoRef)
            {
                case 'USD'  : $decimals = 2;     $cryptoRefSymbol = '<span style="font-weight:bold;">$</span>';         $cryptoRefSymbol2 = '$';    break;
                case 'USDT' : $decimals = 2;     $cryptoRefSymbol = '<span style="font-weight:bold;">$</span>';         $cryptoRefSymbol2 = '$';    break;
                case 'EUR'  : $decimals = 2;     $cryptoRefSymbol = '<span style="font-weight:bold;">€</span>';         $cryptoRefSymbol2 = '€';    break;
                case 'BTC'  : $decimals = 8;     $cryptoRefSymbol = '<i class="fab fa-btc" aria-hidden="true"></i>';    $cryptoRefSymbol2 = '฿';    break;
                default     : $decimals = 8;     $cryptoRefSymbol = $cryptoRef;                                         $cryptoRefSymbol2 = '';
            }

            if      ($res0->last < $res1->last) { $classLast = 'infosMarketLibDown';    }
            elseif  ($res0->last > $res1->last) { $classLast = 'infosMarketLibUp';      }
            else                                { $classLast = 'infosMarketLib';        }

            if      ($res0->bid < $res1->bid)   { $classBid = 'infosMarketLibDown';     }
            elseif  ($res0->bid > $res1->bid)   { $classBid = 'infosMarketLibUp';       }
            else                                { $classBid = 'infosMarketLib';         }

            if      ($res0->ask < $res1->ask)   { $classAsk = 'infosMarketLibDown';     }
            elseif  ($res0->ask > $res1->ask)   { $classAsk = 'infosMarketLibUp';       }
            else                                { $classAsk = 'infosMarketLib';         }

            if      ($res0->baseVolume < $res1->baseVolume)     { $classBaseVolume = 'infosMarketLibDown';     }
            elseif  ($res0->baseVolume > $res1->baseVolume)     { $classBaseVolume = 'infosMarketLibUp';       }
            else                                                { $classBaseVolume = 'infosMarketLib';         }

            // Gestion du timer du compte de démontration
            $demoAccountCheck = '';
            if (! is_null($userId) && $userId == 21) {
                $demoAccountCheck = self::demoAccountCheck($userId, $ipPublic, $userAgent, $demoTourTime, $httpHost, $curlMethod);
            }

            return json_encode(array(

                'result'    => true,

                'last'      => '<strong>' . self::formatNumber($res0->last, $decimals) . '</strong> ' . $cryptoRefSymbol,
                'lastTitle' => self::formatNumber($res0->last, $decimals) . $cryptoRefSymbol2,
                'bid'       => '<strong>' . self::formatNumber($res0->bid, $decimals)  . '</strong> ' . $cryptoRefSymbol,
                'ask'       => '<strong>' . self::formatNumber($res0->ask, $decimals)  . '</strong> ' . $cryptoRefSymbol,
                'baseVolume'=> '<strong>' . self::formatNumber($res0->baseVolume, 0)   . '</strong> ' . $cryptoRefSymbol,

                'classLast'         => $classLast,
                'classBid'          => $classBid,
                'classAsk'          => $classAsk,
                'classBaseVolume'   => $classBaseVolume,
                'demoAccountCheck'  => $demoAccountCheck,
            ));
        }
    }


    /**
     * Vérification de l'utilisation du compte de démonstration
     * Limitation en temps à la journée
     */
    private static function demoAccountCheck($userId, $ipPublic, $userAgent, $demoTourTime, $httpHost, $curlMethod)
    {
        // Instance PDO
        if ($curlMethod === true) {
            if ($httpHost == 'www.cryptoview.io') {
                $dbh = \core\dbSingleton::getInstance('cryptoview_prod');
            } else {
                $dbh = \core\dbSingleton::getInstance('cryptoview_preprod');
            }
        } else {
            $dbh = \core\dbSingleton::getInstance();
        }

        // On vérifie si cet utilisateur c'est déjà connecté et le temps déjà consommé aujourd'hui
        $req = "SELECT * FROM logs_connect_demo WHERE ip_public = :ip_public AND DATE_FORMAT(date_crea, '%Y-%m-%d') = CURDATE()";
        $sql = $dbh->prepare($req);
        $sql->execute(array( ':ip_public' => $ipPublic));

        if ($sql->rowCount() == 0) {

            $req_add = "INSERT INTO logs_connect_demo (ip_public, browser_os, seconds, nb_connection, date_crea, date_modif) VALUES (:ip_public, :browser_os, 0, 1, NOW(), NOW())";
            $sql_add = $dbh->prepare($req_add);
            $sql_add->execute(array(
                ':ip_public'  => $ipPublic,
                ':browser_os' => $userAgent,
            ));

        } else {

            $res = $sql->fetch();

            if ($res->seconds >= $demoTourTime || $res->nb_connection >= 3) {

                return array('msg' => 'demoEndTime');

            } else {

                // Il ne pourra pas venir plus de 2 jours sur les 30 derniers jours
                $req2 = "SELECT     COUNT(DISTINCT(DATE_FORMAT(date_crea, '%Y-%m-%d'))) AS count_days
                         FROM       logs_connect_demo
                         WHERE      ip_public = :ip_public
                         AND        date_crea >= DATE_ADD(NOW(), INTERVAL -30 DAY)";

                $sql2 = $dbh->prepare($req2);
                $sql2->execute(array( ':ip_public' => $ipPublic));
                $res2 = $sql2->fetch();

                if ($res2->count_days > 2) {

                    return array('msg' => 'demoEndTime');

                } else {

                    $req_maj = "UPDATE      logs_connect_demo

                                SET         seconds         = :seconds,
                                            date_modif      = NOW()

                                WHERE       id              = :id";

                    $sql_maj = $dbh->prepare($req_maj);
                    $sql_maj->execute(array(
                        ':seconds'  => $res->seconds + 1,
                        ':id'       => $res->id,
                    ));

                    return array('msg' => 'demoOk');
                }
            }
        }
    }


    /**
     * Formatage des nombres avec décimals
     */
    private function formatNumber($number, $decimals)
    {
        $number= round($number, $decimals);
        $number= number_format($number, $decimals, '.', ' ');

        return $number;
    }
}
