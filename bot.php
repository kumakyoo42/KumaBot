<?php

require_once('page.php');

if (!isset($argv[1])) die('Usage: php bot.php <taskfile>'.PHP_EOL);

$bot = new Bot();
$bot->readCredentials();
$bot->readTasks($argv[1]);
$bot->readCSRFToken();
$bot->writeStartLog();
$bot->execute();
$bot->writeFinishLog();

class Bot
{
    final const BASE_URL = 'https://el.wiktionary.org/w/api.php';

    private $bot_name;
    private $bot_auth;
    private $log_page;
    private $auth_token;
    private $csrf_token;
    private $sleep = 30000;
    private $tasks = array();
    private $abort = 0;

    private $success;
    private $skipped;

    public function readCredentials()
    {
        try
        {
            $f = file('credentials');
            $this->bot_name = trim(array_shift($f));
            $this->bot_auth = trim(array_shift($f));
            $this->log_page = trim(array_shift($f));
            $this->auth_token = trim(array_shift($f));
        }
        catch (Exception $e)
        {
            $this->die('file credentials missing or damaged');
        }
        $this->log('credentials read successfully');
    }

    public function readTasks($filename)
    {
        $before = count($this->tasks);
        try
        {
            $f = file($filename);
            foreach ($f as $line)
            {
                $line = trim($line);
                if ($line=='') continue;
                list($cmd,$param) = explode(' ',trim($line),2);

                if ($cmd=='SLEEP')
                {
                    $this->sleep = intval($param);
                    $this->log("\033[31;1mdelay set to ".($this->sleep/1000)." seconds\033[0m",true);
                    continue;
                }

                $this->tasks[] = array($cmd,$param);
            }
        }
        catch (Exception $e)
        {
            $this->die('tasks file missing or damaged');
        }
        $this->log((count($this->tasks)-$before).' tasks read successfully',true);
    }

