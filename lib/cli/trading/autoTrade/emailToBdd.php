<?php
namespace cryptos\cli\trading\autoTrade;

use core\cliColorText;
use core\dbSingleton;
use core\config;
use core\curl;

/**
 * Lecture des alertes email de Tradingview vers une boite IMAP Cryptoview
 * Cette classe lit les emails, stocke les alertes en BDD et supprime les emails
 *
 * @since   2018-10-03
 * @author  Daniel Gomes
 */
class emailToBdd
{
    /**
     * Atributs
     */
    private $_nameBDD;                              // Nom de la base de données du bot
    private $_colorCli;                             // Coloration syntaxique
    private $_wait = 2;                             // Temps en secondes entre chaque boucle

    /**
     * Paramètre de connexion à la BAL
     */
    private $_balServer;                            // Serveur de messagerie à appeler
    private $_balEmail;                             // Boite mail recevant toutes les alertes
    private $_balPassword;                          // Mot de passe de la boite mail

    private $_emailfrom;                            // Email autorisé à envoyer des alertes
    private $_emailToAdmin;                         // Email utilisé pour le compte Tradingview commun (leo|pym|dan)

    /**
     * Liste des exchanges supportés
     * @var array
     */
    private $_exchangeList;


    /**
     * Constructeur
     */
    public function __construct()
    {
        // Gestion des couleurs en interface CLI
        $this->_colorCli = new cliColorText();

        // Récupération de la configuration
        $this->getConf();
    }


    /**
     * Récupération de la configuration
     */
    private function getConf()
    {
        // Connexion à la base de données
		$confBot = config::getConfig('botAutoTrade');

        $this->_nameBDD         = $confBot['nameBDD'];

        $this->_balServer       = $confBot['bal']['server'];
        $this->_balEmail        = $confBot['bal']['email'];
        $this->_balPassword     = $confBot['bal']['password'];

        $this->_emailfrom       = $confBot['emailFrom'];
        $this->_emailToAdmin    = $confBot['emailToAdmin'];
        $this->_adminUserList   = $confBot['adminUserList'];

        $this->_exchangeList    = $confBot['exchangeList'];
    }


    /**
     * Collecte des nouvelles alertes toutes les 'n' secondes
     */
    public function run()
    {
        // Boucle infinie
        for ($i=0; $i==$i; $i++) {
            $this->checkBal();
            sleep($this->_wait);
        }
    }


