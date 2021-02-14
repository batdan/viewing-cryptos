<?php
namespace cryptos\generiques;

/**
 * Récupération des informations de l'utilisateur
 *
 * Création des tables de configuration des scanners et des graphiques
 * à partir de template
 *
 * @author Daniel Gomes
 */
class infosUser
{
    /**
     * Récupération des informations du compte utilisateur
     */
    public static function getInfos($idUser)
    {
        $infosUser = array();

        // Instance PDO
        $dbh = \tools\dbSingleton::getInstance();

        // Récupération du profil utilisateur
        $req = "SELECT * FROM settings_users WHERE id = :id";
        $sql = $dbh->prepare($req);
        $sql->execute(array(
            ':id' => $idUser,
        ));

        if ($sql->rowCount() > 0) {

            $res = $sql->fetch();

            $infosUser['settings_user']['firstname']  = $res->firstname;
            $infosUser['settings_user']['lastname']   = $res->lastname;
            $infosUser['settings_user']['nickname']   = $res->nickname;
            $infosUser['settings_user']['email']      = $res->email;
            $infosUser['settings_user']['passwd']     = $res->passwd;
            $infosUser['settings_user']['country']    = $res->country;
            $infosUser['settings_user']['skin']       = $res->skin;
            $infosUser['settings_user']['code_affil'] = $res->code_affil;

            // Création de la configuration des graphiques à la première ouverture de Cryptoview
            if ($res->create_table_conf_charts == 0) {
                $defaultConfCharts = self::createConfCharts($idUser);

                $infosUser['settings_charts']['tdv_interval']   = $defaultConfCharts['tdv_interval'];
                $infosUser['settings_charts']['tdv_studies1']   = $defaultConfCharts['tdv_studies1'];
                $infosUser['settings_charts']['tdv_studies2']   = $defaultConfCharts['tdv_studies2'];
                $infosUser['settings_charts']['tdv_studies3']   = $defaultConfCharts['tdv_studies3'];

                $infosUser['settings_charts']['ob_range']       = $defaultConfCharts['ob_range'];

                $infosUser['settings_charts']['obv_range']      = $defaultConfCharts['obv_range'];
                $infosUser['settings_charts']['obv_interval']   = $defaultConfCharts['obv_interval'];

                $infosUser['settings_charts']['mh_interval']    = $defaultConfCharts['mh_interval'];

                $infosUser['settings_charts']['aio_tdv_int1']   = $defaultConfCharts['aio_tdv_int1'];
                $infosUser['settings_charts']['aio_tdv_int2']   = $defaultConfCharts['aio_tdv_int2'];
                $infosUser['settings_charts']['aio_tdv_int3']   = $defaultConfCharts['aio_tdv_int3'];
                $infosUser['settings_charts']['aio_tdv_int4']   = $defaultConfCharts['aio_tdv_int4'];
                $infosUser['settings_charts']['aio_tdv_studies1'] = $defaultConfCharts['aio_tdv_studies1'];
                $infosUser['settings_charts']['aio_tdv_studies2'] = $defaultConfCharts['aio_tdv_studies2'];
                $infosUser['settings_charts']['aio_tdv_studies3'] = $defaultConfCharts['aio_tdv_studies3']; 



            // Récupération de la configuration des graphiques de l'utilisateur
            } else {

                $req2 = "SELECT * FROM settings_charts WHERE id_user = :id_user";
                $sql2 = $dbh->prepare($req2);
                $sql2->execute(array(
                    ':id_user'      => $idUser,
                ));

                $res2 = $sql2->fetch();

                $infosUser['settings_charts']['tdv_interval']       = $res2->tdv_interval;
                $infosUser['settings_charts']['tdv_studies1']       = $res2->tdv_studies1;
                $infosUser['settings_charts']['tdv_studies2']       = $res2->tdv_studies2;
                $infosUser['settings_charts']['tdv_studies3']       = $res2->tdv_studies3;

                $infosUser['settings_charts']['ob_range']           = $res2->ob_range;

                $infosUser['settings_charts']['obv_range']          = $res2->obv_range;
                $infosUser['settings_charts']['obv_interval']       = $res2->obv_interval;

                $infosUser['settings_charts']['mh_interval']        = $res2->mh_interval;

                $infosUser['settings_charts']['aio_tdv_int1']       = $res2->aio_tdv_int1;
                $infosUser['settings_charts']['aio_tdv_int2']       = $res2->aio_tdv_int2;
                $infosUser['settings_charts']['aio_tdv_int3']       = $res2->aio_tdv_int3;
                $infosUser['settings_charts']['aio_tdv_int4']       = $res2->aio_tdv_int4;
                $infosUser['settings_charts']['aio_tdv_studies1']   = $res2->aio_tdv_studies1;
                $infosUser['settings_charts']['aio_tdv_studies2']   = $res2->aio_tdv_studies2;
                $infosUser['settings_charts']['aio_tdv_studies3']   = $res2->aio_tdv_studies3;


            }

            // Création de la configuration des scanners à la première ouverture de Cryptoview
            if ($res->create_table_conf_scans == 0) {
                $defaultConfScans = self::createConfScans($idUser);

                $infosUser['settings_scans']['exchange_default']    = $defaultConfScans['exchange_default'];
                $infosUser['settings_scans']['nb_result_default']   = $defaultConfScans['nb_result_default'];

                $infosUser['settings_scans']['price_period']        = $defaultConfScans['price_period'];
                $infosUser['settings_scans']['price_vol24h']        = $defaultConfScans['price_vol24h'];

                $infosUser['settings_scans']['mh_period']           = $defaultConfScans['mh_period'];
                $infosUser['settings_scans']['mh_vol24h']           = $defaultConfScans['mh_vol24h'];
                $infosUser['settings_scans']['mh_orientation']      = $defaultConfScans['mh_orientation'];

                $infosUser['settings_scans']['ob_range']            = $defaultConfScans['ob_range'];
                $infosUser['settings_scans']['ob_period']           = $defaultConfScans['ob_period'];
                $infosUser['settings_scans']['ob_vol24h']           = $defaultConfScans['ob_vol24h'];
                $infosUser['settings_scans']['ob_orientation']      = $defaultConfScans['ob_orientation'];

                $infosUser['settings_scans']['rsi_period']          = $defaultConfScans['rsi_period'];
                $infosUser['settings_scans']['rsi_vol24h']          = $defaultConfScans['rsi_vol24h'];

                $infosUser['settings_scans']['manual_vol24h']       = $defaultConfScans['manual_vol24h'];

            // Récupération de la configuration des scanners de l'utilisateur
            } else {

                $req2 = "SELECT * FROM settings_scans WHERE id_user = :id_user";
                $sql2 = $dbh->prepare($req2);
                $sql2->execute(array(
                    ':id_user'      => $idUser,
                ));

                $res2 = $sql2->fetch();

                $infosUser['settings_scans']['exchange_default']    = $res2->exchange_default;
                $infosUser['settings_scans']['nb_result_default']   = $res2->nb_result_default;

                $infosUser['settings_scans']['price_period']        = $res2->price_period;
                $infosUser['settings_scans']['price_vol24h']        = $res2->price_vol24h;

                $infosUser['settings_scans']['mh_period']           = $res2->mh_period;
                $infosUser['settings_scans']['mh_vol24h']           = $res2->mh_vol24h;
                $infosUser['settings_scans']['mh_orientation']      = $res2->mh_orientation;

                $infosUser['settings_scans']['ob_range']            = $res2->ob_range;
                $infosUser['settings_scans']['ob_period']           = $res2->ob_period;
                $infosUser['settings_scans']['ob_vol24h']           = $res2->ob_vol24h;
                $infosUser['settings_scans']['ob_orientation']      = $res2->ob_orientation;

                $infosUser['settings_scans']['rsi_period']          = $res2->rsi_period;
                $infosUser['settings_scans']['rsi_vol24h']          = $res2->rsi_vol24h;

                $infosUser['settings_scans']['manual_vol24h']       = $res2->manual_vol24h;
            }
        }


        return $infosUser;
    }


