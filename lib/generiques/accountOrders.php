<?php
namespace cryptos\generiques;

/**
 * Récupération de la liste des abonnements d'un utilisateur
 *
 * @author Daniel Gomes
 */
class accountOrders
{
    /**
     * Préparation du dataset pour un bootstrapTable
     */
    public static function getInfos($idUser)
    {
        $accountOrders = array();

        // Instance PDO
        $dbh = \tools\dbSingleton::getInstance();

        // Récupération de la liste d'affiliés
        $req = "SELECT      DATE_FORMAT(date_crea, '%Y-%m-%d')  AS dateOrder,
                            CONCAT(amount, ' ', coinLabel)      AS amountDevise,
                            id, libelle, date_start, date_end

                FROM        orders

                WHERE       id_user = :id_user

                ORDER BY    id DESC";

        $sql = $dbh->prepare($req);
        $sql->execute(array(':id_user' => $idUser));

        // Liste des ordres
        if ($sql->rowCount() > 0) {

            $i=0;

            while ($res = $sql->fetch()) {

                $accountOrders[$i]['id']            = $res->id;
                $accountOrders[$i]['dateOrder']     = $res->dateOrder;
                $accountOrders[$i]['amountDevise']  = $res->amountDevise;
                $accountOrders[$i]['libelle']       = $res->libelle;
                $accountOrders[$i]['date_start']    = $res->date_start;
                $accountOrders[$i]['date_end']      = $res->date_end;

                $i++;
            }
        }

        return $accountOrders;
    }
}