    /**
     * Lecture des nouveaux mail
     */
    private function checkBal()
    {
        try {
            // Instance PDO : open
            $dbh = dbSingleton::getInstance($this->_nameBDD);

            // Connexion à la BAL IMAP dans la boite de réception
            $selected  = "{$this->_balServer}INBOX";
            $mbox      = @imap_open($selected, $this->_balEmail , $this->_balPassword);

            // Echec de connexion à la boite mail
            if ($mbox === false) {
                return;
            }

            // Récupère les ID des emails nons lus
            $checkUseenMails = imap_search($mbox, 'UNSEEN');

            // Il y a des nouveaux mails non lus, on check
            if ($checkUseenMails) {

                // Requêtes préparées ------------------------------------------

                // Insertion d'une alerte email
                $req = "INSERT INTO     tradingview_alert
                                        ( userId,  json,  date_email,  status)
                        VALUES          (:userId, :json, :date_email, :status)";
                $sql = $dbh->prepare($req);

                // Insertion d'une alerte email invalide
                $reqErr = "INSERT INTO  tradingview_alert_invalid
                                        ( userId,  json,  error,  date_email)
                           VALUES       (:userId, :json, :error, :date_email)";
                $sqlErr = $dbh->prepare($reqErr);

                // Enregistrement des spams
                $reqSpam = "INSERT INTO email_trash
                                        ( emailTo,  body,  error,  date_email)
                            VALUES      (:emailTo, :body, :error, :date_email)";
                $sqlSpam = $dbh->prepare($reqSpam);

                // Récupération de la configuration d'un compte admin
                $reqAdmin = "SELECT * FROM users WHERE profilAdmin = :profilAdmin";
                $sqlAdmin = $dbh->prepare($reqAdmin) ;

                // Récupération de la configuration d'un compte client
                $reqClient = "SELECT * FROM users WHERE id = :id";
                $sqlClient = $dbh->prepare($reqClient) ;


                // Boucle sur les emails non-lus -------------------------------
                foreach ($checkUseenMails as $emailId) {

                    $error   = 0;
                    $message = '';

                    // Récupération de l'entête de l'email
                    $header = imap_headerinfo($mbox, $emailId);

                    // On vérifie l'expéditeur pour éviter de se faire spammer (Tradingview)
                    // if (!strstr($header->fromaddress, $this->_emailfrom)) {
                    //     $error++;
                    //     $message = 'The email is not sent by Tradingview';
                    // }

                    // Récupération de l'alias d'email ayant servi à l'envoi de l'alerte
                    $toAddress = $header->toaddress;

                    // On vérifie si c'est une alerte provenant d'un client ou de l'un des comptes admin (leo|pym|dan)
                    $isEmailAdmin = false;
                    if ($toAddress == $this->_emailToAdmin) {
                        $isEmailAdmin = true;
                    }

                    // L'alias ne correspond pas à un code unique valide et il ne s'agit pas de l'alias "admin"
                    $validCodeUniq = $this->checkCodeUniq($toAddress);
                    if ($error==0 && $validCodeUniq === false && $isEmailAdmin === false) {
                        $error++;
                        $message = 'Invalid sender';
                    }

                    // Récupération de la date & heure du mail
                    $dateTimeEmail = $this->getDateHeureEmail($header->date);

                    // Récupération du corps du message avec l'ID
                    $json = imap_fetchbody($mbox, $emailId, 1);
                    $json = $this->cleanEmailBody($json);

                    // TEST ////////////////////////////////////////////////////
                    // $json = json_encode(array(
                    //     'exc' => 'binance',
                    //     'mar' => 'XRPBTC',
                    //     'act' => 'buy',
                    //     'qty' => 0.12,
                    // ));

                    // Vérification du format du JSON, pour l'instant seule la validité du JSON est vérifiée
                    $alertCheck = new alertCheck($json);
                    $alert = $alertCheck->jsonValidate();
                    if ($error==0 && !is_array($alert)) {
                        $error++;
                        $message = $alert;
                    }

                    if ($error==0) {
                        // Récupération de la configuration d'un compte admin
                        if ($isEmailAdmin && isset($alert['usr']) && in_array($alert['usr'], $this->_adminUserList)) {
                            $sqlAdmin->execute(array( ':profilAdmin' => $alert['usr'] ));
                            if ($sqlAdmin->rowCount() > 0) {
                                $confUser = $sqlAdmin->fetch(\PDO::FETCH_ASSOC);
                            }
                        }

                        // Récupération de la configuration d'un compte client
                        if ($isEmailAdmin === false) {
                            $sqlClient->execute(array( ':id' => $validCodeUniq ));
                            if ($sqlClient->rowCount() > 0) {
                                $confUser = $sqlClient->fetch(\PDO::FETCH_ASSOC);
                            }
                        }

                        // Utilisateur inconnu
                        if ($error==0 && !isset($confUser)) {
                            $error++;
                            $message = 'Unknown user';
                        }
                    }


                    // Nom de l'exchange obligatoire & exchange supporté
                    if ($error==0) {
                        if (!isset($alert['exc']) || !in_array(strtolower($alert['exc']), $this->_exchangeList)) {
                            $error++;
                            $message = 'Invalid exchange';
                        } else {
                            $exchange = strtolower($alert['exc']);
                        }
                    }

                    // Vérification de la présence des clés API pour cet exhanges
                    if ($error==0) {
                        $apiKey     = $confUser['connect_' . $exchange . '_apiKey'];
                        $apiSecret  = $confUser['connect_' . $exchange . '_apiSecret'];
                        if (empty($apiKey) || empty($apiSecret)) {
                            $error++;
                            $message = 'API keys missing';
                        }
                    }

                    // Connexion à l'API de l'exchange
                    if ($error==0) {
                        $api = new exchangeAPI($exchange, $apiKey, $apiSecret);
                    }

                    // Vérification du contenu de l'alerte email dans le flux JSON
                    if ($error==0) {
                        $checkFormatJson = $alertCheck->checkFormatJson($api);
                        if ($checkFormatJson !== true) {
                            $error++;
                            $message = $checkFormatJson;
                        }
                    }

                    $log = '';

                    // Enregistrement de l'alerte et affichage du log
                    if ($error==0) {

                        $params = array(
                            ':userId'       => $confUser['id'],
                            ':json'         => $json,
                            ':date_email'   => $dateTimeEmail,
                            ':status'       => 'wait',
                        );

                        $sql->execute($params);

                        // Affichage du message de log en cas de succes
                        if ($isEmailAdmin == true) {
                            $logMsg = array(
                                $toAddress,
                                'id='.$confUser['id'],
                                'usr='.$alert['usr'],
                                'exc='.$alert['exc'],
                                'mar='.$alert['mar'],
                                'act='.$alert['act'],
                                'qty='.$alert['qty']
                            );
                        } else {
                            $logMsg = array(
                                $toAddress,
                                'id='.$confUser['id'],
                                'exc='.$alert['exc'],
                                'mar='.$alert['mar'],
                                'act='.$alert['act'],
                                'qty='.$alert['qty']
                            );
                        }

                        $log .= $this->_colorCli->getColor($dateTimeEmail . ' : ',  'light_gray');
                        $log .= $this->colorLog($logMsg, 'light_green');
                        $log .= chr(10);

                    } else {

                        // Utilisateur existant
                        if (isset($confUser)) {

                            $params = array(
                                ':userId'       => $confUser['id'],
                                ':json'         => $json,
                                ':error'        => $message,
                                ':date_email'   => $dateTimeEmail,
                            );

                            $sqlErr->execute($params);

                            // Affichage du message de log en cas d'erreur
                            $logMsg = array($toAddress, 'id='.$confUser['id'], 'msg: '.$message, 'json: '.$json);

                            $log .= $this->_colorCli->getColor($dateTimeEmail . ' : ',  'light_gray');
                            $log .= $this->colorLog($logMsg, 'yellow');
                            $log .= chr(10);

                        } else {

                            $params = array(
                                ':emailTo'      => $toAddress,
                                ':body'         => $json,
                                ':error'        => $message,
                                ':date_email'   => $dateTimeEmail,
                            );

                            $sqlSpam->execute($params);

                            // Affichage du message de log pour un utilisateur inconnu
                            $log .= $this->_colorCli->getColor($dateTimeEmail . ' : ' . $message . ' (' . $toAddress . ')',  'light_red');
                            $log .= chr(10);
                        }
                    }

                    // Affichage des logs
                    echo $log;

                    // Suppresion du mail
                    // imap_delete($mbox, $emailId);
                }

                // Vide la corbeille
                imap_expunge($mbox);

                // Clôture de la session imap
                imap_close($mbox);
            }

            // Instance PDO : close
            $dbh = dbSingleton::closeInstance($this->_nameBDD);

        } catch (\Exception $e) {

            echo chr(10);
            echo $this->_colorCli->getColor('['.date('Y-m-d H:i:s') . ']',  'light_gray');
            echo $this->_colorCli->getColor('[ERROR] ' . $e->getMessage(), 'light_red');
            echo chr(10) . chr(10);
        }
    }


