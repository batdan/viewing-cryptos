<?php
namespace cryptos\trading;

/**
 * Couche d'abastration permettant d'appeler une function php5 dans un environnement php7
 * Le script est exécuté en CLI php5 et retourne un flux JSON lu par php7
 *
 * @author Daniel Gomes
 */
class cli_php5
{
    /**
     * Méthode d'appel du script exécuter en CLI php5
     *
     * @param       string      $function           Nom de la fonction a exécuter
     * @param       array       $options            Tableau contenant les paramètres de la function
     *
     * @return      mixed
     */
    public static function call($function, $options)
    {
        $varGet = array();

        // Préparation des paramètres de la fonction à faire passer dans l'appel en CLI php5
        foreach($options as $option) {
            if (is_array($option) || is_object($option)) {
                $varGet[] = "'" . json_encode($option) . "'";
            } elseif (is_numeric($option)) {
                $varGet[] = $option;
            } else {
                $varGet[] = "'" . $option . "'";
            }
        }

        $varGet = implode(' ', $varGet);

        $path_cli = '/usr/bin/php5 /var/www/vw/front/cryptoview/vendor/vw/cryptos/lib/trading/cli_php5.php';
        $cmd = $path_cli . ' ' . $function . ' ' . $varGet;

        exec($cmd, $res);

        return json_decode($res[0], true);
    }


    /**
     * Script PHP5 exectutant une function uniquement compatible avec php
     *
     * @return      json
     */
    public static function exec()
    {
        $argv = $_SERVER['argv'];
        $function = $argv[1];

        $parameters = array();

        for($i=2; $i<count($argv); $i++) {

            if (strstr($argv[$i], '[') || strstr($argv[$i], '{')) {
                $parameters[] = json_decode($argv[$i]);
            } elseif (is_numeric($argv[$i])) {
                $parameters[] = $argv[$i];
            } else {
                $parameters[] = strval($argv[$i]);
            }
        }

        switch( count($parameters) )
        {
            case 1 : $res = $function($parameters[0]);  break;
            case 2 : $res = $function($parameters[0], $parameters[1]);  break;
            case 3 : $res = $function($parameters[0], $parameters[1], $parameters[2]);  break;
            case 4 : $res = $function($parameters[0], $parameters[1], $parameters[2], $parameters[3]);  break;
            case 5 : $res = $function($parameters[0], $parameters[1], $parameters[2], $parameters[3], $parameters[4]);  break;
            case 6 : $res = $function($parameters[0], $parameters[1], $parameters[2], $parameters[3], $parameters[4], $parameters[5]);  break;
        }

        /*
        $res = array(
            'res'    => $_SERVER['argv'],
            'phpver' => mb_substr(phpversion(), 0, 1),
        );
        */

        return json_encode($res);
    }
}

// Si le script est exécuté en PHP5, la méthode exec est appelée
if (mb_substr(phpversion(), 0, 1) == 5) {
    echo cli_php5::exec();
}
