<?php
namespace cryptos\cli\trading\autoTrade;

/**
 * Enregistrement des événements dans un fichier de log
 *
 * @author Daniel Gomes
 */

class logFile
{
    /**
	 * Attributs
	 */
    private $_logFileName;                          // Nom du fichier de log


    /**
	 * Constructeur
	 */
    public function __contruct()
    {
        $this->logFile();

        return $this->_logFileName;
    }


    /**
     * Création ou récupération du nom du fichier de log de l'utilisateur
     */
    private function logFile()
    {
        $this->_logFileName = '/root/bot-auto-trade.log';

        if (! file_exists($this->_logFileName)) {
            fopen($this->_logFileName, "w");
        }
    }
}
