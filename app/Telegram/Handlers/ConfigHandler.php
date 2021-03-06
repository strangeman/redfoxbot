<?php


namespace App\Telegram\Handlers;

use App\Telegram\Bot;
use App\Telegram\Commands\ConfigCommand;
use App\Telegram\Config;
use League\Uri\Components\Host;
use League\Uri\Components\Query;
use League\Uri\Parser;
use TelegramBot\Api\Types\Message;
use TelegramBot\Api\Types\Update;

class ConfigHandler extends BaseHandler
{
    public function run(Update $update)
    {
        \Log::debug('run ConfigHandler');
        $chatId = $update->getMessage()->getChat()->getId();
        $state  = Config::getState($chatId) ?: '';
        list(, $type) = explode(':', $state);
        switch ($type) {
            case 'url':
                return $this->parseUrl($update->getMessage());
            case 'pin':
            case 'teamPassword':
            case 'teamLogin':
            case 'login':
            case 'password':
            case 'gameId':
                Config::setValue($chatId, $type, $update->getMessage()->getText());
                if ($update->getMessage()->getChat()->getType() !== 'private') {
                    try {
                        Bot::action()->deleteMessage($chatId, $update->getMessage()->getMessageId());
                    } catch (\Exception $e) {
                        \Log::error('Cannot delete msg:' . $e->getMessage());
                    }
                }
                Bot::sendMessage($chatId, 'Настройки обновлены', ConfigCommand::getConfigKeyboard($chatId));
                break;
            default:
                Bot::sendMessage($chatId, 'unknown state ' . $state);
        }
        Config::setState($chatId, '');

        return false;
    }

    private function parseUrl(Message $message)
    {
        $text = trim($message->getText());
        \Log::debug('parseUrl: ' . $text);
        $chatId = $message->getChat()->getId();

        if (strpos($text, 'http') === false) {
            $text = 'http://' . $text;
        };
        $uriParser = new Parser();
        try {
            $uri          = $uriParser($text);
            $query        = new Query(array_get($uri, 'query', ''));
            $parsedDomain = array_get($uri, 'host', '');

            $host = new Host($parsedDomain);
        } catch (\Exception $e) {
            \Log::error('invalidUrl: ' . $text);
            Bot::sendMessage($chatId, 'Прислан не валидный url: ' . $text . PHP_EOL . 'Выход из режима конфигурации');
            Config::setState($message->getChat()->getId(), '');

            return false;
        }
        $domain = $host->getRegisterableDomain();
        $city   = current(array_pad(explode('/', trim($uri['path'], '/')), 1, ''));

        if (!$domain) {
            \Log::error('invalidDomain: ' . $text);
            Bot::sendMessage($chatId, 'Ошибка парсинга URL (не правильный домен)');
            Config::setState($message->getChat()->getId(), '');
            return false;
        }

        $patchedUrl     = sprintf('http://%s/', $host->__toString());
        $defaultSetting = [
            'auto'   => 'true',
            'format' => 'a-z0-9',
        ];

        foreach ($defaultSetting as $param => $value) {
            if (!Config::getValue($chatId, $param)) {
                Config::setValue($chatId, $param, $value);
            }
        }
        Config::setState($message->getChat()->getId(), '');

        switch ($domain) {
            case 'dzzzr.ru':
            case 'ekipazh.org':
                if (!$city) {
                    \Log::error('invalidCity: ' . $text);
                    Bot::sendMessage($chatId, 'Ошибка парсинга URL (не указан город)');

                    return false;
                }
                switch (true) {
                    case $host->getSubdomain() === 'classic':
                        $project = 'DozorClassic';
                        break;
                    case $domain == 'dzzzr.ru':
                        $project = 'DozorLite';
                        break;
                    default:
                        $project = 'Ekipazh';
                        break;
                }
                Config::setValue($chatId, 'project', $project);
                Config::setValue($chatId, 'domain', $city);
                Config::setValue($chatId, 'url', $patchedUrl);
                Config::setValue($chatId, 'pin', $query->getParam('pin'));
                $msg = 'настройки установлены. Не забудьте указать пин-код';
                Config::setState($chatId, '');
                break;
            case 'redfoxkrsk.ru':
                Config::setValue($chatId, 'url', $patchedUrl);
                Config::setValue($chatId, 'project', 'RedfoxAvangard');
                $msg = 'настройки установлены. Не забудьте указать логин/пароль';
                break;
            case 'en.cx':
            case 'quest.ua':
                Config::setValue($chatId, 'url', $patchedUrl);
                Config::setValue($chatId, 'project', 'Encounter');
                Config::setValue($chatId, 'gameId', $query->getParam('gid'));
                $msg = 'настройки установлены. Не забудьте указать логин/пароль';
                break;
            case 'probeg.net.ua':
                if (!$city) {
                    \Log::error('invalidCity: ' . $text);
                    Bot::sendMessage($chatId, 'не удалось распознать ID игры');
                    Config::setState($message->getChat()->getId(), '');
                    return false;
                }
                Config::setValue($chatId, 'url', $patchedUrl . $city . '/');
                Config::setValue($chatId, 'project', 'Probeg');
                Config::setValue($chatId, 'pin', $query->getParam('p'));

                $msg = 'настройки установлены. Не забудьте указать пин-код';
                break;
            default:
                \Log::error('invalidURL: ' . $text);
                $msg = 'Не удалось распознать присланный адрес. ' . PHP_EOL . 'выберите пункт url заново';
                Bot::action()->sendMessage($chatId, $msg);
                return false;
        }
        Bot::action()->sendMessage($chatId, $msg, null, false, null, ConfigCommand::getConfigKeyboard($chatId));
    }
}