    /**
     * Récupération de la date & heure du mail
     *
     * @param   string  $headerDate     Date & heure dans le format du header de l'email
     * @return  string
     */
    private function getDateHeureEmail($headerDate)
    {
        $expDate        = explode(', ', $headerDate);
        $dateTimeEmail  = $expDate[1];

        $expDate        = explode(' +', $dateTimeEmail);
        $dateTimeEmail  = $expDate[0];

        $d = \DateTime::createFromFormat('d M Y H:i:s', $dateTimeEmail);
        $dateTimeEmail = $d->format('Y-m-d H:i:s');

        return $dateTimeEmail;
    }


    /**
     * Nettoyage de la zone body de l'email d'alerte
     *
     * @param       json     $json   Alerte au format JSON
     * @return      json
     */
    private function cleanEmailBody($json)
    {
        $json = strip_tags($json);
        $json = str_replace(chr(10),   '', $json);
        $json = str_replace("\n",      '', $json);
        $json = str_replace("\r",      '', $json);

        return $json;
    }


    /**
     * Vérification de la validité du code unqiue
     *
     * @param       string          $toAddress      alias de réception de l'alerte email
     * @return      integer|false
     */
    private function checkCodeUniq($toAddress)
    {
        $expTo      = explode('@', $toAddress);
        $codeUniq   = new codeUniq;
        $checkCode  = $codeUniq->decodeAlpha($expTo[0]);

        return $checkCode;
    }


    /**
     * Affichage avec des pipes en séparateur gris foncé
     *
     * @param       array       $logMsg
     * @param       string      $color
     * @return      string
     */
    private function colorLog($logMsg, $color)
    {
        $log = array();
        $sep = $this->_colorCli->getColor('|', 'dark_gray');

        foreach ($logMsg as $val) {
            $log[] = $this->_colorCli->getColor($val,  $color);
        }

        $log = implode(' ' . $sep . ' ', $log);

        return $log;
    }
}
