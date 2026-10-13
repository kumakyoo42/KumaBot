<?php

require_once('page.php');

$bot = new Bot();
$bot->readCredentials();
$bot->readTasks($argv[1]??'tasks');
$bot->writeStartLog();
$bot->execute();
$bot->writeFinishLog();

class Bot
{
    final const BASE_URL = 'https://el.wiktionary.org/w/rest.php/v1/page/';

    private $bot_name;
    private $log_page;
    private $auth_token;
    private $sleep = 30000;
    private $tasks = array();

    private $success;
    private $skipped;

    public function readCredentials()
    {
        try
        {
            $f = file('credentials');
            $this->bot_name = trim(array_shift($f));
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
                list($cmd,$param) = explode(' ',trim($line),2);
                $this->tasks[] = array($cmd,$param);
            }
        }
        catch (Exception $e)
        {
            $this->die('tasks file missing or damaged');
        }
        $this->log((count($this->tasks)-$before).' tasks read successfully');
    }

    public function execute()
    {
        $this->log('starting execution of '.count($this->tasks).' tasks');
        $total = count($this->tasks);
        while (!empty($this->tasks))
        {
            fwrite(STDERR,($total-count($this->tasks)).'/'.$total."  \r");
            list($cmd,$param) = array_shift($this->tasks);

            switch ($cmd)
            {
             case 'SLEEP':
                $this->sleep = intval($param);
                $this->log('delay set to '.$this->sleep.' ms');
                break;
             case 'SORT':
                $this->sort($param);
                break;
             default:
                $this->die('unknown command: '.$cmd);
                break;
            }
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
            return;
        }
        list($id,$wikitext) = $page;

        $page = new Page($word, $wikitext);
        $page->setFlags(Page::CHECK_FOR_BROKEN_LANGS|Page::CHECK_ORDER_OF_LANGS);
        $page->dissect();
        if (!$page->isValid())
        {
            $this->log('page is not valid. Skipping.');
            $this->skipped++;
            return;
        }

        $page->analyze();
        if (!empty($page->broken_langs))
        {
            $this->log('page contains broken languages. Skipping.');
            $this->skipped++;
            return;
        }
        if (!empty($page->unknown_langs))
        {
            $this->log('page contains unknown languages. Skipping.');
            $this->skipped++;
            return;
        }
        if (empty($page->unsorted_word))
        {
            $this->log('page seems to be already sorted. Skipping.');
            $this->skipped++;
            return;
        }

        if (isset($page->unsorted_word['greek'])
            || isset($page->unsorted_word['mixed']))
        {
            $this->log('page contains greek languages. Skipping due to policy.');
            $this->skipped++;
            return;
        }

        if (!isset($page->unsorted_word['non_greek']) || count($page->unsorted_word)!=1)
          $this->die('something odd happend: '.print_r($page->unsorted_word,true));

        $page->sortLanguages();
        $new_text = $page->join();

        if ($new_text==$wikitext)
        {
            $this->log('nothing changed after sorting. Skipping.');
            $this->skipped++;
            return;
        }

        $this->writePage($word, $id, $new_text, 'ταξινομήθηκε η σειρά των γλωσσών');

        $this->log('"'.$word.'" successfully changed');
        $this->success++;
        $this->sleep();
    }

    private function readPage($word,$add='')
    {
        $ch = curl_init(self::BASE_URL.str_replace('/','%2F',$word).$add);
        if ($ch===false)
          $this->die('cannot init curl');

        if (!curl_setopt($ch,CURLOPT_RETURNTRANSFER,true))
          $this->die('setup of curl failed');

        if (!curl_setopt($ch,CURLOPT_USERAGENT,$this->bot_name))
          $this->die('setup of curl failed');

        $result = curl_exec($ch);
        if ($result===false)
          $this->die('execution of curl failed');

        $json = json_decode($result,true);
        if ($json===null)
          $this->die('execution of curl failed: '.$result);

        $title = $json['title']??false;
        $id = $json['latest']['id']??false;
        $model = $json['content_model']??false;
        $page = $json['source']??false;

        if ($model!=='wikitext')
          $this->die('wrong content model: '.$model);
        if ($id===false || $page===false)
          $this->die('something odd happend: '.$result);

        if ($title!==$word) return false;

        return array($id, $page);
    }

    private function writePage($word, $id, $text, $comment)
    {
        $fields = json_encode(array('source' => $text,
                                    'comment' => $comment,
                                    'latest' => array('id' => $id)));

        $authorization = 'Authorization: Bearer '.$this->auth_token;

        $ch = curl_init();
        if ($ch===false)
          $this->die('cannot init curl');

        if (!curl_setopt($ch,CURLOPT_URL,self::BASE_URL.str_replace('/','%2F',$word)))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch,CURLOPT_CUSTOMREQUEST,'PUT'))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch,CURLOPT_POSTFIELDS,$fields))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-Type: application/json',$authorization)))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch,CURLOPT_USERAGENT,$this->bot_name))
          $this->die('setup of curl failed');
        if (!curl_setopt($ch,CURLOPT_RETURNTRANSFER, true))
          $this->die('setup of curl failed');

        $result = curl_exec($ch);
        if ($result===false)
          $this->die('execution of curl failed');

        $json = json_decode($result,true);
        if ($json===null)
          $this->die('execution of curl failed: '.$result);

        $new_title = $json['title']??false;
        if ($new_title!==$word)
          $this->die('something went wrong while writing: '.print_r($json,true));
    }

    //////////////////////////////////////////////////////////////////

    public function writeStartLog()
    {
        $h = $this->readPage($this->log_page,'?timestamp='.time());
        if ($h===false)
          $this->die('something odd happend');
        list($id, $text) = $h;

        $text .=  PHP_EOL.PHP_EOL.'== Log ('.date("r").') =='.PHP_EOL.PHP_EOL;

        $last = false;
        foreach ($this->tasks as list($cmd,$param))
        {
            if ($cmd==='SLEEP')
            {
                $text .= ' Set sleeping time between two writes to '.($param/1000).' seconds.'.PHP_EOL;
                continue;
            }
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
        $h = $this->readPage($this->log_page,'?timestamp='.time());
        if ($h===false)
          $this->die('something odd happend');
        list($id, $text) = $h;

        $text .= PHP_EOL; // mediawiki removes trailing whitespace
        $text .= ' =================================================================='.PHP_EOL;
        $text .= ' '.$this->success.' tasks processed successfully.'.PHP_EOL;
        $text .= ' '.$this->skipped.' tasks skipped.'.PHP_EOL;
        $text .= ' Finished at '.date("r").'.'.PHP_EOL.PHP_EOL;

        $this->writePage($this->log_page, $id, $text, 'ενημέρωση των αρχείων καταγραφής');
        $this->log('wrote finish log');
    }

    //////////////////////////////////////////////////////////////////

    private function sleep()
    {
        $this->log('sleeping for '.($this->sleep/1000).' seconds');
        usleep(1000*$this->sleep);
    }

    private function die($msg)
    {
        $this->log($msg);
        $this->log('bot stopped due to errors');
        die();
    }

    private function log($msg)
    {
        fwrite(STDOUT,$msg.PHP_EOL);
    }
}



?>