    public function readCSRFToken()
    {
        $fields = array('action' => 'query',
                        'meta' => 'tokens',
                        'format' => 'json');

        $ch = curl_init();
        if ($ch===false)
          $this->die('cannot init curl');

        if (!curl_setopt($ch, CURLOPT_URL, self::BASE_URL))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_POST, true))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_USERAGENT, $this->bot_auth))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$this->auth_token)))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_RETURNTRANSFER, true))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_COOKIEJAR, 'cookies.txt'))
          $this->die('setup of curl failed');

        $result = curl_exec($ch);
        if ($result===false)
          $this->die('execution of curl failed');

        $json = json_decode($result, true);
        if ($json===null)
          $this->die('execution of curl failed: '.print_r($result,true));

        $this->csrf_token = $json['query']['tokens']['csrftoken']??false;
        if ($this->csrf_token===false)
          $this->die('execution of curl failed: '.print_r($result,true));
    }

    public function execute()
    {
        $this->log('starting execution of '.count($this->tasks).' tasks');
        $total = count($this->tasks);
        while (!empty($this->tasks))
        {
            list($cmd,$param) = array_shift($this->tasks);

            $wait = true;
            switch ($cmd)
            {
             case 'SORTLANGS':
                $wait = $this->sort($param);
                break;
             case 'FIXKAT': case 'FIXELL': case 'SORTFOOT':
                $wait = $this->fixFooter($param, $cmd);
                break;
             default:
                $this->die('unknown command: '.$cmd);
                break;
            }

            fwrite(STDERR,($total-count($this->tasks)).'/'.$total."  \r");
            if ($wait && !empty($this->tasks))
              $this->sleep();
        }
        $this->log('execution of tasks finished');
    }

    //////////////////////////////////////////////////////////////////

    private function sort($word)
    {
        $this->log('sorting page "'.$word.'"');

        $page = $this->readPage($word);
        if ($page===false)
        {
            $this->log('reading page failed. Skipping.');
            $this->skipped++;
            return false;
        }
        list($id,$wikitext) = $page;

        $page = new Page($word, $wikitext);
        $page->setFlags(Page::CHECK_FOR_BROKEN_LANGS|Page::CHECK_ORDER_OF_LANGS);
        $page->dissect();
        if (!$page->isValid())
        {
            $this->log('page is not valid. Skipping.');
            $this->skipped++;
            return false;
        }

        $page->analyze();
        if (!empty($page->broken_langs))
        {
            $this->log('page contains broken languages. Skipping.');
            $this->skipped++;
            return false;
        }
        if (!empty($page->unknown_langs))
        {
            $this->log('page contains unknown languages. Skipping.');
            $this->skipped++;
            return false;
        }
        if (empty($page->unsorted_word))
        {
            $this->log('page seems to be already sorted. Skipping.');
            $this->skipped++;
            return false;
        }

        if (isset($page->unsorted_word['greek'])
            || isset($page->unsorted_word['mixed']))
        {
            $this->log('page contains greek languages. Skipping due to policy.');
            $this->skipped++;
            return false;
        }

        if (!isset($page->unsorted_word['non_greek']) || count($page->unsorted_word)!=1)
          $this->die('something odd happend: '.print_r($page->unsorted_word,true));

        $page->sortLanguages();
        $new_text = $page->join();

        if ($new_text==$wikitext)
        {
            $this->log('nothing changed after sorting. Skipping.');
            $this->skipped++;
            return false;
        }

        $this->writePage($word, $id, $new_text, 'ταξινομήθηκε η σειρά των γλωσσών');

        $this->log('"'.$word.'" successfully changed');
        $this->success++;
        return true;
    }

    private function fixFooter($word, $cmd)
    {
        $this->log('fixing footer of page "'.$word.'"');

        $page = $this->readPage($word);
        if ($page===false)
        {
            $this->log('reading page failed. Skipping.');
            $this->skipped++;
            return false;
        }
        list($id,$wikitext) = $page;

        $page = new Page($word, $wikitext);
        $page->setFlags(Page::CHECK_FOR_DISALLOWED_ITEMS_IN_LANGUAGES|Page::CHECK_FOOTER_SORTED);
        $page->dissect();
        if (!$page->isValid())
        {
            $this->log('page is not valid. Skipping.');
            $this->skipped++;
            return false;
        }

        $page->analyze();
        if (empty($page->disallowed_in_langs['Κατηγορία'])
            && empty($page->disallowed_in_langs['κλείδα-ελλ'])
            && $page->footer_sorted)
        {
            $this->log('footer seems to be already fixed. Skipping.');
            $this->skipped++;
            return false;
        }

        if ($cmd=='FIXKAT')
          $page->moveKatToFooter();
        if ($cmd=='FIXELL')
          $page->moveEllToFooter();
        if ($cmd=='SORTFOOT')
          $page->sortFooter();

        $new_text = $page->join();

        if ($new_text==$wikitext)
        {
            $this->log('nothing changed after fixing. Skipping.');
            $this->skipped++;
            return false;
        }

        $this->writePage($word, $id, $new_text, 'διόρθωση υποσέλιδου');

        $this->log('"'.$word.'" successfully changed');
        $this->success++;
        return true;
    }

    private function readPage($word)
    {
        $fields = array('action' => 'parse',
                        'page' => $word,
                        'prop' => 'wikitext|revid',
                        'format' => 'json');

        $ch = curl_init();
        if ($ch===false)
          $this->die('cannot init curl');

        if (!curl_setopt($ch, CURLOPT_URL, self::BASE_URL))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_POST, true))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_USERAGENT,$this->bot_auth))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$this->auth_token)))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt'))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_RETURNTRANSFER, true))
          $this->die('setup of curl failed');

        $result = curl_exec($ch);
        if ($result===false)
          $this->die('execution of curl failed');

        $json = json_decode($result,true);
        if ($json===null)
          $this->die('execution of curl failed: '.print_r($result,true));

        if (!isset($json['parse']))
          $this->die('execution of curl failed: '.print_r($result,true));

        $json = $json['parse'];
        $title = $json['title']??false;
        $id = $json['revid']??false;
        $page = $json['wikitext']['*']??false;

        if ($id===false || $page===false)
          $this->die('something odd happend: '.print_r($result,true));

        if ($title!==$word) return false;

        return array($id, $page);
    }

    private function writePage($word, $id, $text, $comment)
    {
        $fields = array('action' => 'edit',
                        'title' => $word,
                        'text' => $text,
                        'summary' => $comment,
                        'assertuser' => $this->bot_name,
                        'bot' => 1,
                        'baserevid' => $id,
                        'token' => $this->csrf_token,
                        'format' => 'json');

        $ch = curl_init();
        if ($ch===false)
          $this->die('cannot init curl');

        if (!curl_setopt($ch, CURLOPT_URL, self::BASE_URL))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_POST, true))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields)))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_USERAGENT,$this->bot_auth))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_HTTPHEADER, array('Authorization: Bearer '.$this->auth_token)))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_COOKIEFILE, 'cookies.txt'))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch, CURLOPT_RETURNTRANSFER, true))
          $this->die('setup of curl failed');

        $result = curl_exec($ch);
        if ($result===false)
          $this->die('execution of curl failed');

        $json = json_decode($result,true);
        if ($json===null)
          $this->die('execution of curl failed: '.print_r($result,true));

        if (!isset($json['edit']))
          $this->die('execution of curl failed: '.print_r($result,true));

        $json = $json['edit'];
        $result = $json['result']??false;
        $new_title = $json['title']??false;

        if ($result!=='Success')
          $this->die('something went wrong while writing: '.print_r($json,true));

        if ($new_title!==$word)
          $this->die('something went wrong while writing: '.print_r($json,true));
    }

    //////////////////////////////////////////////////////////////////

    public function writeStartLog()
    {
        $this->log('ready to go, waiting 10 seconds',true);
        sleep(10);
        $this->installSignalHandler();

        $h = $this->readPage($this->log_page);
        if ($h===false)
          $this->die('something odd happend');
        list($id, $text) = $h;

        $text .=  PHP_EOL.PHP_EOL.'== Log ('.date("r").') =='.PHP_EOL.PHP_EOL;
        $text .= ' Set sleeping time between two writes to '.($this->sleep/1000).' seconds.'.PHP_EOL;

        $last = false;
        foreach ($this->tasks as list($cmd,$param))
        {
            if ($cmd!==$last)
            {
                if ($last!==false)
                  $text .= ' Scheduled: '.$count.' times a '.$last.' action.'.PHP_EOL;
                $count = 1;
                $last = $cmd;
                continue;
            }

            $count++;
        }
        if ($last!==false)
          $text .= ' Scheduled: '.$count.' times a '.$last.' action.'.PHP_EOL;

        $this->writePage($this->log_page, $id, $text, 'ενημέρωση των αρχείων καταγραφής');
        $this->log('wrote start log');

        $this->success = 0;
        $this->skipped = 0;
    }

    public function writeFinishLog()
    {
        $h = $this->readPage($this->log_page);
        if ($h===false)
          $this->die('something odd happend');
        list($id, $text) = $h;

        $text .= PHP_EOL; // mediawiki removes trailing whitespace
        $text .= ' =================================================================='.PHP_EOL;
        $text .= ' '.$this->success.' tasks processed successfully.'.PHP_EOL;
        $text .= ' '.$this->skipped.' tasks skipped.'.PHP_EOL;
        $text .= ' '.($this->abort===2?'Aborted':'Finished').' at '.date("r").'.'.PHP_EOL.PHP_EOL;

        $this->writePage($this->log_page, $id, $text, 'ενημέρωση των αρχείων καταγραφής');
        $this->log('wrote finish log');
        $this->die();
    }

    private function installSignalHandler()
    {
        $this->abort = 1;
        pcntl_async_signals(true);
        pcntl_signal(SIGINT, array($this, "signalHandler"));
    }

    private function signalHandler()
    {
        $this->die('abort due to ctrl-C');
    }

    //////////////////////////////////////////////////////////////////

    private function sleep()
    {
        $this->log('sleeping for '.($this->sleep/1000).' seconds');
        usleep(1000*$this->sleep);
    }

    private function die($msg=false)
    {
        if ($msg!==false)
        {
            $this->log($msg);
            if ($this->abort===1)
            {
                sleep(60);
                $this->abort = 2;
                $this->writeFinishLog();
            }
            $this->log('bot stopped due to errors');
        }
        exec('rm -rf cookies.txt');
        die();
    }

    private function log($msg, $important=false)
    {
        if ($important)
          echo $msg.PHP_EOL;
        $res = fopen('log','a');
        fwrite($res,$msg.PHP_EOL);
        fclose($res);
    }
}



?>