    /**
     * Création de la configuration des charts pour cet utilisateur
     * Initialisation avec la configuration par défaut
     */
    private static function createConfCharts($idUser)
    {
        // Instance PDO
        $dbh = \tools\dbSingleton::getInstance();

        // Configuration des graphiques par défaut
        $defaultConfCharts = \tools\config::getConfig('defaultConfCharts');

        // Insertion de la configuration utilisateur
        $req = "INSERT INTO settings_charts
                (
                    id_user, tdv_interval, tdv_studies1, tdv_studies2, tdv_studies3,
                    ob_range, obv_range, obv_interval, mh_interval,
                    aio_tdv_int1, aio_tdv_int2, aio_tdv_int3, aio_tdv_int4,
                    aio_tdv_studies1, aio_tdv_studies2, aio_tdv_studies3,
                    date_crea, date_modif
                )
                VALUES
                (
                    :id_user, :tdv_interval, :tdv_studies1, :tdv_studies2, :tdv_studies3,
                    :ob_range, :obv_range, :obv_interval, :mh_interval,
                    :aio_tdv_int1, :aio_tdv_int2, :aio_tdv_int3, :aio_tdv_int4,
                    :aio_tdv_studies1, :aio_tdv_studies2, :aio_tdv_studies3,
                    NOW(), NOW()
                )";

        $sql = $dbh->prepare($req);
        $sql->execute(array(
            ':id_user'      => $idUser,
            ':tdv_interval' => $defaultConfCharts['tdv_interval'],
            ':tdv_studies1' => $defaultConfCharts['tdv_studies1'],
            ':tdv_studies2' => $defaultConfCharts['tdv_studies2'],
            ':tdv_studies3' => $defaultConfCharts['tdv_studies3'],
            ':ob_range'     => $defaultConfCharts['ob_range'],
            ':obv_range'    => $defaultConfCharts['obv_range'],
            ':obv_interval' => $defaultConfCharts['obv_interval'],
            ':mh_interval'  => $defaultConfCharts['mh_interval'],
            ':aio_tdv_int1' => $defaultConfCharts['aio_tdv_int1'],
            ':aio_tdv_int2' => $defaultConfCharts['aio_tdv_int2'],
            ':aio_tdv_int3' => $defaultConfCharts['aio_tdv_int3'],
            ':aio_tdv_int4' => $defaultConfCharts['aio_tdv_int4'],
            ':aio_tdv_studies1' => $defaultConfCharts['aio_tdv_studies1'],
            ':aio_tdv_studies2' => $defaultConfCharts['aio_tdv_studies2'],
            ':aio_tdv_studies3' => $defaultConfCharts['aio_tdv_studies3'],
        ));

        // On passe le tag à 1 pour que cette entrée ne se créé qu'une fois
        $req = "UPDATE settings_users SET create_table_conf_charts = 1 WHERE id = :id";
        $sql = $dbh->prepare($req);
        $sql->execute(array(
            ':id' => $idUser,
        ));

        return $defaultConfCharts;
    }

