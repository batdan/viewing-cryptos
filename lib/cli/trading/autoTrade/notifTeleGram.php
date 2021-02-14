<?php
namespace cryptos\cli\trading\autoTrade;

use TelegramBot\Api\BotApi;

/**
 * Envoi des notifications au client avec Telegram
 *
 * https://packagist.org/packages/telegram-bot/api
 *
 * @author Daniel Gomes
 */

class notifTeleGram
{
    /**
	 * Attributs
	 */
    private $_botTelegram;                          // Instance de l'API Telegram

    private $_telegram_activ;                       // Activation de Telegram
    private $_telegram_id;                          // ChatId Telegram
    private $_telegram_token;                       // API Token Telegram


    /**
	 * Constructeur
	 */
	public function __construct($user)
	{
        $this->BotApiTelegram();
        $this->_botTelegram = new BotApi($this->_telegram_token);
	}


    /**
     * Instance de l'API Telegram
     */
    private function BotApiTelegram()
    {
        try {
            set_time_limit(2);
            $this->_botTelegram = new BotApi($this->_telegram_token);
        } catch (\Exception $e) {
            $message  = '___ API Telegram Offline ___' . chr(10) . chr(10);
            $message .= 'Erreur : ' . $e . chr(10);
            error_log($message, 3, $this->_logFileName);
        }

        set_time_limit(0);
    }


    /**
     * Envoyer des messages avec Telegram
     */
    private function telegramMsg($msg)
    {
        try {
            set_time_limit(2);
            if ($this->_telegram_activ == 1) {
                $this->_botTelegram->sendMessage($this->_telegram_id, $msg);
            }
        } catch (\Exception $e) {
            $message  = '___ Telegram Problem ___' . chr(10) . chr(10);
            $message .= 'Erreur : ' . $e . chr(10);
            error_log($message, 3, $this->_logFileName);
        }

        set_time_limit(0);
    }
}
