<?php
namespace cryptos\generiques;

/**
 * Récupération des informations d'affiliation d'un utilisateur
 * Cette classe retourne les abonnements des filleuls (payés et en attente de payement)
 *
 * @author Daniel Gomes
 */
class infosAffil
{
    /**
     * Récupération des informations sur les filleuls
     */
    public static function getInfos($codeParrain)
    {
        $infosAffil = array();

        // Instance PDO
        $dbh = \tools\dbSingleton::getInstance();

        // Récupération de la liste d'affiliés
        $req = "SELECT id, nickname FROM settings_users WHERE code_parrain = :code_parrain";
        $sql = $dbh->prepare($req);
        $sql->execute(array(':code_parrain' => $codeParrain));

        // Liste des affiliés
        if ($sql->rowCount() > 0) {

            $reqOrder = "SELECT * FROM orders WHERE id_user = :id_user ORDER BY id DESC LIMIT 1";
            $sqlOrder = $dbh->prepare($reqOrder);

            $payOld  = array();
            $payPending = array();

            $i=0;
            $j=0;
            while ($res = $sql->fetch()) {

                $sqlOrder->execute(array( ':id_user' => $res->id ));

                if ($sqlOrder->rowCount() > 0) {

                    $resOrder = $sqlOrder->fetch();

                    $pctParrain = ($resOrder->amount / 100) * 7;
                    $pctParrain = strval($pctParrain);
                    $pctParrain = sprintf('%.8f', $pctParrain);

                    if ($resOrder->affil_pay == 1) {

        				$payOld[$i]['date_crea']		= $resOrder->date_crea;
                        $payOld[$i]['nickname']         = $res->nickname;
                        $payOld[$i]['libelle']          = $resOrder->libelle;
                        $payOld[$i]['subscription']     = $resOrder->date_start . ' - ' . $resOrder->date_end;
                        $payOld[$i]['amount']           = $resOrder->amount . ' ' . $resOrder->coinLabel;
                        $payOld[$i]['pctParrain']       = $pctParrain . ' ' . $resOrder->coinLabel;

                        $i++;

                    } else {

        				$payPending[$j]['date_crea']	= $resOrder->date_crea;
                        $payPending[$j]['nickname']     = $res->nickname;
                        $payPending[$j]['libelle']      = $resOrder->libelle;
                        $payPending[$j]['subscription'] = $resOrder->date_start . ' - ' . $resOrder->date_end;
                        $payPending[$j]['amount']       = $resOrder->amount . ' ' . $resOrder->coinLabel;
                        $payPending[$j]['pctParrain']   = $pctParrain . ' ' . $resOrder->coinLabel;

                        $j++;
                    }
                }
            }

            $infosAffil['payOld']       = $payOld;
            $infosAffil['payPending']   = $payPending;
        }

        return $infosAffil;
    }
}