    /**
     * Création de la configuration des scanners pour cet utilisateur
     * Initialisation avec la configuration par défaut
     */
    private static function createConfScans($idUser)
    {
        // Instance PDO
        $dbh = \tools\dbSingleton::getInstance();

        // Configuration des scanners par défaut
        $defaultConfScans = \tools\config::getConfig('defaultConfScans');

        // Insertion de la configuration utilisateur
        $req = "INSERT INTO settings_scans
                (
                    id_user, exchange_default, nb_result_default,
                    price_period, price_vol24h,
                    mh_period, mh_vol24h, mh_orientation,
                    ob_range, ob_period, ob_vol24h, ob_orientation,
                    rsi_period, rsi_vol24h,
                    manual_vol24h,
                    date_crea, date_modif
                )
                VALUES
                (
                    :id_user, :exchange_default, :nb_result_default,
                    :price_period, :price_vol24h,
                    :mh_period, :mh_vol24h, :mh_orientation,
                    :ob_range, :ob_period, :ob_vol24h, :ob_orientation,
                    :rsi_period, :rsi_vol24h,
                    :manual_vol24h,
                    NOW(), NOW()
                )";

        $sql = $dbh->prepare($req);
        $sql->execute(array(
            ':id_user'              => $idUser,
            ':exchange_default'     => $defaultConfScans['exchange_default'],
            ':nb_result_default'    => $defaultConfScans['nb_result_default'],

            ':price_period'         => $defaultConfScans['price_period'],
            ':price_vol24h'         => $defaultConfScans['price_vol24h'],

            ':mh_period'            => $defaultConfScans['mh_period'],
            ':mh_vol24h'            => $defaultConfScans['mh_vol24h'],
            ':mh_orientation'       => $defaultConfScans['mh_orientation'],

            ':ob_range'             => $defaultConfScans['ob_range'],
            ':ob_period'            => $defaultConfScans['ob_period'],
            ':ob_vol24h'            => $defaultConfScans['ob_vol24h'],
            ':ob_orientation'       => $defaultConfScans['ob_orientation'],

            ':rsi_period'           => $defaultConfScans['rsi_period'],
            ':rsi_vol24h'           => $defaultConfScans['rsi_vol24h'],

            ':manual_vol24h'        => $defaultConfScans['manual_vol24h'],
        ));

        // On passe le tag à 1 pour que cette entrée ne se créé qu'une fois
        $req = "UPDATE settings_users SET create_table_conf_scans = 1 WHERE id = :id";
        $sql = $dbh->prepare($req);
        $sql->execute(array(
            ':id' => $idUser,
        ));

        return $defaultConfScans;
    }
}
